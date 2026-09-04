<?php
declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Models\Oficina;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotaFiscalCancelamentoProvedorTest extends TestCase
{
    use RefreshDatabase;

    private function montarCenario(): array
    {
        $oficina = $this->criarOficina(['provedor_fiscal' => 'FOCUS']);
        Configuracao::create(['oficina_id' => $oficina->id, 'ambiente_fiscal' => 'HOMOLOGACAO']);
        $admin = Usuario::create([
            'nome' => 'Admin', 'email' => 'a@t.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('pass'),
            'oficina_id' => $oficina->id,
        ]);
        $cliente = Cliente::create(['nome' => 'Cliente', 'cpf_cnpj' => '87748248800', 'oficina_id' => $oficina->id]);
        $nota = NotaFiscal::create([
            'cliente_id' => $cliente->id, 'oficina_id' => $oficina->id,
            'modelo' => 'NFS-e', 'natureza_operacao' => 'Prestação de Serviços',
            'subtotal' => 100, 'valor_total' => 100, 'status' => 'AUTORIZADA',
            'provedor' => 'FOCUS', 'referencia_externa' => 'nf-abc',
        ]);
        $token = $admin->createToken('t')->plainTextToken;
        return [$token, $oficina, $nota];
    }

    private function req(string $token, Oficina $oficina)
    {
        return $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug]);
    }

    public function test_cancelamento_de_nfse_focus_chama_a_api_do_provedor(): void
    {
        [$token, $oficina, $nota] = $this->montarCenario();
        Http::fake(['*/v2/nfse/nf-abc' => Http::response([], 200)]);

        $this->req($token, $oficina)
            ->postJson("/api/notas-fiscais/{$nota->id}/cancelar", ['motivo' => 'Erro no valor lancado na nota'])
            ->assertStatus(200);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v2/nfse/nf-abc') && $r->method() === 'DELETE');
        $this->assertDatabaseHas('notas_fiscais', ['id' => $nota->id, 'status' => 'CANCELADA']);
    }

    public function test_falha_no_provedor_nao_marca_cancelada_local(): void
    {
        [$token, $oficina, $nota] = $this->montarCenario();
        Http::fake(['*/v2/nfse/nf-abc' => Http::response(['mensagem' => 'Prazo de cancelamento expirado'], 422)]);

        $this->req($token, $oficina)
            ->postJson("/api/notas-fiscais/{$nota->id}/cancelar", ['motivo' => 'Erro no valor lancado na nota'])
            ->assertStatus(422);

        $this->assertDatabaseHas('notas_fiscais', ['id' => $nota->id, 'status' => 'AUTORIZADA']);
    }
}
