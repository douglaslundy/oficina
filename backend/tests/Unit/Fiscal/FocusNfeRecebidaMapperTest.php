<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Providers\FocusNfeRecebidaMapper;
use PHPUnit\Framework\TestCase;

class FocusNfeRecebidaMapperTest extends TestCase
{
    private function jsonBase(array $itemOverrides = []): array
    {
        return [
            'nome_emitente' => 'Fornecedor Teste',
            'documento_emitente' => '12345678000199',
            'chave_nfe' => '35260712345678000199550010000012340000000001',
            'valor_total' => '155.00',
            'data_emissao' => '2026-09-01T10:00:00-03:00',
            'requisicao_nota_fiscal' => [
                'itens' => [array_merge([
                    'codigo_produto' => '88888',
                    'codigo_barras_comercial' => '7891234567890',
                    'descricao' => 'Filtro de oleo',
                    'codigo_ncm' => '84212300',
                    'cfop' => '5102',
                    'icms_situacao_tributaria' => '00',
                    'unidade_comercial' => 'UN',
                    'quantidade_comercial' => '10.0000',
                    'valor_unitario_comercial' => '15.5000',
                ], $itemOverrides)],
            ],
        ];
    }

    public function test_mapeia_dados_da_nota(): void
    {
        $dados = FocusNfeRecebidaMapper::paraArray($this->jsonBase());

        $this->assertSame('35260712345678000199550010000012340000000001', $dados['chave_acesso']);
        $this->assertSame('Fornecedor Teste', $dados['fornecedor_nome']);
        $this->assertSame('12345678000199', $dados['fornecedor_cnpj']);
        $this->assertSame(155.0, $dados['valor_total']);
        $this->assertSame('2026-09-01', $dados['data_emissao']);
        $this->assertSame('1', $dados['serie']);
        $this->assertSame('1234', $dados['numero_nf']);
    }

    public function test_mapeia_item_com_cst(): void
    {
        $dados = FocusNfeRecebidaMapper::paraArray($this->jsonBase(['icms_situacao_tributaria' => '00']));
        $item = $dados['itens'][0];

        $this->assertSame('7891234567890', $item['codigo_barras']);
        $this->assertSame('84212300', $item['ncm']);
        $this->assertSame('5102', $item['cfop']);
        $this->assertSame('00', $item['cst_csosn']);
        $this->assertSame('NORMAL', $item['tributacao_icms']);
        $this->assertSame(10.0, $item['quantidade']);
        $this->assertSame(15.5, $item['valor_unitario']);
        $this->assertNull($item['origem']);
        $this->assertNull($item['cest']);
    }

    public function test_mapeia_item_com_csosn(): void
    {
        $dados = FocusNfeRecebidaMapper::paraArray($this->jsonBase(['icms_situacao_tributaria' => '102']));
        $item = $dados['itens'][0];

        $this->assertSame('102', $item['cst_csosn']);
        $this->assertSame('NORMAL', $item['tributacao_icms']);
    }
}
