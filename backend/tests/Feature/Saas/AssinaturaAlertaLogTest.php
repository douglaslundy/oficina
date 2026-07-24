<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssinaturaAlertaLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_alerta_de_cobranca_grava_log_de_visualizacao(): void
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'ciclo_cobranca' => 'MENSAL',
            'proximo_vencimento' => now()->addMonth()->toDateString(),
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);
        $usuario = Usuario::create([
            'nome' => 'Fulano', 'email' => 'fulano@' . uniqid() . '.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('senha123'),
        ]);
        TenancyContext::clear();
        $cobranca = Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => now()->addDays(3)->toDateString(),
        ]);

        $response = $this->withHeaders(['X-Tenant' => $oficina->slug])
            ->actingAs($usuario)
            ->getJson('/api/assinatura/alerta');

        $response->assertStatus(200)->assertJson(['show' => true]);
        $this->assertDatabaseHas('notificacao_visualizacoes', [
            'tipo' => 'COBRANCA', 'cobranca_id' => $cobranca->id,
            'oficina_id' => $oficina->id, 'usuario_id' => $usuario->id,
        ]);
    }
}
