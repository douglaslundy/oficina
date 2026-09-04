<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotaEntrada;
use App\Models\Oficina;
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
        $oficina = Oficina::create([
            'nome' => 'Oficina Teste', 'slug' => 'oficina-teste',
            'cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'status' => 'ATIVA',
        ]);
        $user = Usuario::create([
            'nome' => 'Admin', 'email' => 'admin@test.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('admin123'),
            'oficina_id' => $oficina->id,
        ]);
        // Os testes criam NotaEntrada direto (fora do request), então o
        // HasTenantScope precisa do contexto pra preencher oficina_id.
        \App\Tenancy\TenancyContext::set($oficina->id, $oficina->slug);
        return $user->createToken('test')->plainTextToken;
    }

    protected function tearDown(): void
    {
        \App\Tenancy\TenancyContext::clear();
        parent::tearDown();
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

    private function xmlComDadosFiscais(): string
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
          <NCM>84212300</NCM>
        </prod>
        <imposto>
          <ICMS>
            <ICMS00>
              <orig>0</orig>
              <CST>00</CST>
            </ICMS00>
          </ICMS>
        </imposto>
      </det>
      <total>
        <ICMSTot>
          <vNF>155.00</vNF>
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

    public function test_parse_nota_ja_lancada_sinaliza_atualizacao_fiscal_disponivel(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => '35260712345678000199550010000012340000000001', 'numero_nf' => '1234']);
        Produto::create([
            'nome' => 'Filtro de Óleo Existente', 'sku' => 'FLT-EXIST', 'categoria' => 'Filtros',
            'codigo_barras' => '7891234567890', 'qty_atual' => 3, 'qty_minima' => 5, 'preco_venda' => 40,
            // sem ncm — o XML de teste traz NCM? Não traz (xmlValido() não tem NCM/ICMS).
            // Este teste precisa de um XML com dado fiscal — usar xmlComDadosFiscais() (Step 2 abaixo).
        ]);

        $arquivo  = UploadedFile::fake()->createWithContent('nota.xml', $this->xmlComDadosFiscais());
        $response = $this->withToken($token)->post('/api/entradas-nf/parse', ['arquivo' => $arquivo]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('ja_lancada'));
        $this->assertTrue($response->json('atualizacao_fiscal_disponivel'));
        $this->assertTrue($response->json('itens.0.sera_atualizado'));
    }

    public function test_parse_nota_ja_lancada_sem_nada_fiscal_pra_atualizar(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => '35260712345678000199550010000012340000000001', 'numero_nf' => '1234']);
        Produto::create([
            // tributacao_icms 'NORMAL' — bate exatamente com o que o
            // xmlComDadosFiscais() carrega (ICMS00/CST 00). Um valor
            // divergente (ex.: 'ST') faz haveriaMudanca() corretamente achar
            // uma atualização disponível, o que não é o que este teste
            // (produto já 100% revisado, nada pra atualizar) quer exercitar.
            'nome' => 'Filtro de Óleo Existente', 'sku' => 'FLT-EXIST', 'categoria' => 'Filtros',
            'codigo_barras' => '7891234567890', 'qty_atual' => 3, 'qty_minima' => 5, 'preco_venda' => 40,
            'ncm' => '84212300', 'origem' => 0, 'tributacao_icms' => 'NORMAL',
            'fiscal_fonte' => 'MANUAL', 'fiscal_revisado_em' => now(),
        ]);

        $arquivo  = UploadedFile::fake()->createWithContent('nota.xml', $this->xmlComDadosFiscais());
        $response = $this->withToken($token)->post('/api/entradas-nf/parse', ['arquivo' => $arquivo]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('ja_lancada'));
        $this->assertFalse($response->json('atualizacao_fiscal_disponivel'));
    }

    public function test_parse_nota_ja_lancada_descarta_itens_sem_produto_correspondente(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => '35260712345678000199550010000012340000000001', 'numero_nf' => '1234']);
        // Nenhum produto cadastrado com os códigos de barras do XML.

        $arquivo  = UploadedFile::fake()->createWithContent('nota.xml', $this->xmlValido());
        $response = $this->withToken($token)->post('/api/entradas-nf/parse', ['arquivo' => $arquivo]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('ja_lancada'));
        // xmlValido() tem 2 itens (1 matched se existisse produto, 1 sempre sem match) —
        // sem nenhum produto cadastrado, os dois ficam sem match e a lista fica vazia.
        $this->assertCount(0, $response->json('itens'));
        $this->assertFalse($response->json('atualizacao_fiscal_disponivel'));
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

    public function test_atualizar_fiscal_aplica_campos_pendentes_e_retorna_contagem(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => 'CHAVE-DUPLICADA', 'numero_nf' => '1']);
        $produto = Produto::create([
            'nome' => 'Vela', 'sku' => 'VEL-02', 'categoria' => 'Elétrica',
            'codigo_barras' => '789000', 'qty_atual' => 5, 'qty_minima' => 2, 'preco_venda' => 20,
        ]);

        $payload = [
            'chave_acesso' => 'CHAVE-DUPLICADA',
            'itens'        => [[
                'produto_id' => $produto->id,
                'ncm' => '85122000', 'origem' => 2, 'tributacao_icms' => 'NORMAL',
            ]],
        ];

        $response = $this->withToken($token)->postJson('/api/entradas-nf/atualizar-fiscal', $payload);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('produtos_atualizados'));
        $produto->refresh();
        $this->assertSame('85122000', $produto->ncm);
        $this->assertSame(2, $produto->origem);
        $this->assertSame('NORMAL', $produto->tributacao_icms);
        // Nunca mexeu em estoque.
        $this->assertSame(5, $produto->qty_atual);
    }

    public function test_atualizar_fiscal_recusa_quando_nao_ha_nada_pra_atualizar(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => 'CHAVE-DUPLICADA', 'numero_nf' => '1']);
        $produto = Produto::create([
            'nome' => 'Vela', 'sku' => 'VEL-03', 'categoria' => 'Elétrica',
            'codigo_barras' => '789000', 'qty_atual' => 5, 'qty_minima' => 2, 'preco_venda' => 20,
            'ncm' => '85122000', 'origem' => 2, 'tributacao_icms' => 'NORMAL',
            'fiscal_fonte' => 'MANUAL', 'fiscal_revisado_em' => now(),
        ]);

        $payload = [
            'chave_acesso' => 'CHAVE-DUPLICADA',
            'itens'        => [[
                'produto_id' => $produto->id,
                'ncm' => '85122000', 'origem' => 2, 'tributacao_icms' => 'NORMAL',
            ]],
        ];

        $response = $this->withToken($token)->postJson('/api/entradas-nf/atualizar-fiscal', $payload);

        $response->assertStatus(422);
        $this->assertSame('Esta nota fiscal já foi lançada anteriormente.', $response->json('message'));
    }

    public function test_atualizar_fiscal_recusa_chave_de_nota_nunca_lancada(): void
    {
        $token = $this->loginAdmin();
        $produto = Produto::create([
            'nome' => 'Vela', 'sku' => 'VEL-04', 'categoria' => 'Elétrica',
            'codigo_barras' => '789000', 'qty_atual' => 5, 'qty_minima' => 2, 'preco_venda' => 20,
        ]);

        $payload = [
            'chave_acesso' => 'CHAVE-NUNCA-LANCADA',
            'itens'        => [[
                'produto_id' => $produto->id,
                'ncm' => '85122000',
            ]],
        ];

        $response = $this->withToken($token)->postJson('/api/entradas-nf/atualizar-fiscal', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('não foi lançada', $response->json('message'));
    }

    public function test_atualizar_fiscal_nunca_cria_nota_entrada_nem_mexe_estoque(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => 'CHAVE-DUPLICADA', 'numero_nf' => '1']);
        $produto = Produto::create([
            'nome' => 'Vela', 'sku' => 'VEL-05', 'categoria' => 'Elétrica',
            'codigo_barras' => '789000', 'qty_atual' => 5, 'qty_minima' => 2, 'preco_venda' => 20,
        ]);

        $this->withToken($token)->postJson('/api/entradas-nf/atualizar-fiscal', [
            'chave_acesso' => 'CHAVE-DUPLICADA',
            'itens'        => [['produto_id' => $produto->id, 'ncm' => '85122000']],
        ]);

        $this->assertSame(1, NotaEntrada::count()); // só a original, nenhuma nova
        $this->assertSame(5, $produto->fresh()->qty_atual);
        $this->assertDatabaseCount('notas_entrada_itens', 0);
    }

    public function test_listar_entradas_nf(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['numero_nf' => '1']);
        NotaEntrada::create(['numero_nf' => '2']);

        $response = $this->withToken($token)->getJson('/api/entradas-nf');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_detalhe_entrada_nf_com_itens(): void
    {
        $token   = $this->loginAdmin();
        $produto = Produto::create(['nome' => 'X', 'sku' => 'X1', 'categoria' => 'Outros']);
        $nota    = NotaEntrada::create(['numero_nf' => '1']);
        \App\Models\NotaEntradaItem::create([
            'nota_entrada_id' => $nota->id, 'produto_id' => $produto->id,
            'quantidade' => 2, 'valor_unitario' => 10,
        ]);

        $response = $this->withToken($token)->getJson("/api/entradas-nf/{$nota->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.itens'));
    }
}
