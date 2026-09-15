<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Providers;

use App\Services\Fiscal\Contracts\ConsultaNotaTerceiroProvider;
use App\Services\Fiscal\Contracts\FiscalProvider;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Data\RegistroResultado;
use Illuminate\Support\Facades\Http;

class FocusNfeProvider implements FiscalProvider, ConsultaNotaTerceiroProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $masterToken,
        private readonly string $ambiente,
        private readonly ?string $emissorToken = null,
    ) {}

    public function ambienteProducao(): bool
    {
        return $this->ambiente === 'PRODUCAO';
    }

    /**
     * Mesmo achado do SpedyProvider (2026-09-14, investigação do erro de
     * cancelamento): a Focus também pode devolver erros no formato
     * `{"erros":[{"mensagem":"..."}]}`, sem uma chave `mensagem` de nível
     * raiz — só 3 dos 8 pontos deste arquivo já tratavam isso. Centralizado
     * aqui pra nenhum outro escapar.
     */
    private function mensagemErroDe(\Illuminate\Http\Client\Response $resp, string $default): string
    {
        return $resp->json('mensagem') ?? $resp->json('erros.0.mensagem') ?? $default;
    }

    public function registrarEmissor(EmissorData $e): RegistroResultado
    {
        // Certificado é enviado junto no cadastro da empresa (ver enviarCertificado/registro combinado no service).
        $resp = Http::withBasicAuth($this->masterToken, '')
            ->post("{$this->baseUrl}/v2/empresas", $this->montarPayloadEmpresa($e));

        if ($resp->failed()) {
            return RegistroResultado::erro($this->mensagemErroDe($resp, 'Erro ao registrar empresa na Focus.'));
        }

        $id    = (string) ($resp->json('id') ?? $e->cnpjLimpo());
        $token = (string) ($this->ambienteProducao()
            ? ($resp->json('token_producao') ?? '')
            : ($resp->json('token_homologacao') ?? ''));

        return RegistroResultado::ok($id, $token);
    }

    public function enviarCertificado(EmissorData $e, string $pfxBinary, string $senha): void
    {
        // Focus aceita o certificado no cadastro da empresa (base64). Atualiza via PUT na empresa.
        $resp = Http::withBasicAuth($this->masterToken, '')
            ->put("{$this->baseUrl}/v2/empresas/{$e->cnpjLimpo()}", [
                'arquivo_certificado_base64' => base64_encode($pfxBinary),
                'senha_certificado'          => $senha,
            ]);

        if ($resp->failed()) {
            throw new \RuntimeException('Erro ao enviar certificado para a Focus: ' . $this->mensagemErroDe($resp, ''));
        }
    }

    public function emitir(NotaFiscalData $nota): EmissaoResultado
    {
        // Modo AUTOMATICO_PROVEDOR (a Spedy tem via /v1/orders) — a Focus
        // tem um equivalente ("Automations"), mas sem contrato de API
        // confirmado. Recusa aqui, sem HTTP nenhum. v1 = Spedy only.
        if ($nota->calculoTributarioModo === 'AUTOMATICO_PROVEDOR') {
            return EmissaoResultado::rejeitada(
                'Cálculo automático de tributação ainda não é suportado pela Focus — use o modo MANUAL nas configurações fiscais.',
                $nota->referenciaExterna,
            );
        }

        return match ($nota->modelo) {
            'NFE'  => $this->emitirNfe($nota),
            'NFCE' => $this->emitirNfce($nota),
            default => $this->emitirNfse($nota),
        };
    }

    private function emitirNfse(NotaFiscalData $nota): EmissaoResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->post("{$this->baseUrl}/v2/nfse?ref={$nota->referenciaExterna}", $this->montarPayloadNfse($nota));

        if ($resp->status() >= 400) {
            return EmissaoResultado::rejeitada(
                $this->mensagemErroDe($resp, 'Erro na emissão (Focus).'),
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoDe($resp->json(), $nota->referenciaExterna);
    }

    private function emitirNfe(NotaFiscalData $nota): EmissaoResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->post("{$this->baseUrl}/v2/nfe?ref={$nota->referenciaExterna}", $this->montarPayloadNfe($nota));

        if ($resp->status() >= 400) {
            return EmissaoResultado::rejeitada(
                $this->mensagemErroDe($resp, 'Erro na emissão de NF-e (Focus).'),
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoNfeDe($resp->json(), $nota->referenciaExterna);
    }

    private function emitirNfce(NotaFiscalData $nota): EmissaoResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->post("{$this->baseUrl}/v2/nfce?ref={$nota->referenciaExterna}", $this->montarPayloadNfce($nota));

        if ($resp->status() >= 400) {
            return EmissaoResultado::rejeitada(
                $this->mensagemErroDe($resp, 'Erro na emissão de NFC-e (Focus).'),
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoNfceDe($resp->json(), $nota->referenciaExterna);
    }

    /**
     * Bugs reais achados 2026-09-15 (auditoria campo-a-campo contra
     * doc.focusnfe.com.br/reference/emitir_nfce.md):
     * - `cnpj_emitente` está no `required` da raiz e nunca era mandado —
     *   bloqueava TODA emissão de NFC-e via Focus. Vem de
     *   `Configuracao.cnpj` (`NotaFiscalData::$cnpjEmitente`, ver
     *   NfeService::montarNotaData()).
     * - `valor_unitario_tributavel` está no `required` do item (par de
     *   `quantidade_tributavel`, que já era mandado) e nunca era mandado —
     *   mesmo corrigindo o item acima, a nota ainda seria rejeitada por
     *   item incompleto.
     */
    public function montarPayloadNfce(NotaFiscalData $n): array
    {
        $docTomador = preg_replace('/\D/', '', $n->tomador['cpf_cnpj']) ?? '';
        $chaveDoc   = strlen($docTomador) > 11 ? 'cnpj_destinatario' : 'cpf_destinatario';
        $valorTotal = round(array_sum(array_map(
            fn ($item) => (float) $item['quantidade'] * (float) $item['valor_unitario'],
            $n->itens
        )), 2);

        return [
            'cnpj_emitente'      => preg_replace('/\D/', '', $n->cnpjEmitente ?? ''),
            'natureza_operacao'  => $n->naturezaOperacao,
            'data_emissao'       => date('c'),
            'presenca_comprador' => 1, // presencial — único cenário coberto na v1
            'modalidade_frete'   => 9, // sem frete
            'local_destino'      => 1, // operação interna (mesmo estado); NFC-e interestadual fica pra quando surgir demanda real
            'indicador_inscricao_estadual_destinatario' => 9, // não contribuinte — sempre verdadeiro em NFC-e
            'nome_destinatario'  => $n->tomador['nome'],
            $chaveDoc            => $docTomador,
            'items' => array_map(fn (int $i, array $item) => [
                'numero_item'               => $i + 1,
                'codigo_produto'            => $item['sku'] ?? $item['produto_id'],
                'descricao'                 => $item['descricao'],
                'cfop'                      => $item['cfop'],
                'codigo_ncm'                => $item['ncm'],
                'unidade_comercial'         => $item['unidade'] ?? 'UN',
                'quantidade_comercial'      => (float) $item['quantidade'],
                'unidade_tributavel'        => $item['unidade'] ?? 'UN',
                'quantidade_tributavel'     => (float) $item['quantidade'],
                'valor_unitario_comercial'  => (float) $item['valor_unitario'],
                'valor_unitario_tributavel' => (float) $item['valor_unitario'],
                'valor_bruto'               => round((float) $item['quantidade'] * (float) $item['valor_unitario'], 2),
                'icms_origem'               => (int) $item['origem'],
                'icms_situacao_tributaria'  => $item['cst_csosn'],
            ], array_keys($n->itens), $n->itens),
            'formas_pagamento' => [[
                'forma_pagamento' => $this->codigoFormaPagamento($n->formaPagamento),
                'valor_pagamento' => $valorTotal,
            ]],
        ];
    }

    // Mapeamento pros códigos SEFAZ de forma de pagamento (tabela tPag). Forma de
    // pagamento não confirmada/livre cai em "99 - Outros" — campo informativo do
    // cupom, não afeta cálculo de ICMS/CFOP/CST, então um default aqui é seguro
    // (diferente de origem/tributacao_icms, que bloqueiam a emissão se ausentes).
    private function codigoFormaPagamento(string $formaPagamento): string
    {
        return match ($formaPagamento) {
            'Dinheiro'          => '01',
            'Cartão de Crédito' => '03',
            'Cartão de Débito'  => '04',
            'Boleto'            => '15',
            'PIX'               => '17',
            default             => '99',
        };
    }

    private function resultadoNfceDe(array $json, ?string $ref): EmissaoResultado
    {
        $status = $this->mapStatus((string) ($json['status'] ?? 'processando_autorizacao'));

        if ($status === 'REJEITADA') {
            return EmissaoResultado::rejeitada(
                $json['mensagem'] ?? ($json['erros'][0]['mensagem'] ?? 'Rejeitada pela SEFAZ.'),
                $ref,
            );
        }
        if ($status === 'PROCESSANDO') {
            return EmissaoResultado::processando($ref);
        }
        if ($status === 'CANCELADA') {
            return EmissaoResultado::cancelada($ref);
        }

        $xmlUrl      = $json['caminho_xml_nota_fiscal'] ?? null;
        $xmlConteudo = $xmlUrl ? $this->baixarXmlNfe($xmlUrl) : null;

        // Bug real achado 2026-09-15: `numero_protocolo` já é extraído
        // corretamente em resultadoNfeDe() (mesmo campo, mesma API) — aqui
        // ficava hardcoded null, perdendo o protocolo de toda NFC-e
        // autorizada via Focus.
        $protocoloBruto = $json['numero_protocolo'] ?? $json['protocolo'] ?? null;

        return EmissaoResultado::autorizada(
            chave: $json['chave_nfe'] ?? null,
            protocolo: $protocoloBruto !== null ? (string) $protocoloBruto : null,
            numero: isset($json['numero']) ? (string) $json['numero'] : null,
            xml: $xmlConteudo,
            pdfUrl: $json['caminho_danfe'] ?? null,
            ref: $ref,
            qrCodeUrl: $json['qrcode_url'] ?? null,
        );
    }

    public function consultar(string $referencia, string $modelo = 'NFSE'): EmissaoResultado
    {
        $recurso = match ($modelo) {
            'NFE'  => 'nfe',
            'NFCE' => 'nfce',
            default => 'nfse',
        };

        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->get("{$this->baseUrl}/v2/{$recurso}/{$referencia}");

        if ($resp->failed()) {
            // Falha ao CONSULTAR (rede, auth, 5xx) não é o mesmo que "rejeitada
            // pela SEFAZ/Prefeitura" — mesmo bug real já corrigido no
            // SpedyProvider (Rodada 40, PROGRESSO.md): confundir os dois já
            // corrompeu 10 NF-e reais lá. Mantém PROCESSANDO (retentável),
            // nunca um status fiscal substantivo por adivinhação.
            \Illuminate\Support\Facades\Log::warning(
                'Focus NFe: falha ao consultar status da nota — mantendo PROCESSANDO.',
                ['referencia' => $referencia, 'modelo' => $modelo, 'status_http' => $resp->status(), 'corpo' => $resp->body()],
            );
            return EmissaoResultado::processando($referencia);
        }

        return match ($modelo) {
            'NFE'  => $this->resultadoNfeDe($resp->json(), $referencia),
            'NFCE' => $this->resultadoNfceDe($resp->json(), $referencia),
            default => $this->resultadoDe($resp->json(), $referencia),
        };
    }

    public function cancelar(string $referencia, string $motivo, string $modelo = 'NFSE'): EmissaoResultado
    {
        // Antes hardcoded em /v2/nfse — cancelava NF-e/NFC-e no recurso errado
        // (nunca tinha caller real: NotaFiscalController::cancelar() só chamava
        // o provider pra NFS-e até esta sessão). Mesmo mapeamento de consultar().
        $recurso = match ($modelo) {
            'NFE'  => 'nfe',
            'NFCE' => 'nfce',
            default => 'nfse',
        };

        // doc.focusnfe.com.br/reference/cancelar_nfe.md: justificativa exige
        // 15-255 caracteres pra NF-e/NFC-e (o min:10 da validação do nosso
        // controller é compartilhado entre todos os modelos/provedores — uma
        // justificativa de 10-14 chars aqui volta como erro do provedor, não
        // erro nosso).
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->delete("{$this->baseUrl}/v2/{$recurso}/{$referencia}", [
                'justificativa' => $motivo,
            ]);

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada($this->mensagemErroDe($resp, 'Erro ao cancelar (Focus).'), $referencia);
        }

        return EmissaoResultado::cancelada($referencia);
    }

    public function montarPayloadEmpresa(EmissorData $e): array
    {
        return [
            'cnpj'                => $e->cnpjLimpo(),
            'nome'                => $e->razaoSocial,
            'nome_fantasia'       => $e->nomeFantasia ?? $e->razaoSocial,
            'inscricao_municipal' => $e->inscricaoMunicipal,
            'inscricao_estadual'  => $e->inscricaoEstadual,
            'regime_tributario'   => $this->mapRegime($e->regimeTributario),
            'email'               => $e->email,
            'telefone'            => $e->telefone,
            'logradouro'          => $e->logradouro,
            'numero'              => $e->numero,
            'complemento'         => $e->complemento,
            'bairro'              => $e->bairro,
            'cep'                 => preg_replace('/\D/', '', $e->cep),
            'municipio'           => $e->cidade,
            'uf'                  => $e->uf,
            // Bugs reais achados 2026-09-15 (auditoria contra
            // doc.focusnfe.com.br/reference/criar_empresa.md):
            // - `codigo_municipio` não existe no schema de REQUEST de
            //   criação de empresa (só na resposta) — a Focus recebe
            //   `municipio`+`uf` (nome), removido por ser dado morto.
            // - Faltavam `habilita_nfe`/`habilita_nfce` — só `habilita_nfse`
            //   era mandado, então mesmo corrigindo os payloads de emissão
            //   de NF-e/NFC-e, a conta na Focus nunca tinha permissão pra
            //   emitir esses modelos (bloqueio de permissão de conta, não
            //   de payload malformado).
            'habilita_nfse'       => true,
            'habilita_nfe'        => true,
            'habilita_nfce'       => true,
        ];
    }

    /**
     * Bugs reais achados 2026-09-15 (auditoria campo-a-campo contra
     * doc.focusnfe.com.br/reference/emitir_nfse.md, POST /v2/nfse) — este
     * era o caminho mais impactante (`NFSE` é o `default` de `emitir()`):
     * - Faltava o objeto `prestador` inteiro (`cnpj`+`inscricao_municipal`,
     *   ambos no `required` da raiz e presentes em todo exemplo real da
     *   doc) — SEM ele, TODA emissão de NFS-e via Focus deve estar sendo
     *   recusada.
     * - `servico.codigo_municipio` (código IBGE do MUNICÍPIO DE PRESTAÇÃO,
     *   `required`) nunca era mandado — o código só mandava
     *   `codigo_tributario_municipio` (campo diferente, opcional, tabela
     *   tributária do município). Regra geral de ISS (LC 116/2003, sem
     *   nenhuma exceção aplicável a serviço automotivo) é o município do
     *   ESTABELECIMENTO PRESTADOR — mesmo padrão já usado por
     *   `MotorNfse::cLocPrestacao` (NFePHP), não o do destinatário.
     * - `optante_simples_nacional` (boolean, `required` no schema; doc tem
     *   1 exemplo real que omite o campo, então a exigência pode variar por
     *   prefeitura — mandado de qualquer forma por ser confirmado no schema
     *   geral e não ter custo mandar mesmo onde é opcional).
     */
    public function montarPayloadNfse(NotaFiscalData $n): array
    {
        $docTomador = preg_replace('/\D/', '', $n->tomador['cpf_cnpj']) ?? '';
        $chaveDoc   = strlen($docTomador) > 11 ? 'cnpj' : 'cpf';

        return [
            'data_emissao'      => date('Y-m-d'),
            'natureza_operacao' => $n->naturezaOperacao,
            'optante_simples_nacional' => str_contains(strtolower($n->regimeTributario), 'simples'),
            'prestador' => [
                'cnpj'                => preg_replace('/\D/', '', $n->cnpjEmitente ?? ''),
                'inscricao_municipal' => $n->inscricaoMunicipalEmitente ?? '',
            ],
            'tomador'           => [
                $chaveDoc      => $docTomador,
                'razao_social' => $n->tomador['nome'],
                'email'        => $n->tomador['email'] ?? null,
                'endereco'     => [
                    'logradouro'       => $n->tomador['logradouro'] ?? '',
                    'numero'           => $n->tomador['numero'] ?? 'S/N',
                    'bairro'           => $n->tomador['bairro'] ?? '',
                    'cep'              => preg_replace('/\D/', '', $n->tomador['cep'] ?? ''),
                    'codigo_municipio' => $n->tomador['codigo_ibge'] ?? '',
                    'uf'               => $n->tomador['uf'] ?? '',
                ],
            ],
            'servico' => [
                'discriminacao'               => $n->descricao,
                'item_lista_servico'          => $n->codigoServicoFederal,
                'codigo_tributario_municipio' => $n->codigoServicoMunicipal,
                'codigo_municipio'            => $n->codigoIbgeEmitente ?? '',
                'aliquota'                    => $n->aliquotaIss,
                'iss_retido'                  => $n->issRetido,
                'valor_servicos'              => $n->valorServicos,
            ],
        ];
    }

    public function montarPayloadNfe(NotaFiscalData $n): array
    {
        $docTomador = preg_replace('/\D/', '', $n->tomador['cpf_cnpj']) ?? '';
        $chaveDoc   = strlen($docTomador) > 11 ? 'cnpj_destinatario' : 'cpf_destinatario';

        return [
            'natureza_operacao'  => $n->naturezaOperacao,
            'data_emissao'       => date('Y-m-d'),
            'tipo_documento'     => 1, // saída
            'finalidade_emissao' => 1, // normal
            'nome_destinatario'  => $n->tomador['nome'],
            $chaveDoc            => $docTomador,
            'logradouro_destinatario'   => $n->tomador['logradouro'] ?? '',
            'numero_destinatario'       => $n->tomador['numero'] ?? 'S/N',
            'bairro_destinatario'       => $n->tomador['bairro'] ?? '',
            'municipio_destinatario'    => $n->tomador['cidade'] ?? '',
            'uf_destinatario'           => $n->tomador['uf'] ?? '',
            'cep_destinatario'          => preg_replace('/\D/', '', $n->tomador['cep'] ?? ''),
            'items' => array_map(fn (int $i, array $item) => [
                'numero_item'               => $i + 1,
                'codigo_produto'            => $item['sku'] ?? $item['produto_id'],
                'descricao'                 => $item['descricao'],
                'cfop'                      => $item['cfop'],
                'codigo_ncm'                => $item['ncm'],
                'unidade_comercial'         => $item['unidade'] ?? 'UN',
                'quantidade_comercial'      => (float) $item['quantidade'],
                'valor_unitario_comercial'  => (float) $item['valor_unitario'],
                'valor_bruto'               => round((float) $item['quantidade'] * (float) $item['valor_unitario'], 2),
                'icms_origem'               => (int) $item['origem'],
                'icms_situacao_tributaria'  => $item['cst_csosn'],
            ], array_keys($n->itens), $n->itens),
        ];
    }

    public function mapStatus(string $focusStatus): string
    {
        return match ($focusStatus) {
            'autorizado'              => 'AUTORIZADA',
            'cancelado'               => 'CANCELADA',
            'erro_autorizacao',
            'denegado'                => 'REJEITADA',
            'processando_autorizacao' => 'PROCESSANDO',
            default                   => $this->statusDesconhecido($focusStatus),
        };
    }

    private function statusDesconhecido(string $status): string
    {
        \Illuminate\Support\Facades\Log::warning(
            'Focus NFe: status desconhecido recebido, tratando como PROCESSANDO.',
            ['status' => $status],
        );
        return 'PROCESSANDO';
    }

    private function mapRegime(string $regime): string
    {
        $r = strtolower($regime);
        // Focus: 1=Simples Nacional, 2=SN excesso sublimite, 3=Regime Normal
        return match (true) {
            str_contains($r, 'simples') => '1',
            default                     => '3',
        };
    }

    private function resultadoDe(array $json, ?string $ref): EmissaoResultado
    {
        $status = $this->mapStatus((string) ($json['status'] ?? 'processando_autorizacao'));

        if ($status === 'REJEITADA') {
            return EmissaoResultado::rejeitada(
                $json['mensagem'] ?? ($json['erros'][0]['mensagem'] ?? 'Rejeitada pela Prefeitura.'),
                $ref,
            );
        }
        if ($status === 'PROCESSANDO') {
            return EmissaoResultado::processando($ref);
        }
        if ($status === 'CANCELADA') {
            return EmissaoResultado::cancelada($ref);
        }

        $xmlUrl      = $json['caminho_xml_nota_fiscal'] ?? null;
        $xmlConteudo = $xmlUrl ? $this->baixarXmlNfe($xmlUrl) : null;

        return EmissaoResultado::autorizada(
            chave: $json['codigo_verificacao'] ?? ($json['chave_nfe'] ?? null),
            // 2026-08-03: não reusa "numero" como protocolo (defeito #4) — a doc
            // de NFS-e da Focus não confirma um campo de protocolo distinto de
            // "numero" (ao contrário da NF-e, que expõe "numero_protocolo"). Sem
            // confirmação, documentamos como limitação do provedor em vez de
            // inventar um valor.
            protocolo: null,
            numero: isset($json['numero']) ? (string) $json['numero'] : null,
            xml: $xmlConteudo,
            pdfUrl: $json['url'] ?? ($json['caminho_danfse'] ?? null),
            ref: $ref,
        );
    }

    private function resultadoNfeDe(array $json, ?string $ref): EmissaoResultado
    {
        $status = $this->mapStatus((string) ($json['status'] ?? 'processando_autorizacao'));

        if ($status === 'REJEITADA') {
            return EmissaoResultado::rejeitada(
                $json['mensagem'] ?? ($json['erros'][0]['mensagem'] ?? 'Rejeitada pela SEFAZ.'),
                $ref,
            );
        }
        if ($status === 'PROCESSANDO') {
            return EmissaoResultado::processando($ref);
        }
        if ($status === 'CANCELADA') {
            return EmissaoResultado::cancelada($ref);
        }

        $xmlUrl = $json['caminho_xml_nota_fiscal'] ?? null;
        $xmlConteudo = $xmlUrl ? $this->baixarXmlNfe($xmlUrl) : null;

        // Campo real da Focus para o protocolo é "numero_protocolo" (confirmado na
        // doc de consulta: "numero_protocolo": "151260029467289"), não "protocolo".
        // NÃO reusa "numero" (defeito #4).
        $protocoloBruto = $json['numero_protocolo'] ?? $json['protocolo'] ?? null;

        return EmissaoResultado::autorizada(
            chave: $json['chave_nfe'] ?? null,
            protocolo: $protocoloBruto !== null ? (string) $protocoloBruto : null,
            numero: isset($json['numero']) ? (string) $json['numero'] : null,
            xml: $xmlConteudo,
            pdfUrl: $json['caminho_danfe'] ?? null,
            ref: $ref,
        );
    }

    /**
     * Baixa o conteúdo real do XML da NF-e (defeito #1). Isola falhas: um erro HTTP
     * (status não-2xx) ou uma exceção de conexão (timeout/DNS) no download do XML não
     * pode derrubar o resultado — a NF-e já foi autorizada pela SEFAZ nesse ponto, então
     * degradamos para xml: null (com log) em vez de propagar a exceção.
     */
    private function baixarXmlNfe(string $xmlUrl): ?string
    {
        // Focus retorna caminho_xml_nota_fiscal como PATH RELATIVO na maioria dos
        // casos reais (ex.: "/arquivos/12345678000123/201906/XMLs/...-nfe.xml"),
        // não uma URL absoluta — Http::get() num path relativo lança exceção (sem
        // host na URI). A doc da Focus não é totalmente consistente nisso, então
        // aceitamos as duas formas: se não vier com esquema http(s), prefixamos
        // com o baseUrl do provider.
        if (!str_starts_with($xmlUrl, 'http://') && !str_starts_with($xmlUrl, 'https://')) {
            $xmlUrl = $this->baseUrl . '/' . ltrim($xmlUrl, '/');
        }

        try {
            $resp = Http::get($xmlUrl);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'Focus NFe: falha ao baixar XML da NF-e (exceção na requisição).',
                ['url' => $xmlUrl, 'erro' => $e->getMessage()],
            );
            return null;
        }

        if (! $resp->successful()) {
            \Illuminate\Support\Facades\Log::warning(
                'Focus NFe: falha ao baixar XML da NF-e (status HTTP não-sucesso).',
                ['url' => $xmlUrl, 'status' => $resp->status()],
            );
            return null;
        }

        return $resp->body() ?: null;
    }

    public function consultarNotaRecebida(string $chaveAcesso): ConsultaNotaTerceiroResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->get("{$this->baseUrl}/v2/nfes_recebidas/{$chaveAcesso}.json", ['completa' => 1]);

        if ($resp->status() === 404) {
            return ConsultaNotaTerceiroResultado::naoEncontrada();
        }

        if ($resp->failed()) {
            \Illuminate\Support\Facades\Log::warning(
                'Focus NFe: falha ao consultar nota recebida.',
                ['chave_acesso' => $chaveAcesso, 'status' => $resp->status(), 'corpo' => $resp->body()],
            );
            return ConsultaNotaTerceiroResultado::erro($this->mensagemErroDe($resp, 'Erro ao consultar nota na Focus.'));
        }

        $json = $resp->json();

        // Bug real achado 2026-09-15 (auditoria contra
        // doc.focusnfe.com.br/reference/consultar_nfe_recebida_individual_json):
        // o campo `manifestacao_destinatario` está SEMPRE presente na
        // resposta e, quando ainda não há manifestação, vale a STRING
        // LITERAL "nulo" — nunca `null`/`""`. `empty('nulo')` é `false` em
        // PHP, então este branch nunca executava com dados reais: a ciência
        // da operação nunca era auto-registrada.
        $manifestacao = $json['manifestacao_destinatario'] ?? null;
        if ($manifestacao === null || $manifestacao === '' || $manifestacao === 'nulo') {
            $manifestoResp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
                ->post("{$this->baseUrl}/v2/nfes_recebidas/{$chaveAcesso}/manifesto", ['tipo' => 'ciencia']);

            if ($manifestoResp->failed()) {
                \Illuminate\Support\Facades\Log::warning(
                    'Focus NFe: falha ao registrar ciência da operação (manifesto).',
                    ['chave_acesso' => $chaveAcesso, 'status' => $manifestoResp->status(), 'corpo' => $manifestoResp->body()],
                );
                return ConsultaNotaTerceiroResultado::erro('Falha ao registrar ciência da operação na Focus.');
            }

            return ConsultaNotaTerceiroResultado::aguardandoManifestacao();
        }

        return ConsultaNotaTerceiroResultado::completa(FocusNfeRecebidaMapper::paraArray($json));
    }

    public function listarNotasRecebidas(string $cnpjOficina, ?\DateTimeInterface $desde = null): array
    {
        $cnpjLimpo = preg_replace('/\D/', '', $cnpjOficina) ?? '';

        // A Focus não filtra por data — só por "versao" (paginação
        // incremental). $desde fica sem uso aqui por ora; mantido na
        // assinatura pra simetria com a interface e com o SpedyProvider.
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->get("{$this->baseUrl}/v2/nfes_recebidas", ['cnpj' => $cnpjLimpo]);

        if ($resp->failed()) {
            $mensagem = $this->mensagemErroDe($resp, 'Erro ao listar notas recebidas na Focus.');
            \Illuminate\Support\Facades\Log::warning(
                'Focus NFe: falha ao listar notas recebidas.',
                ['cnpj' => $cnpjLimpo, 'status' => $resp->status(), 'corpo' => $resp->body()],
            );
            throw new \RuntimeException($mensagem);
        }

        return array_map(fn (array $item) => new ConsultaNotaTerceiroResumo(
            chaveAcesso: (string) ($item['chave_nfe'] ?? ''),
            fornecedorNome: $item['nome_emitente'] ?? null,
            fornecedorCnpj: $item['documento_emitente'] ?? null,
            dataEmissao: isset($item['data_emissao']) ? substr((string) $item['data_emissao'], 0, 10) : null,
            valorTotal: (float) ($item['valor_total'] ?? 0),
            completa: ($item['nfe_completa'] ?? false) === true,
        ), $resp->json() ?? []);
    }
}
