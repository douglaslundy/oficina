<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\NotificacaoVisualizacao;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificacaoCobrancaLogTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarSuperAdmin(): void
    {
        $admin = SuperAdmin::create(['nome' => 'Super', 'email' => 'super@teste.com', 'senha_hash' => Hash::make('senha123')]);
        $this->actingAs($admin, 'saas');
    }

    public function test_index_agrupa_por_oficina_e_cobranca(): void
    {
        $this->autenticarSuperAdmin();
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA',
        ]);
        $cobranca = Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => now()->addDays(3)->toDateString(),
        ]);
        NotificacaoVisualizacao::create([
            'tipo' => 'COBRANCA', 'cobranca_id' => $cobranca->id,
            'titulo' => 'Fatura disponível', 'mensagem' => 'texto', 'oficina_id' => $oficina->id,
        ]);
        NotificacaoVisualizacao::create([
            'tipo' => 'COBRANCA', 'cobranca_id' => $cobranca->id,
            'titulo' => 'Fatura disponível', 'mensagem' => 'texto', 'oficina_id' => $oficina->id,
        ]);

        $response = $this->getJson('/api/saas/notificacoes-cobranca');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame(2, $response->json('data.0.total_exibicoes'));
    }

    public function test_log_retorna_visualizacoes_do_grupo(): void
    {
        $this->autenticarSuperAdmin();
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA',
        ]);
        $cobranca = Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => now()->addDays(3)->toDateString(),
        ]);
        NotificacaoVisualizacao::create([
            'tipo' => 'COBRANCA', 'cobranca_id' => $cobranca->id,
            'titulo' => 'Fatura disponível', 'mensagem' => 'texto', 'oficina_id' => $oficina->id,
            'ip' => '203.0.113.7',
        ]);

        $response = $this->getJson("/api/saas/notificacoes-cobranca/log?oficina_id={$oficina->id}&cobranca_id={$cobranca->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('203.0.113.7', $response->json('data.0.ip'));
    }
}
