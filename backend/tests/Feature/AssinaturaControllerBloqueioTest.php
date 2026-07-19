<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SaasConfig;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssinaturaControllerBloqueioTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficinaComUsuario(string $status = 'SUSPENSA', string $role = 'ADMIN'): array
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => $status,
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

    public function test_status_bloqueio_retorna_dados_da_fatura(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
        ]);

        $response = $this->comoTenant($oficina, $usuario)->getJson('/api/assinatura/status-bloqueio');

        $response->assertStatus(200)->assertJson(['suspensa' => true]);
    }

    public function test_voto_confianca_libera_acesso(): void
    {
        SaasConfig::get()->update(['voto_confianca_dias' => 5]);
        [$oficina, $usuario] = $this->criarOficinaComUsuario();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
        ]);

        $response = $this->comoTenant($oficina, $usuario)->postJson('/api/assinatura/voto-confianca');

        $response->assertStatus(200);
        $oficina->refresh();
        $this->assertSame('ATIVA', $oficina->status);
        $this->assertSame(now()->addDays(5)->toDateString(), $oficina->voto_confianca_ate->toDateString());
    }

    public function test_voto_confianca_bloqueado_se_ja_usado_na_fatura(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
            'voto_confianca_usado_em' => now(),
        ]);

        $response = $this->comoTenant($oficina, $usuario)->postJson('/api/assinatura/voto-confianca');

        $response->assertStatus(422);
        $oficina->refresh();
        $this->assertSame('SUSPENSA', $oficina->status);
    }

    public function test_voto_confianca_bloqueado_para_oficina_nao_suspensa(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario('ATIVA');

        $response = $this->comoTenant($oficina, $usuario)->postJson('/api/assinatura/voto-confianca');

        $response->assertStatus(422);
    }

    public function test_voto_confianca_bloqueado_para_nao_admin(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario('SUSPENSA', 'MECANICO');

        $response = $this->comoTenant($oficina, $usuario)->postJson('/api/assinatura/voto-confianca');

        $response->assertStatus(403);
    }
}
