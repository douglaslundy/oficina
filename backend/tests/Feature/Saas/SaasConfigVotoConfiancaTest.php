<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SaasConfigVotoConfiancaTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarSuperAdmin(): void
    {
        $admin = SuperAdmin::create(['nome' => 'Super', 'email' => 'super@teste.com', 'senha_hash' => Hash::make('senha123')]);
        $this->actingAs($admin, 'saas');
    }

    public function test_show_inclui_voto_confianca_dias(): void
    {
        $this->autenticarSuperAdmin();

        $response = $this->getJson('/api/saas/config');

        $response->assertStatus(200)->assertJsonStructure(['data' => ['voto_confianca_dias']]);
    }

    public function test_update_cobranca_salva_voto_confianca_dias(): void
    {
        $this->autenticarSuperAdmin();

        $response = $this->putJson('/api/saas/config/cobranca', [
            'cobranca_dias_antecedencia_padrao' => 5,
            'cobranca_dias_suspensao_padrao'    => 10,
            'desconto_anual_pct'                => 15,
            'alerta_cobranca_vezes_dia'          => 2,
            'alerta_cobranca_dias_exibicao'      => 20,
            'voto_confianca_dias'                => 7,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('saas_config', ['voto_confianca_dias' => 7]);
    }
}
