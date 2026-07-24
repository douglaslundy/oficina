<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notificacao;
use App\Models\NotificacaoVisualizacao;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificacaoVisualizacaoModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_visualizacao_manual_com_relacoes(): void
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
        $notificacao = Notificacao::create(['titulo' => 'Aviso', 'texto' => 'Texto do aviso', 'alvo_tipo' => 'TODOS']);

        $visualizacao = NotificacaoVisualizacao::create([
            'tipo' => 'MANUAL', 'notificacao_id' => $notificacao->id,
            'titulo' => $notificacao->titulo, 'mensagem' => $notificacao->texto,
            'oficina_id' => $oficina->id, 'usuario_id' => $usuario->id,
            'ip' => '203.0.113.7', 'user_agent' => 'PHPUnit',
        ]);

        $this->assertDatabaseHas('notificacao_visualizacoes', ['id' => $visualizacao->id, 'tipo' => 'MANUAL']);
        $this->assertSame($oficina->id, $visualizacao->oficina->id);
        $this->assertSame($usuario->id, $visualizacao->usuario->id);
        $this->assertNotNull($visualizacao->visualizado_em);
    }
}
