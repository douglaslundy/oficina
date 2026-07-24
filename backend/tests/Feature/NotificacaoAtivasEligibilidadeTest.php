<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notificacao;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificacaoAtivasEligibilidadeTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficinaComUsuario(): array
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA',
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);
        $usuario = Usuario::create([
            'nome' => 'Fulano', 'email' => 'fulano@' . uniqid() . '.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('senha123'),
        ]);
        TenancyContext::clear();
        return [$oficina, $usuario];
    }

    private function comoTenant(Oficina $oficina, Usuario $usuario): static
    {
        return $this->withHeaders(['X-Tenant' => $oficina->slug])->actingAs($usuario);
    }

    public function test_notificacao_some_apos_atingir_vezes_dia(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario();
        $notificacao = Notificacao::create([
            'titulo' => 'Aviso', 'texto' => 'Texto', 'alvo_tipo' => 'TODOS',
            'vezes_dia' => 1, 'intervalo_minutos' => 60, 'ativo' => true,
        ]);

        $this->comoTenant($oficina, $usuario)->getJson('/api/notificacoes/ativas')
            ->assertStatus(200)->assertJsonCount(1, 'data');

        $this->comoTenant($oficina, $usuario)->postJson("/api/notificacoes/{$notificacao->id}/visualizar")
            ->assertStatus(201);

        $this->comoTenant($oficina, $usuario)->getJson('/api/notificacoes/ativas')
            ->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_notificacao_respeita_intervalo_minutos_entre_exibicoes(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario();
        $notificacao = Notificacao::create([
            'titulo' => 'Aviso', 'texto' => 'Texto', 'alvo_tipo' => 'TODOS',
            'vezes_dia' => 5, 'intervalo_minutos' => 60, 'ativo' => true,
        ]);

        $this->comoTenant($oficina, $usuario)->postJson("/api/notificacoes/{$notificacao->id}/visualizar")
            ->assertStatus(201);

        $this->comoTenant($oficina, $usuario)->getJson('/api/notificacoes/ativas')
            ->assertStatus(200)->assertJsonCount(0, 'data');

        $this->travel(61)->minutes();

        $this->comoTenant($oficina, $usuario)->getJson('/api/notificacoes/ativas')
            ->assertStatus(200)->assertJsonCount(1, 'data');
    }
}
