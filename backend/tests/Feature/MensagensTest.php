<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AlertaLog;
use App\Models\Oficina;
use App\Models\Usuario;
use App\Services\MensagemLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Histórico de mensagens (WhatsApp + e-mail) com filtro por canal, e o
 * registro do último acesso do usuário.
 */
class MensagensTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private Oficina $oficina;
    private Usuario $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oficina = Oficina::create([
            'nome' => 'Oficina Teste', 'slug' => 'oficina-teste',
            'cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'status' => 'ATIVA',
        ]);
        $this->user = Usuario::create([
            'nome' => 'Admin', 'email' => 'admin@test.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('admin123'),
            'oficina_id' => $this->oficina->id,
        ]);
        \App\Tenancy\TenancyContext::set($this->oficina->id, $this->oficina->slug);
        $this->token = $this->user->createToken('test')->plainTextToken;

        AlertaLog::create(['tipo' => 'ESTOQUE_BAIXO', 'canal' => 'WHATSAPP', 'destinatario' => '5535999990000', 'mensagem' => 'Estoque do filtro baixo', 'sucesso' => true]);
        AlertaLog::create(['tipo' => 'ORCAMENTO', 'canal' => 'EMAIL', 'assunto' => 'Orçamento · OS #7', 'destinatario' => 'cliente@exemplo.com', 'mensagem' => 'Veja o orçamento', 'sucesso' => true]);
        AlertaLog::create(['tipo' => 'NPS', 'canal' => 'EMAIL', 'destinatario' => 'outro@exemplo.com', 'mensagem' => 'Como foi?', 'sucesso' => false, 'erro' => 'SMTP fora']);
    }

    protected function tearDown(): void
    {
        \App\Tenancy\TenancyContext::clear();
        parent::tearDown();
    }

    private function chamar(string $url)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->token, 'X-Tenant' => 'oficina-teste'])->getJson($url);
    }

    public function test_sem_filtro_traz_todas_as_mensagens_de_todos_os_canais(): void
    {
        $r = $this->chamar('/api/mensagens')->assertOk();

        $this->assertSame(3, $r->json('total'));
        $this->assertEqualsCanonicalizing(['WHATSAPP', 'EMAIL', 'EMAIL'], array_column($r->json('data'), 'canal'));
    }

    public function test_filtra_por_whatsapp_e_por_email(): void
    {
        $this->assertSame(1, $this->chamar('/api/mensagens?canal=WHATSAPP')->json('total'));
        $emails = $this->chamar('/api/mensagens?canal=EMAIL');
        $this->assertSame(2, $emails->json('total'));
        $this->assertContains('Orçamento · OS #7', array_column($emails->json('data'), 'assunto'));
    }

    public function test_canal_invalido_e_ignorado_e_nao_esconde_mensagens(): void
    {
        $this->assertSame(3, $this->chamar('/api/mensagens?canal=SMS')->json('total'));
    }

    public function test_busca_por_destinatario_assunto_ou_texto(): void
    {
        $this->assertSame(1, $this->chamar('/api/mensagens?busca=cliente@exemplo')->json('total'));
        $this->assertSame(1, $this->chamar('/api/mensagens?busca=orçamento')->json('total'));
        $this->assertSame(1, $this->chamar('/api/mensagens?busca=filtro')->json('total'));
    }

    public function test_mensagens_de_outra_oficina_nao_aparecem(): void
    {
        $outra = Oficina::create(['nome' => 'Outra', 'slug' => 'outra', 'cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'status' => 'ATIVA']);
        AlertaLog::create(['oficina_id' => $outra->id, 'tipo' => 'ALERTA', 'canal' => 'EMAIL', 'destinatario' => 'x@y.com', 'mensagem' => 'segredo', 'sucesso' => true]);

        $this->assertSame(3, $this->chamar('/api/mensagens')->json('total'));
    }

    public function test_servico_registra_email_no_historico_da_oficina(): void
    {
        app(MensagemLogService::class)->registrarEmail($this->oficina->id, 'NPS', 'cli@exemplo.com', 'Assunto', 'Corpo', true, null, 'CLIENTE');

        $this->assertSame(4, $this->chamar('/api/mensagens')->json('total'));
        $this->assertSame(3, $this->chamar('/api/mensagens?canal=EMAIL')->json('total'));
    }

    public function test_registrar_acesso_grava_ultimo_acesso(): void
    {
        $this->assertNull($this->user->fresh()->ultimo_acesso);

        $this->user->registrarAcesso();

        $this->assertNotNull($this->user->fresh()->ultimo_acesso);
        $this->assertEqualsWithDelta(now()->timestamp, $this->user->fresh()->ultimo_acesso->timestamp, 5);
    }

    public function test_lista_de_usuarios_devolve_o_ultimo_acesso(): void
    {
        $this->user->registrarAcesso();

        $linha = collect($this->chamar('/api/usuarios')->assertOk()->json('data'))->firstWhere('id', $this->user->id);
        $this->assertNotEmpty($linha['ultimo_acesso']);
    }
}
