<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Oficina;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Falha de segurança real corrigida em 2026-09-14 (auditoria completa do
 * sistema): `InitializeTenancyByHeader` resolvia a oficina só a partir do
 * header `X-Tenant` (enviado pelo CLIENTE), sem nunca conferir se o usuário
 * autenticado (`auth:sanctum`) realmente pertencia a essa oficina — o global
 * scope de `HasTenantScope` confiava cegamente nesse valor. Qualquer usuário
 * autenticado de QUALQUER oficina podia trocar o header pelo slug de outra
 * (slugs aparecem no subdomínio público, triviais de descobrir) e
 * ler/editar/apagar dados de qualquer oficina do sistema.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficinaComUsuarioECliente(string $sufixo): array
    {
        $oficina = Oficina::create([
            'nome' => "Oficina {$sufixo}", 'cnpj' => (string) mt_rand(10000000000000, 99999999999999),
            'slug' => 'oficina-' . $sufixo . '-' . uniqid(), 'status' => 'ATIVA',
        ]);
        $usuario = Usuario::create([
            'nome' => "Usuario {$sufixo}", 'email' => "user{$sufixo}@" . uniqid() . '.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('senha123'),
            'oficina_id' => $oficina->id,
        ]);

        TenancyContext::set($oficina->id, $oficina->slug);
        $cliente = Cliente::create(['nome' => "Cliente {$sufixo}", 'cpf_cnpj' => (string) mt_rand(10000000000, 99999999999)]);
        TenancyContext::clear();

        return [$oficina, $usuario, $cliente];
    }

    public function test_usuario_nao_consegue_ler_dados_de_outra_oficina_trocando_o_header(): void
    {
        [, $usuarioA] = $this->criarOficinaComUsuarioECliente('a');
        [$oficinaB, , $clienteB] = $this->criarOficinaComUsuarioECliente('b');

        // Usuário autenticado da oficina A manda o X-Tenant da oficina B.
        $response = $this->withHeaders(['X-Tenant' => $oficinaB->slug])
            ->actingAs($usuarioA)
            ->getJson("/api/clientes/{$clienteB->id}");

        $response->assertStatus(403);
    }

    public function test_usuario_nao_consegue_listar_clientes_de_outra_oficina_trocando_o_header(): void
    {
        [, $usuarioA] = $this->criarOficinaComUsuarioECliente('a');
        [$oficinaB] = $this->criarOficinaComUsuarioECliente('b');

        $response = $this->withHeaders(['X-Tenant' => $oficinaB->slug])
            ->actingAs($usuarioA)
            ->getJson('/api/clientes');

        $response->assertStatus(403);
    }

    public function test_usuario_acessa_normalmente_com_o_proprio_tenant(): void
    {
        [$oficinaA, $usuarioA, $clienteA] = $this->criarOficinaComUsuarioECliente('a');

        $response = $this->withHeaders(['X-Tenant' => $oficinaA->slug])
            ->actingAs($usuarioA)
            ->getJson("/api/clientes/{$clienteA->id}");

        $response->assertStatus(200);
    }

    public function test_usuario_sem_header_x_tenant_nenhum_e_bloqueado(): void
    {
        // Antes do fix: sem header, TenancyContext::has() era false e o
        // scope global não filtrava NADA — a rota respondia normalmente
        // devolvendo dados sem filtro de oficina nenhum. Precisa falhar
        // fechado também neste caso, não só no de header ERRADO.
        [, $usuarioA] = $this->criarOficinaComUsuarioECliente('a');

        $response = $this->actingAs($usuarioA)->getJson('/api/clientes');

        $response->assertStatus(403);
    }
}
