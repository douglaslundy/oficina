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
        return DB::transaction(function () {
            $config = Configuracao::lockForUpdate()->first();
            if (!$config) throw new \Exception('Configurações da empresa não encontradas.');
            $numero = $config->proximo_numero_nf;
            $config->increment('proximo_numero_nf');
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

    // Quando $nota->modelo === 'NF-e', $nota precisa ter sido carregado com ->load('itens') antes de chamar este método.
    public function montarNotaData(
        NotaFiscal $nota,
        string $codigoServicoFederal = '14.01',
        string $codigoServicoMunicipal = '1401',
        string $codigoIbgeTomador = '',
    ): NotaFiscalData {
        $cliente = $nota->cliente;
        $aliquota = (float) ($nota->aliquota_iss ?? 5.0);

        $ehNfe = $nota->modelo === 'NF-e';

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
            modelo: $ehNfe ? 'NFE' : 'NFSE',
            itens: $ehNfe ? $nota->itens->map(fn ($item) => [
                'produto_id'      => $item->produto_id,
                'descricao'       => $item->descricao,
                'ncm'             => $item->ncm,
                'cfop'            => $item->cfop,
                'origem'          => $item->origem,
                'tributacao_icms' => $item->tributacao_icms,
                'cst_csosn'       => $item->cst_csosn,
                'quantidade'      => $item->quantidade,
                'valor_unitario'  => $item->valor_unitario,
            ])->all() : [],
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
            'mensagem_erro'      => $resultado->mensagemErro,
            'referencia_externa' => $resultado->referenciaExterna,
        ];
    }
}
