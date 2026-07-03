<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Configuracao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ConfiguracaoEntradaNfTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(): string
    {
        $user = Usuario::create([
            'nome' => 'Admin', 'email' => 'admin@test.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('admin123'),
        ]);
        return $user->createToken('test')->plainTextToken;
    }

    public function test_atualiza_markup_e_toggle_de_custo(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->putJson('/api/configuracoes', [
            'markup_padrao_entrada_nf'   => 55.5,
            'atualizar_custo_entrada_nf' => false,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('configuracoes', [
            'markup_padrao_entrada_nf'   => 55.5,
            'atualizar_custo_entrada_nf' => false,
        ]);
    }

    public function test_show_retorna_valores_padrao(): void
    {
        Configuracao::create([]);
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->getJson('/api/configuracoes');

        $response->assertStatus(200);
        $this->assertSame(40.0, (float) $response->json('markup_padrao_entrada_nf'));
        $this->assertTrue($response->json('atualizar_custo_entrada_nf'));
    }
}
