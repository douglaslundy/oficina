<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\FiscalProviderManager;
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
        $numeroJaReservado = ($modeloInterno === 'NFE' && $nota->provedor === 'NFEPHP' && $nota->numero !== null)
            ? (string) $nota->numero
            : null;

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
                'codigo_ibge' => $codigoIbgeTomador,
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
                'tributacao_icms' => $item->tributacao_icms,
                'cst_csosn'       => $item->cst_csosn,
                'quantidade'      => $item->quantidade,
                'valor_unitario'  => $item->valor_unitario,
            ])->all() : [],
            formaPagamento: $nota->forma_pagamento ?? '',
            numeroReservado: $numeroJaReservado,
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

        $resultado = $provider->consultar($nota->referencia_externa ?? ('nf-' . $nota->id), $modeloInterno);

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
