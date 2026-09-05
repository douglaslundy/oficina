<?php
declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Models\NotaEntrada;
use App\Models\Oficina;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ConciliacaoFiscalEntradaNfTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(): array
    {
        $oficina = Oficina::create([
            'nome' => 'Oficina Teste', 'slug' => 'oficina-teste',
            'cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'status' => 'ATIVA',
        ]);
        $user = Usuario::create([
            'nome' => 'Admin', 'email' => 'admin@test.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('admin123'),
            'oficina_id' => $oficina->id,
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);
        return [$user->createToken('t')->plainTextToken, $oficina];
    }

    protected function tearDown(): void
    {
        TenancyContext::clear();
        parent::tearDown();
    }

    public function test_conciliar_uma_nota_despacha_o_job(): void
    {
        Bus::fake();
        [$token, $oficina] = $this->loginAdmin();
        $nota = NotaEntrada::create(['oficina_id' => $oficina->id, 'chave_acesso' => str_repeat('1', 44), 'valor_total' => 10]);

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/entradas-nf/{$nota->id}/conciliar")
            ->assertStatus(202);

        Bus::assertDispatched(\App\Jobs\ConciliarFiscalNotaEntradaJob::class);
    }

    public function test_conciliar_pendentes_despacha_1_job_por_nota_elegivel(): void
    {
        Bus::fake();
        [$token, $oficina] = $this->loginAdmin();
        NotaEntrada::create(['oficina_id' => $oficina->id, 'chave_acesso' => str_repeat('1', 44), 'valor_total' => 10]);
        NotaEntrada::create(['oficina_id' => $oficina->id, 'chave_acesso' => str_repeat('2', 44), 'valor_total' => 10, 'fiscal_conferida_em' => now()]);
        NotaEntrada::create(['oficina_id' => $oficina->id, 'chave_acesso' => null, 'valor_total' => 10]);

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf/conciliar-pendentes')
            ->assertStatus(202)
            ->assertJsonPath('notas_enfileiradas', 1);

        Bus::assertDispatchedTimes(\App\Jobs\ConciliarFiscalNotaEntradaJob::class, 1);
    }
}
