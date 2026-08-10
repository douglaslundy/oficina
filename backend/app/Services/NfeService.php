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
                'descricao'       => $item->descricao,
                'ncm'             => $item->ncm,
                'cfop'            => $item->cfop,
                'origem'          => $item->origem,
                'tributacao_icms' => $item->tributacao_icms,
                'cst_csosn'       => $item->cst_csosn,
                'quantidade'      => $item->quantidade,
                'valor_unitario'  => $item->valor_unitario,
            ])->all() : [],
            formaPagamento: $nota->forma_pagamento ?? '',
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
