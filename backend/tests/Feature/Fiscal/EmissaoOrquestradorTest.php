<?php
declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Models\Oficina;
use App\Models\OrdemServico;
use App\Models\OsItem;
use App\Models\Produto;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmissaoOrquestradorTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): array
    {
        $oficina = Oficina::create([
            'nome' => 'Oficina', 'slug' => 'oficina-teste',
            'cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'status' => 'ATIVA',
            'provedor_fiscal' => 'FOCUS',
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);

        Configuracao::create([
            'oficina_id' => $oficina->id, 'ambiente_fiscal' => 'HOMOLOGACAO',
            'uf' => 'MG', 'regime_tributario' => 'Simples Nacional',
            'serie_nf' => '1', 'serie_nfce' => '1',
        ]);

        $admin = Usuario::create([
            'nome' => 'Admin', 'email' => 'a@t.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('x'),
            'oficina_id' => $oficina->id,
        ]);

        // Cliente PJ → sempre NF-e (não NFC-e).
        $cliente = Cliente::create([
            'nome' => 'Cliente PJ', 'cpf_cnpj' => '12345678000199', 'uf' => 'SP',
            'oficina_id' => $oficina->id,
        ]);

        $produto = Produto::create([
            'nome' => 'Filtro', 'sku' => 'FLT1', 'categoria' => 'Filtros', 'unidade' => 'UN',
            'qty_atual' => 10, 'qty_minima' => 2, 'preco_custo' => 10, 'preco_venda' => 30,
            'ncm' => '84212300', 'origem' => 0, 'tributacao_icms' => 'NORMAL',
        ]);

        $os = OrdemServico::create([
            'cliente_id' => $cliente->id, 'oficina_id' => $oficina->id,
            'status' => 'CONCLUIDA', 'valor_total' => 180,
        ]);
        OsItem::create([
            'os_id' => $os->id, 'oficina_id' => $oficina->id, 'tipo' => 'PECA',
            'produto_id' => $produto->id, 'descricao' => 'Filtro', 'quantidade' => 1, 'valor_unitario' => 30,
        ]);
        OsItem::create([
            'os_id' => $os->id, 'oficina_id' => $oficina->id, 'tipo' => 'SERVICO',
            'produto_id' => null, 'descricao' => 'Troca de óleo', 'quantidade' => 1, 'valor_unitario' => 150,
        ]);

        return [$oficina, $admin->createToken('t')->plainTextToken, $os, $produto];
    }

    protected function tearDown(): void
    {
        TenancyContext::clear();
        parent::tearDown();
    }

    public function test_os_mista_gera_nfe_das_pecas_e_nfse_dos_servicos(): void
    {
        [$oficina, $token, $os] = $this->cenario();

        Http::fake([
            '*focusnfe*' => Http::response(['status' => 'processando'], 202),
        ]);

        $res = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/os/{$os->id}/emitir-notas")
            ->assertStatus(202);

        $nfeId  = $res->json('nfe_id');
        $nfseId = $res->json('nfse_id');
        $this->assertNotNull($nfeId);
        $this->assertNotNull($nfseId);
        $this->assertNotSame($nfeId, $nfseId);

        $nfe  = NotaFiscal::find($nfeId);
        $nfse = NotaFiscal::find($nfseId);
        $this->assertSame('NF-e', $nfe->modelo);
        $this->assertSame('Venda de Mercadoria', $nfe->natureza_operacao);
        $this->assertSame(30.0, (float) $nfe->valor_total);
        $this->assertSame('NFS-e', $nfse->modelo);
        $this->assertSame(150.0, (float) $nfse->subtotal);
        $this->assertSame($os->id, $nfe->os_id);
    }

    public function test_produto_com_origem_pendente_bloqueia_so_a_nfe_nao_a_nfse(): void
    {
        [$oficina, $token, $os, $produto] = $this->cenario();
        $produto->update(['origem' => null]); // pendência fiscal na peça

        Http::fake(['*focusnfe*' => Http::response(['status' => 'processando'], 202)]);

        $res = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/os/{$os->id}/emitir-notas")
            ->assertStatus(202);

        $this->assertNull($res->json('nfe_id'));
        $this->assertNotNull($res->json('nfse_id'), 'A NFS-e dos serviços sai mesmo com a NF-e bloqueada.');
        $this->assertTrue(collect($res->json('avisos'))->contains(fn ($a) => str_contains($a, 'origem da mercadoria')));
    }

    public function test_os_sem_pecas_nem_servicos_retorna_422(): void
    {
        [$oficina, $token, $os] = $this->cenario();
        OsItem::where('os_id', $os->id)->delete();

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/os/{$os->id}/emitir-notas")
            ->assertStatus(422);
    }
}
