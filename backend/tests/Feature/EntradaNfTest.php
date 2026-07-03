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
}
