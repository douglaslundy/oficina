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

        // Cliente PJ → sempre NF-e (não NFC-e). Precisa de IE cadastrada
        // (bug real corrigido 2026-09-23, NF-e #13, cStat=232 — ver
        // IndicadorIeDestinatarioResolverTest) senão a emissão de NF-e
        // bloqueia neste fixture, que é exatamente o comportamento correto
        // que a correção introduziu.
        $cliente = Cliente::create([
            'nome' => 'Cliente PJ', 'cpf_cnpj' => '12345678000199', 'uf' => 'SP',
            'inscricao_estadual' => '123456789',
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
        // Bug real reportado pelo usuário (2026-09-17): este era exatamente
        // o caminho onde a nota saía com o ISS somado ao total (R$150 de
        // serviço virava R$157,50 com a alíquota padrão de 5%). ISS é "por
        // dentro" — o total tem que ficar igual ao subtotal.
        $this->assertSame(150.0, (float) $nfse->valor_total, 'Total da NFS-e não pode somar o ISS.');
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

    /**
     * Bug real reportado pelo usuário (2026-09-17): mudou a alíquota de ISS
     * de 5% pra 2,01% em Configurações › Empresa, gerou uma NF nova a
     * partir de uma OS, e ela saiu com 5% mesmo assim. Causa raiz:
     * `EmissaoOrquestradorService::orquestrar()` nunca mandava
     * `aliquota_iss` no payload pra `CriarNotaFiscalService::criar()`, que
     * caía no fallback hardcoded `?? 5.00` em vez de ler
     * `Configuracao.aliquota_iss`. O fluxo manual (`/fiscal/emitir`) não
     * tinha esse bug — o frontend já lia e mandava o valor configurado; só
     * o caminho "Gerar notas fiscais" da tela da OS (o mais usado) tinha o
     * defeito.
     */
    public function test_nfse_da_os_usa_a_aliquota_iss_configurada_na_empresa_nao_5_por_cento_hardcoded(): void
    {
        [$oficina, $token, $os] = $this->cenario();
        Configuracao::where('oficina_id', $oficina->id)->update(['aliquota_iss' => 2.01]);

        Http::fake(['*focusnfe*' => Http::response(['status' => 'processando'], 202)]);

        $res = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/os/{$os->id}/emitir-notas")
            ->assertStatus(202);

        $nfse = NotaFiscal::find($res->json('nfse_id'));
        $this->assertNotNull($nfse);
        $this->assertSame(2.01, (float) $nfse->aliquota_iss);
        // Serviço de R$150 (valor fixo do cenário) a 2,01% = R$3,015 → R$3,02 arredondado.
        $this->assertEqualsWithDelta(3.02, (float) $nfse->valor_iss, 0.01);
        // ISS é "por dentro" — o total continua R$150, não R$153,02.
        $this->assertSame(150.0, (float) $nfse->valor_total);
    }

    /**
     * Tarefa 2026-09-22: campo de desconto na OS/PDV. O desconto da OS
     * precisa ser rateado proporcionalmente entre a NF-e (peças) e a NFS-e
     * (serviços) — não pode ser jogado inteiro num dos dois documentos nem
     * ignorado. Cenário base: peças R$30 + serviços R$150 = R$180. Desconto
     * de R$18 (10% do total) → 10% de cada subtotal: R$3 na NF-e, R$15 na
     * NFS-e.
     */
    public function test_desconto_da_os_e_rateado_proporcionalmente_entre_nfe_e_nfse(): void
    {
        [$oficina, $token, $os] = $this->cenario();
        $os->update(['desconto' => 18]);

        Http::fake(['*focusnfe*' => Http::response(['status' => 'processando'], 202)]);

        $res = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/os/{$os->id}/emitir-notas")
            ->assertStatus(202);

        $nfe  = NotaFiscal::find($res->json('nfe_id'));
        $nfse = NotaFiscal::find($res->json('nfse_id'));

        $this->assertSame(3.0, (float) $nfe->desconto, 'NF-e leva 30/180 = 1/6 do desconto de R$18.');
        $this->assertSame(27.0, (float) $nfe->valor_total);
        $this->assertSame(15.0, (float) $nfse->desconto, 'NFS-e leva 150/180 = 5/6 do desconto de R$18.');
        $this->assertSame(135.0, (float) $nfse->valor_total);
        // A soma dos dois documentos tem que bater com o total cobrado do
        // cliente (subtotal 180 - desconto 18 = 162) — nenhum centavo pode
        // se perder ou duplicar no rateio.
        $this->assertSame(162.0, (float) $nfe->valor_total + (float) $nfse->valor_total);
    }

    /**
     * Quando só uma categoria existe (aqui: só peça, sem serviço), o
     * desconto inteiro tem que ir pra ela — a fórmula proporcional
     * degenera corretamente pra esse caso (subtotalServicos = 0).
     */
    public function test_desconto_vai_inteiro_para_a_unica_categoria_quando_so_ha_pecas(): void
    {
        [$oficina, $token, $os] = $this->cenario();
        OsItem::where('os_id', $os->id)->where('tipo', 'SERVICO')->delete();
        $os->update(['desconto' => 10]);

        Http::fake(['*focusnfe*' => Http::response(['status' => 'processando'], 202)]);

        $res = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/os/{$os->id}/emitir-notas")
            ->assertStatus(202);

        $this->assertNull($res->json('nfse_id'));
        $nfe = NotaFiscal::find($res->json('nfe_id'));
        $this->assertSame(10.0, (float) $nfe->desconto);
        $this->assertSame(20.0, (float) $nfe->valor_total, 'Peça de R$30 - desconto de R$10 = R$20.');
    }

    /**
     * Achado ao escrever estes testes (2026-09-22): `$os->desconto` é
     * clampado no subtotal de TODOS os itens da OS, incluindo peça sem
     * produto — que nunca vira NF-e nem NFS-e. Sem reclampar aqui também,
     * um desconto que "cabe" no subtotal total da OS podia não caber no
     * subtotal faturável (só peça-com-produto + serviço), deixando uma das
     * notas com valor_total negativo.
     */
    public function test_desconto_que_extrapola_o_subtotal_faturavel_e_reclampado(): void
    {
        [$oficina, $token, $os] = $this->cenario();
        // Peça sem produto de R$50: entra no subtotal "bruto" da OS, mas
        // nunca vira nota — não pode fazer parte da base do rateio.
        OsItem::create([
            'os_id' => $os->id, 'oficina_id' => $oficina->id, 'tipo' => 'PECA',
            'produto_id' => null, 'descricao' => 'Peça avulsa sem cadastro', 'quantidade' => 1, 'valor_unitario' => 50,
        ]);
        // Subtotal faturável (peça c/ produto R$30 + serviço R$150) = R$180.
        // Subtotal BRUTO da OS (com a peça avulsa de R$50) = R$230 — um
        // desconto de R$200 cabe no bruto, mas ultrapassa o faturável.
        $os->update(['desconto' => 200]);

        Http::fake(['*focusnfe*' => Http::response(['status' => 'processando'], 202)]);

        $res = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/os/{$os->id}/emitir-notas")
            ->assertStatus(202);

        $nfe  = NotaFiscal::find($res->json('nfe_id'));
        $nfse = NotaFiscal::find($res->json('nfse_id'));

        $this->assertGreaterThanOrEqual(0.0, (float) $nfe->valor_total, 'NF-e não pode sair com valor_total negativo.');
        $this->assertGreaterThanOrEqual(0.0, (float) $nfse->valor_total, 'NFS-e não pode sair com valor_total negativo.');
        // Reclampado em 180 (subtotal faturável): NF-e leva 30/180 x 180 =
        // 30 (zera), NFS-e leva 150/180 x 180 = 150 (zera). Nenhuma negativa.
        $this->assertSame(0.0, (float) $nfe->valor_total);
        $this->assertSame(0.0, (float) $nfse->valor_total);
    }
}
