<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Providers;

use App\Services\Fiscal\Contracts\FiscalProvider;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Data\RegistroResultado;
use Illuminate\Support\Facades\Http;

class FocusNfeProvider implements FiscalProvider
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

    public function registrarEmissor(EmissorData $e): RegistroResultado
    {
        // Certificado é enviado junto no cadastro da empresa (ver enviarCertificado/registro combinado no service).
        $resp = Http::withBasicAuth($this->masterToken, '')
            ->post("{$this->baseUrl}/v2/empresas", $this->montarPayloadEmpresa($e));

        if ($resp->failed()) {
            return RegistroResultado::erro($resp->json('mensagem') ?? 'Erro ao registrar empresa na Focus.');
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
            throw new \RuntimeException('Erro ao enviar certificado para a Focus: ' . ($resp->json('mensagem') ?? ''));
        }
    }

    public function emitir(NotaFiscalData $nota): EmissaoResultado
    {
        return $nota->modelo === 'NFE' ? $this->emitirNfe($nota) : $this->emitirNfse($nota);
    }

    private function emitirNfse(NotaFiscalData $nota): EmissaoResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->post("{$this->baseUrl}/v2/nfse?ref={$nota->referenciaExterna}", $this->montarPayloadNfse($nota));

        if ($resp->status() >= 400) {
            return EmissaoResultado::rejeitada(
                $resp->json('mensagem') ?? ($resp->json('erros.0.mensagem') ?? 'Erro na emissão (Focus).'),
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoDe($resp->json(), $nota->referenciaExterna);
    }

    private function emitirNfe(NotaFiscalData $nota): EmissaoResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->post("{$this->baseUrl}/nfe?ref={$nota->referenciaExterna}", $this->montarPayloadNfe($nota));

        if ($resp->status() >= 400) {
            return EmissaoResultado::rejeitada(
                $resp->json('mensagem') ?? ($resp->json('erros.0.mensagem') ?? 'Erro na emissão de NF-e (Focus).'),
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoNfeDe($resp->json(), $nota->referenciaExterna);
    }

    public function consultar(string $referencia): EmissaoResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->get("{$this->baseUrl}/v2/nfse/{$referencia}");

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada($resp->json('mensagem') ?? 'Erro ao consultar (Focus).', $referencia);
        }

        return $this->resultadoDe($resp->json(), $referencia);
    }

    public function cancelar(string $referencia, string $motivo): EmissaoResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->delete("{$this->baseUrl}/v2/nfse/{$referencia}", [
                'justificativa' => $motivo,
            ]);

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada($resp->json('mensagem') ?? 'Erro ao cancelar (Focus).', $referencia);
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
            'codigo_municipio'    => $e->codigoIbge,
            'habilita_nfse'       => true,
        ];
    }

    public function montarPayloadNfse(NotaFiscalData $n): array
    {
        $docTomador = preg_replace('/\D/', '', $n->tomador['cpf_cnpj']) ?? '';
        $chaveDoc   = strlen($docTomador) > 11 ? 'cnpj' : 'cpf';

        return [
            'data_emissao'      => date('Y-m-d'),
            'natureza_operacao' => $n->naturezaOperacao,
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
                'codigo_produto'            => $item['produto_id'],
                'descricao'                 => $item['descricao'],
                'cfop'                      => $item['cfop'],
                'codigo_ncm'                => $item['ncm'],
                'unidade_comercial'         => 'UN',
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

        return EmissaoResultado::autorizada(
            chave: $json['codigo_verificacao'] ?? ($json['chave_nfe'] ?? null),
            protocolo: isset($json['numero']) ? (string) $json['numero'] : null,
            numero: isset($json['numero']) ? (string) $json['numero'] : null,
            xml: $json['caminho_xml_nota_fiscal'] ?? null,
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

        return EmissaoResultado::autorizada(
            chave: $json['chave_nfe'] ?? null,
            protocolo: isset($json['protocolo']) ? (string) $json['protocolo'] : null, // NÃO reusa "numero" (defeito #4)
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
}
