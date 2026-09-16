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

    /**
     * Achado 2026-09-16: a tela da OS trocava permanentemente "Gerar notas
     * fiscais" por "Baixar notas fiscais" assim que QUALQUER nota existia
     * pra ela — mesmo que só uma das duas categorias (peça/serviço) tivesse
     * saído. Não existia jeito de gerar só a que faltou sem duplicar a que
     * já tinha ido pra SEFAZ. Estes 3 testes cobrem o fix: o orquestrador
     * agora pula categoria já satisfeita, e trata "nada a fazer" como
     * sucesso (202), não como bloqueio (422).
     */
    public function test_pula_nfe_ja_autorizada_e_gera_so_a_nfse_que_faltava(): void
    {
        [$oficina, $token, $os] = $this->cenario();

        NotaFiscal::create([
            'oficina_id' => $oficina->id, 'cliente_id' => $os->cliente_id, 'os_id' => $os->id,
            'modelo' => 'NF-e', 'status' => 'AUTORIZADA',
            'natureza_operacao' => 'Venda de Mercadoria', 'valor_total' => 30,
        ]);

        Http::fake(['*focusnfe*' => Http::response(['status' => 'processando'], 202)]);

        $res = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/os/{$os->id}/emitir-notas")
            ->assertStatus(202);

        $this->assertNull($res->json('nfe_id'), 'NF-e já autorizada não deve ser gerada de novo.');
        $this->assertNotNull($res->json('nfse_id'), 'NFS-e ainda faltava e deve ser gerada agora.');
        $this->assertSame(
            1, NotaFiscal::where('os_id', $os->id)->where('modelo', 'NF-e')->count(),
            'Não pode duplicar a NF-e já autorizada.',
        );
    }

    public function test_retorna_202_sem_gerar_nada_quando_tudo_ja_esta_satisfeito(): void
    {
        [$oficina, $token, $os] = $this->cenario();

        NotaFiscal::create([
            'oficina_id' => $oficina->id, 'cliente_id' => $os->cliente_id, 'os_id' => $os->id,
            'modelo' => 'NF-e', 'status' => 'AUTORIZADA',
            'natureza_operacao' => 'Venda de Mercadoria', 'valor_total' => 30,
        ]);
        NotaFiscal::create([
            'oficina_id' => $oficina->id, 'cliente_id' => $os->cliente_id, 'os_id' => $os->id,
            'modelo' => 'NFS-e', 'status' => 'AUTORIZADA',
            'natureza_operacao' => 'Prestação de Serviços', 'valor_total' => 150,
        ]);

        $res = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/os/{$os->id}/emitir-notas");

        // 202, não 422 — "nada a fazer porque já está tudo pronto" não é
        // um bloqueio, é sucesso trivial.
        $res->assertStatus(202);
        $this->assertNull($res->json('nfe_id'));
        $this->assertNull($res->json('nfse_id'));
        $this->assertSame(
            2, NotaFiscal::where('os_id', $os->id)->count(),
            'Não pode duplicar nenhuma das duas notas já satisfeitas.',
        );
    }

    public function test_nfe_rejeitada_nao_conta_como_satisfeita_e_gera_nova_tentativa(): void
    {
        [$oficina, $token, $os] = $this->cenario();

        NotaFiscal::create([
            'oficina_id' => $oficina->id, 'cliente_id' => $os->cliente_id, 'os_id' => $os->id,
            'modelo' => 'NF-e', 'status' => 'REJEITADA', 'mensagem_erro' => 'rejeitada em teste',
            'natureza_operacao' => 'Venda de Mercadoria', 'valor_total' => 30,
        ]);

        Http::fake(['*focusnfe*' => Http::response(['status' => 'processando'], 202)]);

        $res = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/os/{$os->id}/emitir-notas")
            ->assertStatus(202);

        $this->assertNotNull(
            $res->json('nfe_id'),
            'NF-e rejeitada NÃO conta como satisfeita — precisa tentar gerar uma nova.',
        );
        $this->assertSame(
            2, NotaFiscal::where('os_id', $os->id)->where('modelo', 'NF-e')->count(),
            'A rejeitada antiga fica no histórico, somada à nova tentativa.',
        );
    }
}
