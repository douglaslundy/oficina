<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Models\Cliente;
use App\Models\NotaFiscal;
use App\Services\NfeService;
use PHPUnit\Framework\TestCase;

class NfeServiceMontagemTest extends TestCase
{
    public function test_monta_nota_data_a_partir_da_nota(): void
    {
        $cliente = new Cliente([
            'nome' => 'Fulano', 'cpf_cnpj' => '12345678000199',
            'email' => 'f@x.com', 'cep' => '01310100', 'endereco' => 'Av A',
            'bairro' => 'Centro', 'cidade' => 'São Paulo', 'uf' => 'SP',
        ]);
        $nota = new NotaFiscal([
            'valor_total' => 150.0, 'aliquota_iss' => 5.0,
            'natureza_operacao' => 'Prestação de Serviços',
            'observacoes' => 'Troca de óleo', 'referencia_externa' => 'nf-abc',
        ]);
        $nota->setRelation('cliente', $cliente);

        $service = new NfeService();
        $data = $service->montarNotaData($nota, codigoServicoFederal: '14.01', codigoServicoMunicipal: '1401', codigoIbgeTomador: '3550308');

        $this->assertSame('NFSE', $data->tipo);
        $this->assertSame(150.0, $data->valorServicos);
        $this->assertSame(5.0, $data->aliquotaIss);
        $this->assertSame('nf-abc', $data->referenciaExterna);
        $this->assertSame('12345678000199', $data->tomador['cpf_cnpj']);
    }

    public function test_monta_nota_data_nfce_usa_modelo_interno_nfce_e_inclui_itens(): void
    {
        $cliente = new Cliente(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800']);
        $nota = new NotaFiscal([
            'modelo' => 'NFC-e', 'valor_total' => 90.0,
            'natureza_operacao' => 'Venda de Mercadoria',
            'forma_pagamento' => 'PIX', 'referencia_externa' => 'nfce-abc',
        ]);
        $nota->setRelation('cliente', $cliente);
        $item = new \App\Models\NotaFiscalItem([
            'descricao' => 'Filtro', 'ncm' => '84212300', 'cfop' => '5102',
            'origem' => 0, 'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102',
            'quantidade' => 2, 'valor_unitario' => 45,
        ]);
        $nota->setRelation('itens', collect([$item]));

        $service = new NfeService();
        $data = $service->montarNotaData($nota);

        $this->assertSame('NFCE', $data->modelo);
        $this->assertCount(1, $data->itens);
        $this->assertSame('PIX', $data->formaPagamento);
    }
}
