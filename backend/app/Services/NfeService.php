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

    public function montarNotaData(
        NotaFiscal $nota,
        string $codigoServicoFederal = '14.01',
        string $codigoServicoMunicipal = '1401',
        string $codigoIbgeTomador = '',
    ): NotaFiscalData {
        $cliente = $nota->cliente;
        $aliquota = (float) ($nota->aliquota_iss ?? 5.0);

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
