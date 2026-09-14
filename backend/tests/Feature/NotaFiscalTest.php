<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\NotaFiscal;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotaFiscalTest extends TestCase
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

    private function criarCliente(): Cliente
    {
        return Cliente::create([
            'nome'     => 'Cliente Teste',
            'cpf_cnpj' => '87748248800',
            'status'   => 'REGULAR',
        ]);
    }

    public function test_criar_nota_fiscal_rascunho(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Prestação de Serviços',
            'subtotal'          => 500.00,
            'desconto'          => 0,
            'aliquota_iss'      => 5.00,
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', 'RASCUNHO');
    }

    /**
     * Pedido explícito do usuário (2026-09-14): botão de excluir nota
     * fiscal, permitido SOMENTE pra notas de homologação — nunca produção
     * (documento fiscal real, mesmo cancelada precisa manter o registro).
     */
    public function test_excluir_nota_fiscal_de_homologacao(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();
        $nota = NotaFiscal::create([
            'cliente_id' => $cliente->id, 'modelo' => 'NFS-e', 'natureza_operacao' => 'Prestação de Serviços',
            'subtotal' => 100, 'valor_total' => 100, 'status' => 'AUTORIZADA', 'ambiente' => 'HOMOLOGACAO',
        ]);

        $response = $this->withToken($token)->deleteJson("/api/notas-fiscais/{$nota->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('notas_fiscais', ['id' => $nota->id]);
    }

    public function test_nao_permite_excluir_nota_fiscal_de_producao(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();
        $nota = NotaFiscal::create([
            'cliente_id' => $cliente->id, 'modelo' => 'NFS-e', 'natureza_operacao' => 'Prestação de Serviços',
            'subtotal' => 100, 'valor_total' => 100, 'status' => 'AUTORIZADA', 'ambiente' => 'PRODUCAO',
        ]);

        $response = $this->withToken($token)->deleteJson("/api/notas-fiscais/{$nota->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('notas_fiscais', ['id' => $nota->id]);
    }

    /**
     * Pedido explícito do usuário (2026-09-14): botão pra tentar autorizar
     * uma NF-e presa em CONTINGÊNCIA na hora, sem esperar a varredura
     * agendada `nfe:reconciliar-contingencia` (roda de hora em hora).
     */
    public function test_retransmitir_nota_em_contingencia_com_sucesso(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();
        $nota = NotaFiscal::create([
            'cliente_id' => $cliente->id, 'modelo' => 'NF-e', 'provedor' => 'NFEPHP',
            'natureza_operacao' => 'Venda de Mercadoria', 'subtotal' => 100, 'valor_total' => 100,
            'status' => 'CONTINGENCIA', 'ambiente' => 'HOMOLOGACAO',
            'chave_acesso' => '31260950388509000121550010000000014082390387',
            'xml_retorno' => '<NFe/>', 'contingencia_desde' => now()->subHours(2),
        ]);

        $this->mock(\App\Services\Fiscal\NfePhp\MotorNfe::class, function ($m) {
            $m->shouldReceive('retransmitir')->once()
              ->andReturn(\App\Services\Fiscal\Data\EmissaoResultado::autorizada(
                  '31260950388509000121550010000000014082390387', 'PROT123', '1', '<NFe>autorizada</NFe>', null
              ));
        });

        $response = $this->withToken($token)->postJson("/api/notas-fiscais/{$nota->id}/retransmitir");

        $response->assertStatus(200)->assertJsonPath('data.status', 'AUTORIZADA');
        $this->assertDatabaseHas('notas_fiscais', ['id' => $nota->id, 'status' => 'AUTORIZADA', 'protocolo' => 'PROT123']);
        $this->assertNull(NotaFiscal::find($nota->id)->contingencia_desde);
    }

    public function test_retransmitir_falha_preserva_contingencia_desde_para_nao_perder_prazo_epec(): void
    {
        $token         = $this->loginAdmin();
        $cliente       = $this->criarCliente();
        $desdeOriginal = now()->subHours(5);
        $nota = NotaFiscal::create([
            'cliente_id' => $cliente->id, 'modelo' => 'NF-e', 'provedor' => 'NFEPHP',
            'natureza_operacao' => 'Venda de Mercadoria', 'subtotal' => 100, 'valor_total' => 100,
            'status' => 'CONTINGENCIA', 'ambiente' => 'HOMOLOGACAO',
            'chave_acesso' => '31260950388509000121550010000000014082390387',
            'xml_retorno' => '<NFe/>', 'contingencia_desde' => $desdeOriginal,
        ]);

        $this->mock(\App\Services\Fiscal\NfePhp\MotorNfe::class, function ($m) {
            $m->shouldReceive('retransmitir')->once()
              ->andReturn(\App\Services\Fiscal\Data\EmissaoResultado::erro('SEFAZ ainda indisponível.'));
        });

        $response = $this->withToken($token)->postJson("/api/notas-fiscais/{$nota->id}/retransmitir");

        $response->assertStatus(200)->assertJsonPath('data.status', 'CONTINGENCIA');
        $notaFresh = NotaFiscal::find($nota->id);
        $this->assertNotNull($notaFresh->contingencia_desde);
        $this->assertEquals($desdeOriginal->timestamp, $notaFresh->contingencia_desde->timestamp);
    }

    public function test_nao_permite_retransmitir_nota_que_nao_esta_em_contingencia(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();
        $nota = NotaFiscal::create([
            'cliente_id' => $cliente->id, 'modelo' => 'NF-e', 'natureza_operacao' => 'Venda de Mercadoria',
            'subtotal' => 100, 'valor_total' => 100, 'status' => 'AUTORIZADA', 'ambiente' => 'HOMOLOGACAO',
        ]);

        $response = $this->withToken($token)->postJson("/api/notas-fiscais/{$nota->id}/retransmitir");

        $response->assertStatus(422);
    }

    /**
     * Pedido explícito do usuário (2026-09-14): botão de baixar o XML da
     * nota (até aqui só existia baixar PDF).
     */
    public function test_baixar_xml_de_nota_autorizada(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();
        $nota = NotaFiscal::create([
            'cliente_id' => $cliente->id, 'modelo' => 'NF-e', 'natureza_operacao' => 'Venda de Mercadoria',
            'subtotal' => 100, 'valor_total' => 100, 'status' => 'AUTORIZADA', 'ambiente' => 'HOMOLOGACAO',
            'numero' => 5, 'xml_retorno' => '<NFe>conteudo</NFe>',
        ]);

        $response = $this->withToken($token)->get("/api/notas-fiscais/{$nota->id}/xml");

        $response->assertStatus(200);
        $this->assertSame('application/xml', $response->headers->get('content-type'));
        $this->assertStringContainsString('NFe-5.xml', $response->headers->get('content-disposition'));
        $this->assertSame('<NFe>conteudo</NFe>', $response->getContent());
    }

    public function test_baixar_xml_de_nota_sem_xml_salvo_retorna_404(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();
        $nota = NotaFiscal::create([
            'cliente_id' => $cliente->id, 'modelo' => 'NF-e', 'natureza_operacao' => 'Venda de Mercadoria',
            'subtotal' => 100, 'valor_total' => 100, 'status' => 'RASCUNHO', 'ambiente' => 'HOMOLOGACAO',
        ]);

        $this->withToken($token)->get("/api/notas-fiscais/{$nota->id}/xml")->assertStatus(404);
    }

    public function test_listar_notas_fiscais(): void
    {
        $token = $this->loginAdmin();
        $response = $this->withToken($token)->getJson('/api/notas-fiscais');
        $response->assertStatus(200)
                 ->assertJsonStructure(['data', 'meta']);
    }

    public function test_cancelar_nota_fiscal(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        $nf = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Prestação de Serviços',
            'subtotal'          => 300.00,
        ])->json('data');

        NotaFiscal::find($nf['id'])->update(['status' => 'AUTORIZADA']);

        $response = $this->withToken($token)->postJson("/api/notas-fiscais/{$nf['id']}/cancelar", [
            'motivo' => 'Cancelamento de teste para verificação do sistema',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('notas_fiscais', ['id' => $nf['id'], 'status' => 'CANCELADA']);
    }

    public function test_rejeitar_cancelamento_sem_motivo(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        $nf = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Prestação de Serviços',
            'subtotal'          => 300.00,
        ])->json('data');

        $response = $this->withToken($token)->postJson("/api/notas-fiscais/{$nf['id']}/cancelar", [
            'motivo' => 'curto',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['motivo']);
    }
}
