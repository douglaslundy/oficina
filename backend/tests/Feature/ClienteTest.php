<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClienteTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(): string
    {
        $user = Usuario::create([
            'nome'       => 'Admin',
            'email'      => 'admin@test.com',
            'cpf'        => '52998224725',
            'role'       => 'ADMIN',
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('admin123'),
        ]);
        return $user->createToken('test')->plainTextToken;
    }

    public function test_criar_cliente_com_cpf_valido(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/clientes', [
            'nome'     => 'João Silva',
            'cpf_cnpj' => '529.982.247-25',
            'telefone' => '(11) 99999-9999',
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['data' => ['id', 'nome', 'cpf_cnpj']]);
    }

    public function test_rejeitar_cliente_com_cpf_invalido(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/clientes', [
            'nome'     => 'Inválido',
            'cpf_cnpj' => '111.111.111-11',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['cpf_cnpj']);
    }

    public function test_listar_clientes_com_paginacao(): void
    {
        $token = $this->loginAdmin();
        $response = $this->withToken($token)->getJson('/api/clientes');
        $response->assertStatus(200)
                 ->assertJsonStructure(['data', 'meta' => ['total', 'per_page']]);
    }

    /**
     * Bug real de produção (2026-09-14): um CEP com 7 dígitos (faltando um
     * dígito) foi aceito sem validação alguma, e só quebrou muito mais tarde
     * — na emissão de NF-e via Spedy, com um erro genérico e sem relação
     * óbvia ("Erro ao gerar XML da nota fiscal. Verifique os dados e tente
     * novamente."). O CEP nunca tinha validação de formato, só `max:9`
     * (aceitava qualquer string curta). Um CEP brasileiro válido tem sempre
     * 8 dígitos (formato NNNNN-NNN).
     */
    public function test_rejeitar_cliente_com_cep_de_7_digitos(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/clientes', [
            'nome'     => 'Cliente CEP Inválido',
            'cpf_cnpj' => '529.982.247-25',
            'cep'      => '3717500',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['cep']);
    }

    public function test_aceitar_cliente_com_cep_de_8_digitos_ou_formatado(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/clientes', [
            'nome'     => 'Cliente CEP Válido',
            'cpf_cnpj' => '529.982.247-25',
            'cep'      => '37175-000',
        ]);

        $response->assertStatus(201);
    }

    public function test_aceitar_cliente_sem_cep(): void
    {
        // CEP continua opcional (cadastro sem endereço, ex. venda de balcão).
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/clientes', [
            'nome'     => 'Cliente Sem CEP',
            'cpf_cnpj' => '529.982.247-25',
        ]);

        $response->assertStatus(201);
    }
}
