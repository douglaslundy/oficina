<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Notificacao;
use App\Models\NotificacaoVisualizacao;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificacaoLogTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarSuperAdmin(): void
    {
        $admin = SuperAdmin::create(['nome' => 'Super', 'email' => 'super@teste.com', 'senha_hash' => Hash::make('senha123')]);
        $this->actingAs($admin, 'saas');
    }

    public function test_index_traz_contagem_de_visualizacoes(): void
    {
        $this->autenticarSuperAdmin();
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA',
        ]);
        $notificacao = Notificacao::create(['titulo' => 'Aviso', 'texto' => 'Texto', 'alvo_tipo' => 'TODOS']);
        NotificacaoVisualizacao::create([
            'tipo' => 'MANUAL', 'notificacao_id' => $notificacao->id,
            'titulo' => 'Aviso', 'mensagem' => 'Texto', 'oficina_id' => $oficina->id,
            'ip' => '203.0.113.7',
        ]);

        $response = $this->getJson('/api/saas/notificacoes');

        $response->assertStatus(200);
        $item = collect($response->json('data'))->firstWhere('id', $notificacao->id);
        $this->assertSame(1, $item['total_visualizacoes']);
        $this->assertSame(1, $item['oficinas_distintas']);
    }

    public function test_log_retorna_visualizacoes_paginadas(): void
    {
        $this->autenticarSuperAdmin();
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA',
        ]);
        $notificacao = Notificacao::create(['titulo' => 'Aviso', 'texto' => 'Texto', 'alvo_tipo' => 'TODOS']);
        NotificacaoVisualizacao::create([
            'tipo' => 'MANUAL', 'notificacao_id' => $notificacao->id,
            'titulo' => 'Aviso', 'mensagem' => 'Texto', 'oficina_id' => $oficina->id,
            'ip' => '203.0.113.7',
        ]);

        $response = $this->getJson("/api/saas/notificacoes/{$notificacao->id}/log");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($oficina->nome, $response->json('data.0.oficina.nome'));
    }
}
