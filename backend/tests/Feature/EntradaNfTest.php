<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotaEntrada;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EntradaNfTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(): string
    {
        $user = Usuario::create([
            'nome' => 'Admin', 'email' => 'admin@test.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('admin123'),
        ]);
        return $user->createToken('test')->plainTextToken;
    }

    private function xmlValido(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe>
    <infNFe Id="NFe35260712345678000199550010000012340000000001" versao="4.00">
      <ide>
        <nNF>1234</nNF>
        <serie>1</serie>
        <dhEmi>2026-07-01T09:15:32-03:00</dhEmi>
      </ide>
      <emit>
        <CNPJ>12345678000199</CNPJ>
        <xNome>Auto Pecas Distribuidora LTDA</xNome>
      </emit>
      <det nItem="1">
        <prod>
          <cProd>FORN-001</cProd>
          <cEAN>7891234567890</cEAN>
          <xProd>FILTRO DE OLEO XPTO</xProd>
          <qCom>10.0000</qCom>
          <vUnCom>15.5000</vUnCom>
        </prod>
      </det>
      <det nItem="2">
        <prod>
          <cProd>FORN-002</cProd>
          <cEAN>SEM GTIN</cEAN>
          <xProd>PASTILHA DE FREIO GENERICA</xProd>
          <qCom>4.0000</qCom>
          <vUnCom>42.0000</vUnCom>
        </prod>
      </det>
      <total>
        <ICMSTot>
          <vNF>323.00</vNF>
        </ICMSTot>
      </total>
    </infNFe>
  </NFe>
</nfeProc>
XML;
    }

    public function test_parse_xml_retorna_preview_com_match_e_novo(): void
    {
        $token = $this->loginAdmin();
        Produto::create([
            'nome' => 'Filtro de Óleo Existente', 'sku' => 'FLT-EXIST', 'categoria' => 'Filtros',
            'codigo_barras' => '7891234567890', 'qty_atual' => 3, 'qty_minima' => 5, 'preco_venda' => 40,
        ]);

        $arquivo  = UploadedFile::fake()->createWithContent('nota.xml', $this->xmlValido());
        $response = $this->withToken($token)->post('/api/entradas-nf/parse', ['arquivo' => $arquivo]);

        $response->assertStatus(200);
        $this->assertSame('1234', $response->json('numero_nf'));
        $this->assertCount(2, $response->json('itens'));
        $this->assertTrue($response->json('itens.0.matched'));
        $this->assertFalse($response->json('itens.1.matched'));
        $this->assertSame('Outros', $response->json('itens.1.categoria'));
    }

    public function test_parse_avisa_nota_ja_lancada(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => '35260712345678000199550010000012340000000001', 'numero_nf' => '1234']);

        $arquivo  = UploadedFile::fake()->createWithContent('nota.xml', $this->xmlValido());
        $response = $this->withToken($token)->post('/api/entradas-nf/parse', ['arquivo' => $arquivo]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('ja_lancada'));
    }

    public function test_parse_xml_invalido_retorna_422(): void
    {
        $token    = $this->loginAdmin();
        $arquivo  = UploadedFile::fake()->createWithContent('nota.xml', '<xml><foo>bar</foo></xml>');
        $response = $this->withToken($token)->post('/api/entradas-nf/parse', ['arquivo' => $arquivo]);

        $response->assertStatus(422);
    }

    public function test_confirmar_entrada_cria_produto_novo_e_atualiza_estoque(): void
    {
        $token = $this->loginAdmin();

        $payload = [
            'numero_nf'       => '1234',
            'serie'           => '1',
            'chave_acesso'    => '35260712345678000199550010000012340000000001',
            'fornecedor_nome' => 'Auto Pecas Distribuidora LTDA',
            'fornecedor_cnpj' => '12345678000199',
            'data_emissao'    => '2026-07-01',
            'itens'           => [[
                'codigo_barras'  => '7891234567890',
                'nome'           => 'Filtro de Óleo XPTO',
                'categoria'      => 'Filtros',
                'unidade'        => 'Un',
                'quantidade'     => 10,
                'valor_unitario' => 15.50,
                'preco_venda'    => 25.00,
                'qty_minima'     => 5,
            ]],
        ];

        $response = $this->withToken($token)->postJson('/api/entradas-nf', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('notas_entrada', [
            'numero_nf'    => '1234',
            'chave_acesso' => $payload['chave_acesso'],
        ]);

        $produto = Produto::where('codigo_barras', '7891234567890')->first();
        $this->assertNotNull($produto);
        $this->assertSame(10, $produto->qty_atual);

        $this->assertDatabaseHas('notas_entrada_itens', [
            'produto_id'     => $produto->id,
            'produto_criado' => true,
        ]);
    }

    public function test_confirmar_entrada_soma_estoque_de_produto_existente(): void
    {
        $token   = $this->loginAdmin();
        $produto = Produto::create([
            'nome' => 'Vela', 'sku' => 'VEL-01', 'categoria' => 'Elétrica',
            'codigo_barras' => '789000', 'qty_atual' => 5, 'qty_minima' => 2,
            'preco_custo' => 10, 'preco_venda' => 20,
        ]);

        $payload = [
            'itens' => [[
                'produto_id'     => $produto->id,
                'codigo_barras'  => '789000',
                'quantidade'     => 7,
                'valor_unitario' => 12.00,
            ]],
        ];

        $response = $this->withToken($token)->postJson('/api/entradas-nf', $payload);

        $response->assertStatus(201);
        $this->assertSame(12, $produto->fresh()->qty_atual);
        $this->assertSame(12.00, (float) $produto->fresh()->preco_custo);
    }

    public function test_confirmar_entrada_rejeita_chave_ja_lancada(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => 'CHAVE-DUPLICADA', 'numero_nf' => '1']);

        $payload = [
            'chave_acesso' => 'CHAVE-DUPLICADA',
            'itens'        => [[
                'nome' => 'Produto X', 'categoria' => 'Outros',
                'quantidade' => 1, 'valor_unitario' => 10,
            ]],
        ];

        $response = $this->withToken($token)->postJson('/api/entradas-nf', $payload);

        $response->assertStatus(422);
    }
}
