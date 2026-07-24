<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notificacao;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificacaoVisualizarTest extends TestCase
{
    use RefreshDatabase;

    public function test_visualizar_registra_log_com_ip_encaminhado_pelo_proxy(): void
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA',
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);
        $usuario = Usuario::create([
            'nome' => 'Fulano', 'email' => 'fulano@' . uniqid() . '.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('senha123'),
        ]);
        TenancyContext::clear();
        $notificacao = Notificacao::create(['titulo' => 'Aviso', 'texto' => 'Texto', 'alvo_tipo' => 'TODOS']);

        $response = $this->withHeaders([
                'X-Tenant' => $oficina->slug,
                'X-Forwarded-For' => '203.0.113.7',
            ])
            ->actingAs($usuario)
            ->postJson("/api/notificacoes/{$notificacao->id}/visualizar");

        $response->assertStatus(201);
        $this->assertDatabaseHas('notificacao_visualizacoes', [
            'tipo' => 'MANUAL', 'notificacao_id' => $notificacao->id,
            'oficina_id' => $oficina->id, 'usuario_id' => $usuario->id,
            'ip' => '203.0.113.7',
        ]);
    }
}
