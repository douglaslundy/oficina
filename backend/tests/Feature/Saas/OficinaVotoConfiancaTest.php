<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SaasConfig;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OficinaVotoConfiancaTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarSuperAdmin(): void
    {
        $admin = SuperAdmin::create(['nome' => 'Super', 'email' => 'super@teste.com', 'senha_hash' => Hash::make('senha123')]);
        $this->actingAs($admin, 'saas');
    }

    public function test_admin_concede_voto_confianca_mesmo_ja_usado_na_fatura(): void
    {
        $this->autenticarSuperAdmin();
        SaasConfig::get()->update(['voto_confianca_dias' => 7]);

        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'SUSPENSA',
        ]);
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
            'voto_confianca_usado_em' => now(), // já usado pelo self-service — admin ignora essa trava
        ]);

        $response = $this->postJson("/api/saas/oficinas/{$oficina->id}/voto-confianca");

        $response->assertStatus(200);
        $oficina->refresh();
        $this->assertSame('ATIVA', $oficina->status);
        $this->assertSame(now()->addDays(7)->toDateString(), $oficina->voto_confianca_ate->toDateString());
    }
}
