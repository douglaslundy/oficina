<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\FiscalProviderManager;
use App\Services\Fiscal\IndicadorIeDestinatarioResolver;
use Illuminate\Support\Facades\DB;

class NfeService
{
    public function proximoNumeroNf(): int
    {
        return $this->proximoNumeroPorContador('proximo_numero_nf');
    }

    public function proximoNumeroNfce(): int
    {
        return $this->proximoNumeroPorContador('proximo_numero_nfce');
    }

    /**
     * Contador PRÓPRIO da NFC-e via NFePHP (MotorNfce) — nunca compartilhado
     * com `proximo_numero_nfce` (Spedy/Focus). Mesmo raciocínio já aplicado
     * à NF-e via NFePHP (`proximo_numero_nfe`, separado de
     * `proximo_numero_nf`).
     */
    public function proximoNumeroNfceNfephp(): int
    {
        return $this->proximoNumeroPorContador('proximo_numero_nfce_nfephp');
    }

    private function proximoNumeroPorContador(string $coluna): int
    {
        return DB::transaction(function () use ($coluna) {
            $config = Configuracao::lockForUpdate()->first();
            if (!$config) throw new \Exception('Configurações da empresa não encontradas.');
            $numero = $config->{$coluna};
            $config->increment($coluna);
            return $numero;
        });
    }

    /**
     * Numeração própria da DPS (motor NFePHP/NFS-e nacional) — contador
     * separado de proximo_numero_nf, que pertence à numeração de NFS-e do
     * Spedy/Focus. Ver migration 2026_08_04_000001.
     */
    public function proximoNumeroDps(): int
    {
        return DB::transaction(function () {
            $config = Configuracao::lockForUpdate()->first();
            if (!$config) throw new \Exception('Configurações da empresa não encontradas.');
            $numero = $config->proximo_numero_dps;
            $config->increment('proximo_numero_dps');
            return $numero;
        });
    }

    /**
     * Numeração própria da NF-e via NFePHP/sped-nfe — contador separado de
     * proximo_numero_nf (Spedy/Focus) e proximo_numero_dps (NFS-e nacional).
     * Ver migration 2026_08_10_000003.
     */
    public function proximoNumeroNfe(): int
    {
        return DB::transaction(function () {
            $config = Configuracao::lockForUpdate()->first();
            if (!$config) throw new \Exception('Configurações da empresa não encontradas.');
            $numero = $config->proximo_numero_nfe;
            $config->increment('proximo_numero_nfe');
            return $numero;
        });
    }

    // Quando $nota->modelo === 'NF-e'|'NFC-e', $nota precisa ter sido carregado com ->load('itens') antes de chamar este método.
    public function montarNotaData(
        NotaFiscal $nota,
        string $codigoServicoFederal = '14.01',
        string $codigoServicoMunicipal = '1401',
        string $codigoIbgeTomador = '',
        ?Configuracao $config = null,
    ): NotaFiscalData {
        $cliente = $nota->cliente;
        $aliquota = (float) ($nota->aliquota_iss ?? 5.0);

        $modeloInterno = match ($nota->modelo) {
            'NF-e'  => 'NFE',
            'NFC-e' => 'NFCE',
            default => 'NFSE',
        };
        $temItens = in_array($modeloInterno, ['NFE', 'NFCE'], true);

        // Finding 4 do fix wave pós-revisão da Etapa C2 (2026-08-11): se
        // esta NotaFiscal já tem um `numero` persistido (uma tentativa
        // anterior via NFePHP alocou e o controller salvou, mesmo que
        // rejeitada — ver NotaFiscalController::emitir()), essa é uma
        // retentativa, não uma primeira emissão. MotorNfe::emitir() reusa
        // esse número em vez de queimar um novo (spec Seção B). Restrito a
        // NFEPHP porque Spedy/Focus atribuem o número deles mesmos — um
        // `$nota->numero` vindo desses provedores não significa "reservado
        // pra reenviar", significa "já emitido por eles".
        $numeroJaReservado = (in_array($modeloInterno, ['NFE', 'NFCE'], true) && $nota->provedor === 'NFEPHP' && $nota->numero !== null)
            ? (string) $nota->numero
            : null;

        // indIEDest só existe de verdade pra NF-e — NFC-e já trata todo
        // destinatário como não contribuinte (regra de domínio, não algo
        // pra resolver aqui) e NFS-e não tem esse campo. Resolver aqui, uma
        // vez, em vez de cada provider/motor decidir (ou não decidir, que
        // foi o bug real: cStat=232 na NF-e #13 — ver
        // IndicadorIeDestinatarioResolver).
        $indicadorIe = null;
        $ieDestinatario = null;
        if ($modeloInterno === 'NFE') {
            $resolvido = IndicadorIeDestinatarioResolver::resolver(
                $cliente?->cpf_cnpj ?? '',
                $cliente?->inscricao_estadual,
                (bool) ($cliente?->ie_isento ?? false),
                $cliente?->nome ?? 'cliente',
            );
            $indicadorIe    = $resolvido['indicador'];
            $ieDestinatario = $resolvido['inscricao_estadual'];
        }

        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: [
                'nome'        => $cliente?->nome ?? '-',
                'cpf_cnpj'    => $cliente?->cpf_cnpj ?? '',
                'email'       => $cliente?->email,
                'cep'         => $cliente?->cep,
                'logradouro'  => $cliente?->endereco,
                'numero'      => 'S/N',
                'bairro'      => $cliente?->bairro,
                'cidade'      => $cliente?->cidade,
                'uf'          => $cliente?->uf,
                'indicador_ie'        => $indicadorIe,
                'inscricao_estadual'  => $ieDestinatario,
                // Gap de dados cross-provider (achado 2026-09-14, ver
                // TAREFAS.md): antes disto, $codigoIbgeTomador SEMPRE vinha
                // de $config->codigo_ibge (a própria oficina) — qualquer
                // cliente de outro município saía com cMun/codigo_municipio
                // errado no documento fiscal, divergente de UF/cidade (que já
                // usavam o dado real do cliente). `clientes.codigo_ibge` é
                // preenchido pelo ViaCEP no cadastro; usa o do cliente quando
                // existe, cai pro parâmetro (oficina) só pra clientes antigos
                // sem esse dado ainda.
                'codigo_ibge' => $cliente?->codigo_ibge ?: $codigoIbgeTomador,
            ],
            descricao: $nota->observacoes ?? 'Serviços automotivos',
            valorServicos: (float) $nota->valor_total,
            aliquotaIss: $aliquota,
            issRetido: false,
            codigoServicoFederal: $codigoServicoFederal,
            codigoServicoMunicipal: $codigoServicoMunicipal,
            naturezaOperacao: $nota->natureza_operacao ?? 'Prestação de Serviços',
            referenciaExterna: $nota->referencia_externa ?? ('nf-' . $nota->id),
            modelo: $modeloInterno,
            itens: $temItens ? $nota->itens->map(fn ($item) => [
                'produto_id'      => $item->produto_id,
                // codigo_produto do provedor: SKU real do produto; cai pro UUID
                // só quando a nota é anterior a este snapshot (coluna nula).
                'sku'             => $item->sku ?: $item->produto_id,
                'descricao'       => $item->descricao,
                // uCom/unidade_comercial: unidade real do produto (Par, Cx, L…),
                // não mais 'UN' fixo. Normalizada em caixa alta.
                'unidade'         => strtoupper((string) ($item->unidade ?: 'UN')),
                'ncm'             => $item->ncm,
                'cfop'            => $item->cfop,
                'origem'          => $item->origem,
                // CEST: obrigatório pela SEFAZ quando tributacao_icms é ST
                // (rejeição real "806: operação com ICMS-ST sem CEST",
                // homologação 2026-09-10) — já existe em produtos.cest,
                // só não era lido aqui. notas_fiscais_itens não tem coluna
                // própria; lê direto do produto vinculado (lazy-load OK,
                // poucos itens por nota).
                'cest'            => $item->produto?->cest,
                // Bug real de produção (2026-09-14, "cStat=883: GTIN (cEAN)
                // sem informação"): desde 12/09/2022 a SEFAZ exige <cEAN>
                // preenchido em TODO item — com o GTIN de verdade quando o
                // produto tem código de barras, ou o literal "SEM GTIN"
                // quando não tem (nunca vazio). Este campo nunca era lido
                // aqui nem mandado por MotorNfe/MotorNfce::tagprod() — toda
                // emissão de produto SEM código de barras cadastrado batia
                // essa rejeição. Mesmo padrão de `cest` acima: lê direto do
                // produto vinculado.
                'codigo_barras'   => $item->produto?->codigo_barras,
                'tributacao_icms' => $item->tributacao_icms,
                'cst_csosn'       => $item->cst_csosn,
                'quantidade'      => $item->quantidade,
                'valor_unitario'  => $item->valor_unitario,
            ])->all() : [],
            formaPagamento: $nota->forma_pagamento ?? '',
            numeroReservado: $numeroJaReservado,
            regimeTributario: $config?->regime_tributario ?? '',
            calculoTributarioModo: $config?->calculo_tributario_modo ?? 'MANUAL',
            // $nota->numero já foi alocado por IniciarEmissaoNotaService
            // (proximoNumeroNf()/proximoNumeroNfce()) antes de chegar aqui,
            // pra qualquer provedor — ver comentário em NotaFiscalData.
            numeroAlocado: $temItens && $nota->numero !== null ? (string) $nota->numero : null,
            serieNf: $config?->serie_nf,
            cnpjEmitente: $config?->cnpj,
            inscricaoMunicipalEmitente: $config?->inscricao_municipal,
            codigoIbgeEmitente: $config?->codigo_ibge,
        );
    }

    public function emitir(NotaFiscal $nota): array
    {
        $config = Configuracao::first();
        if (!$config) {
            throw new \RuntimeException('Configurações fiscais da empresa não encontradas. Preencha os dados da empresa antes de emitir.');
        }
        $manager  = app(FiscalProviderManager::class);
        $provider = $manager->forTenant();

        $data     = $this->montarNotaData(
            $nota,
            codigoServicoFederal: '14.01',
            codigoServicoMunicipal: '1401',
            codigoIbgeTomador: $config?->codigo_ibge ?? '',
            config: $config,
        );

        $resultado = $provider->emitir($data);

        return [
            'status'             => $resultado->status,
            'chave'              => $resultado->chave ?? '',
            'protocolo'          => $resultado->protocolo ?? '',
            'numero'             => $resultado->numero,
            'xml_retorno'        => $resultado->xml ?? '',
            'pdf_url'            => $resultado->pdfUrl,
            'qrcode_url'         => $resultado->qrCodeUrl,
            'mensagem_erro'      => $resultado->mensagemErro,
            'referencia_externa' => $resultado->referenciaExterna,
        ];
    }

    public function consultarStatus(NotaFiscal $nota): array
    {
        $manager  = app(FiscalProviderManager::class);
        $provider = $manager->forTenant();

        $modeloInterno = match ($nota->modelo) {
            'NF-e'  => 'NFE',
            'NFC-e' => 'NFCE',
            default => 'NFSE',
        };

        // Bug real de produção (2026-09-14): NFEPHP fala DIRETO com a SEFAZ/
        // ADN usando a chave de acesso real (44 dígitos) — não a nossa
        // referência interna (`nf-<uuid>`), que só faz sentido pra Spedy/
        // Focus (reconciliação por `integrationId`, ver SpedyProvider).
        // Mandar a referência interna pro MotorNfe/MotorNfse dava "Consulta
        // chave: chave "nf-<uuid>" invalida!" — confirmado ao vivo, era a
        // causa real de uma NF-e mostrando CONTINGÊNCIA com mensagem de
        // erro de consulta, mesmo já tendo entrado em contingência
        // corretamente por fora. Sem chave ainda (nota genuinamente sem
        // resposta síncrona ainda), não há o que consultar — mantém
        // PROCESSANDO sem tentar, em vez de mandar um valor que a SEFAZ vai
        // recusar de qualquer forma.
        if ($nota->provedor === 'NFEPHP') {
            $resultado = empty($nota->chave_acesso)
                ? \App\Services\Fiscal\Data\EmissaoResultado::processando($nota->referencia_externa)
                : $provider->consultar($nota->chave_acesso, $modeloInterno);
        } else {
            $resultado = $provider->consultar($nota->referencia_externa ?? ('nf-' . $nota->id), $modeloInterno);
        }

        return [
            'status'             => $resultado->status,
            'chave'              => $resultado->chave ?? '',
            'protocolo'          => $resultado->protocolo ?? '',
            'numero'             => $resultado->numero,
            'xml_retorno'        => $resultado->xml ?? '',
            'pdf_url'            => $resultado->pdfUrl,
            'qrcode_url'         => $resultado->qrCodeUrl,
            'mensagem_erro'      => $resultado->mensagemErro,
            'referencia_externa' => $resultado->referenciaExterna,
        ];
    }
}
