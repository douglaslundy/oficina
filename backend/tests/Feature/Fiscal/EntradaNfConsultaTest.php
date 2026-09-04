<?php
declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Models\Oficina;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntradaNfConsultaTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(string $provedor = 'SPEDY'): array
    {
        $oficina = Oficina::create([
            'nome' => 'Oficina Teste', 'slug' => 'oficina-teste',
            'cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'status' => 'ATIVA',
            'provedor_fiscal' => $provedor,
        ]);
        $user = Usuario::create([
            'nome' => 'Admin', 'email' => 'admin@test.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('admin123'),
            'oficina_id' => $oficina->id,
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);
        return [$user->createToken('test')->plainTextToken, $oficina];
    }

    protected function tearDown(): void
    {
        TenancyContext::clear();
        parent::tearDown();
    }

    public function test_consultar_chave_completa_devolve_preview(): void
    {
        [$token, $oficina] = $this->loginAdmin('SPEDY');

        Http::fake([
            '*/inbound-product-invoices?*' => Http::response([
                'items' => [['id' => 'inv-1', 'accessKey' => '35260712345678000199550010000012340000000001', 'isComplete' => true]],
            ], 200),
            '*/inbound-product-invoices/inv-1/xml' => Http::response(<<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe><infNFe Id="NFe35260712345678000199550010000012340000000001" versao="4.00">
    <ide><nNF>1234</nNF><serie>1</serie><dhEmi>2026-07-01T09:15:32-03:00</dhEmi></ide>
    <emit><CNPJ>12345678000199</CNPJ><xNome>Fornecedor QR</xNome></emit>
    <det nItem="1"><prod><cEAN>SEM GTIN</cEAN><xProd>ITEM QR</xProd><qCom>1.0000</qCom><vUnCom>10.0000</vUnCom></prod></det>
  </infNFe></NFe>
</nfeProc>
XML, 200),
        ]);

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf/consultar', ['chave_acesso' => '35260712345678000199550010000012340000000001'])
            ->assertStatus(200)
            ->assertJsonPath('fornecedor_nome', 'Fornecedor QR')
            ->assertJsonPath('ja_lancada', false);
    }

    public function test_consultar_chave_aguardando_manifestacao_retorna_202(): void
    {
        [$token, $oficina] = $this->loginAdmin('SPEDY');

        Http::fake([
            '*/inbound-product-invoices?*' => Http::response([
                'items' => [['id' => 'inv-1', 'accessKey' => '35260712345678000199550010000012340000000001', 'isComplete' => false]],
            ], 200),
            '*/inbound-product-invoices/inv-1/manifest' => Http::response([], 200),
        ]);

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf/consultar', ['chave_acesso' => '35260712345678000199550010000012340000000001'])
            ->assertStatus(202);
    }

    public function test_consultar_chave_nao_encontrada_retorna_404(): void
    {
        [$token, $oficina] = $this->loginAdmin('SPEDY');

        Http::fake(['*/inbound-product-invoices?*' => Http::response(['items' => []], 200)]);

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf/consultar', ['chave_acesso' => '99999999999999999999999999999999999999999999'])
            ->assertStatus(404);
    }

    public function test_consultar_chave_com_motor_nfephp_retorna_422_sem_chamar_http(): void
    {
        [$token, $oficina] = $this->loginAdmin('NFEPHP');
        // Http::fake() sem stub registrado: qualquer chamada real ficaria
        // faked como 200 vazio (não lança) — por isso a prova de "não
        // chamou o provedor" é o assertNothingSent() explícito abaixo, não
        // a mera presença do fake.
        Http::fake();

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf/consultar', ['chave_acesso' => '35260712345678000199550010000012340000000001'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_consultar_chave_ja_lancada_retorna_422_sem_consultar_provedor(): void
    {
        [$token, $oficina] = $this->loginAdmin('SPEDY');
        \App\Models\NotaEntrada::create(['chave_acesso' => '35260712345678000199550010000012340000000001', 'valor_total' => 10]);
        Http::fake();

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf/consultar', ['chave_acesso' => '35260712345678000199550010000012340000000001'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }
}
