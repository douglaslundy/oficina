<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Oficina;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** CPF/CNPJ de cliente, SKU e CPF de usuário são únicos por oficina, não no SaaS inteiro. */
class DocumentoUnicoPorOficinaTest extends TestCase
{
    use RefreshDatabase;

    private function oficinaComAdmin(string $sufixo): array
    {
        $oficina = Oficina::create([
            'nome' => "Oficina {$sufixo}", 'slug' => "of-{$sufixo}-" . uniqid(),
            'cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'status' => 'ATIVA',
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);
        $admin = Usuario::create([
            'nome' => 'Admin', 'email' => "admin-{$sufixo}@" . uniqid() . '.com', 'cpf' => (string) mt_rand(10000000000, 99999999999),
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('Senha123'),
        ]);
        TenancyContext::clear();

        return [$oficina, $admin->createToken('t')->plainTextToken];
    }

    /** Faz a mesma requisição na oficina A (2x) e na B: A repetida = 422, B = 201. */
    private function assertUnicoSoNaOficina(string $url, callable $payload): void
    {
        [$a, $tokenA] = $this->oficinaComAdmin('a');
        [$b, $tokenB] = $this->oficinaComAdmin('b');

        $this->withToken($tokenA)->withHeaders(['X-Tenant' => $a->slug])->postJson($url, $payload(1))->assertStatus(201);
        $this->withToken($tokenA)->withHeaders(['X-Tenant' => $a->slug])->postJson($url, $payload(2))->assertStatus(422);

        Auth::forgetGuards(); // troca de usuário no mesmo teste
        $this->withToken($tokenB)->withHeaders(['X-Tenant' => $b->slug])->postJson($url, $payload(3))->assertStatus(201);
    }

    public function test_cliente_com_mesmo_cpf_pode_existir_em_outra_oficina(): void
    {
        $this->assertUnicoSoNaOficina('/api/clientes', fn () => ['nome' => 'João', 'cpf_cnpj' => '52998224725']);
    }

    public function test_produto_com_mesmo_sku_pode_existir_em_outra_oficina(): void
    {
        $this->assertUnicoSoNaOficina('/api/produtos', fn () => ['nome' => 'Filtro', 'sku' => 'FLT01', 'categoria' => 'Filtros']);
    }

    public function test_usuario_com_mesmo_cpf_pode_existir_em_outra_oficina(): void
    {
        // E-mail segue único global (login por e-mail): cada chamada usa um e-mail novo.
        $this->assertUnicoSoNaOficina('/api/usuarios', fn (int $n) => [
            'nome' => 'Mecânico', 'email' => "mec{$n}@" . uniqid() . '.com', 'cpf' => '52998224725',
            'role' => 'MECANICO', 'senha' => 'Senha123',
        ]);
    }
}
