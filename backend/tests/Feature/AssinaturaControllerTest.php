<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssinaturaControllerTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficinaComUsuario(string $role = 'ADMIN'): array
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
            'role' => $role, 'status' => 'ATIVO', 'senha_hash' => Hash::make('senha123'),
        ]);
        TenancyContext::clear();

        return [$oficina, $usuario];
    }

    private function comoTenant(Oficina $oficina, Usuario $usuario): static
    {
        return $this->withHeaders(['X-Tenant' => $oficina->slug])->actingAs($usuario);
    }

    public function test_alerta_retorna_show_false_sem_cobranca_pendente(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario();

        $response = $this->comoTenant($oficina, $usuario)->getJson('/api/assinatura/alerta');

        $response->assertStatus(200)->assertJson(['show' => false]);
    }

    public function test_alerta_retorna_mensagem_com_cobranca_pendente(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => now()->addDays(3)->toDateString(),
        ]);

        $response = $this->comoTenant($oficina, $usuario)->getJson('/api/assinatura/alerta');

        $response->assertStatus(200)->assertJson(['show' => true, 'fase' => 'DISPONIVEL']);
    }

    public function test_mudar_ciclo_como_admin_funciona(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario('ADMIN');

        $response = $this->comoTenant($oficina, $usuario)->postJson('/api/assinatura/mudar-ciclo', ['ciclo' => 'ANUAL']);

        $response->assertStatus(200);
        $oficina->refresh();
        $this->assertSame('ANUAL', $oficina->ciclo_cobranca);
    }

    public function test_mudar_ciclo_como_mecanico_e_bloqueado(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario('MECANICO');

        $response = $this->comoTenant($oficina, $usuario)->postJson('/api/assinatura/mudar-ciclo', ['ciclo' => 'ANUAL']);

        $response->assertStatus(403);
    }
}
