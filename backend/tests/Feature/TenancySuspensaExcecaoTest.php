<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Oficina;
use App\Models\Plano;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenancySuspensaExcecaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficinaSuspensaComUsuario(): array
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'SUSPENSA',
        ]);
        $usuario = Usuario::create([
            'nome' => 'Fulano', 'email' => 'fulano@' . uniqid() . '.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('senha123'),
            'oficina_id' => $oficina->id,
        ]);
        return [$oficina, $usuario];
    }

    public function test_rota_generica_continua_bloqueada_com_codigo(): void
    {
        [$oficina, $usuario] = $this->criarOficinaSuspensaComUsuario();

        $response = $this->withHeaders(['X-Tenant' => $oficina->slug])
            ->actingAs($usuario)
            ->getJson('/api/dashboard');

        $response->assertStatus(403)->assertJson(['code' => 'OFICINA_SUSPENSA']);
    }
}
