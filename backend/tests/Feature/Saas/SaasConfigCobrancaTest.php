<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SaasConfigCobrancaTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarSuperAdmin(): void
    {
        $admin = SuperAdmin::create(['nome' => 'Super', 'email' => 'super@teste.com', 'senha_hash' => Hash::make('senha123')]);
        $this->actingAs($admin, 'saas');
    }

    public function test_show_inclui_campos_de_cobranca(): void
    {
        $this->autenticarSuperAdmin();

        $response = $this->getJson('/api/saas/config');

        $response->assertStatus(200)->assertJsonStructure([
            'data' => ['cobranca_dias_antecedencia_padrao', 'cobranca_dias_suspensao_padrao', 'desconto_anual_pct'],
        ]);
    }

    public function test_update_cobranca_salva_valores(): void
    {
        $this->autenticarSuperAdmin();

        $response = $this->putJson('/api/saas/config/cobranca', [
            'cobranca_dias_antecedencia_padrao' => 7,
            'cobranca_dias_suspensao_padrao'    => 12,
            'desconto_anual_pct'                => 15,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('saas_config', [
            'cobranca_dias_antecedencia_padrao' => 7,
            'cobranca_dias_suspensao_padrao'    => 12,
        ]);
    }
}
