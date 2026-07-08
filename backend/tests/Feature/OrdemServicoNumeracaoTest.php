<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Oficina;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrdemServicoNumeracaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficinaComAdmin(string $slug, string $email, string $cpfAdmin, string $cpfCliente): array
    {
        $oficina = Oficina::create([
            'nome' => "Oficina {$slug}", 'slug' => $slug, 'cnpj' => '00000000000000', 'status' => 'ATIVA',
        ]);
        $admin = Usuario::create([
            'nome' => 'Admin', 'email' => $email, 'cpf' => $cpfAdmin,
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('pass'),
            'oficina_id' => $oficina->id,
        ]);
        $cliente = Cliente::create([
            'nome' => 'Cliente', 'cpf_cnpj' => $cpfCliente, 'oficina_id' => $oficina->id,
        ]);
        $token = $admin->createToken('t')->plainTextToken;

        return [$oficina, $token, $cliente];
    }

    // ordens_servico.numero é calculado por oficina (OrdemServico::boot() faz
    // max('numero') escopado por tenant + 1), mas a coluna tinha uma constraint
    // UNIQUE global no banco — a primeira OS de qualquer oficina nova calcula
    // numero=1 e colide com o numero=1 de qualquer oficina mais antiga.
    public function test_duas_oficinas_diferentes_podem_ter_os_numero_1(): void
    {
        [$oficinaA, $tokenA, $clienteA] = $this->criarOficinaComAdmin('oficina-num-a', 'a@numero-test.com', '11111111111', '22222222222');
        [$oficinaB, $tokenB, $clienteB] = $this->criarOficinaComAdmin('oficina-num-b', 'b@numero-test.com', '33333333333', '44444444444');

        $respA = $this->withToken($tokenA)->withHeaders(['X-Tenant' => $oficinaA->slug])
            ->postJson('/api/os', ['cliente_id' => $clienteA->id, 'status' => 'ABERTA']);
        $respA->assertStatus(201)->assertJsonPath('data.numero', 1);

        $respB = $this->withToken($tokenB)->withHeaders(['X-Tenant' => $oficinaB->slug])
            ->postJson('/api/os', ['cliente_id' => $clienteB->id, 'status' => 'ABERTA']);
        $respB->assertStatus(201)->assertJsonPath('data.numero', 1);
    }
}
