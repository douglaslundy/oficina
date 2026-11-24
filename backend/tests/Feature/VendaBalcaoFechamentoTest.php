<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Oficina;
use App\Models\OrdemServico;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VendaBalcaoFechamentoTest extends TestCase
{
    use RefreshDatabase;

    private function postVenda(array $extra)
    {
        $oficina = Oficina::create(['nome' => 'Oficina Teste', 'slug' => 'oficina-teste', 'cnpj' => uniqid('c'), 'status' => 'ATIVA']);
        $admin = Usuario::create([
            'nome' => 'Admin', 'email' => 'a@t.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('pass'),
            'oficina_id' => $oficina->id,
        ]);
        $token = $admin->createToken('t')->plainTextToken;

        return $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])->postJson('/api/os', array_merge([
            'tipo'  => 'VENDA_BALCAO',
            'itens' => [['tipo' => 'SERVICO', 'descricao' => 'Item', 'quantidade' => 1, 'valor_unitario' => 50]],
        ], $extra));
    }

    public function test_sem_itens_retorna_422(): void
    {
        $this->postVenda(['itens' => [], 'pagamentos' => [['forma_pagamento' => 'PIX', 'valor' => 50]]])->assertStatus(422);
        $this->assertSame(0, OrdemServico::count());
    }

    public function test_desconto_que_zera_a_venda_retorna_422(): void
    {
        $this->postVenda(['desconto' => 50, 'pagamentos' => [['forma_pagamento' => 'PIX', 'valor' => 50]]])->assertStatus(422);
        $this->assertSame(0, OrdemServico::count());
    }

    public function test_sem_pagamento_a_vista_retorna_422(): void
    {
        $this->postVenda([])->assertStatus(422);
    }

    public function test_pagamento_parcial_retorna_422(): void
    {
        $this->postVenda(['pagamentos' => [['forma_pagamento' => 'PIX', 'valor' => 40]]])->assertStatus(422);
    }

    public function test_pagamento_parcial_com_desconto_que_fecha_o_total_e_aceito(): void
    {
        $this->postVenda(['desconto' => 10, 'pagamentos' => [['forma_pagamento' => 'PIX', 'valor' => 40]]])
            ->assertStatus(201)
            ->assertJsonPath('data.valor_total', 40)
            ->assertJsonPath('data.valor_pago', 40);
    }

    public function test_excedente_em_cartao_retorna_422(): void
    {
        $this->postVenda(['pagamentos' => [
            ['forma_pagamento' => 'CARTAO_DEBITO', 'valor' => 50],
            ['forma_pagamento' => 'CARTAO_DEBITO', 'valor' => 50],
        ]])->assertStatus(422);
    }

    public function test_troco_em_dinheiro_e_aceito(): void
    {
        $this->postVenda(['pagamentos' => [['forma_pagamento' => 'DINHEIRO', 'valor' => 100]]])
            ->assertStatus(201)
            ->assertJsonPath('data.valor_pago', 50);
        $this->assertEquals(50, (float) \App\Models\OsPagamento::sum('valor'));
    }

    public function test_troco_sai_do_dinheiro_e_cartao_fica_integral(): void
    {
        $this->postVenda(['pagamentos' => [
            ['forma_pagamento' => 'CARTAO_CREDITO', 'valor' => 30],
            ['forma_pagamento' => 'DINHEIRO', 'valor' => 50],
        ]])->assertStatus(201);
        $this->assertEquals(30, (float) \App\Models\OsPagamento::where('forma_pagamento', 'CARTAO_CREDITO')->sum('valor'));
        $this->assertEquals(20, (float) \App\Models\OsPagamento::where('forma_pagamento', 'DINHEIRO')->sum('valor'));
    }

    public function test_a_prazo_sem_pagamento_e_aceito(): void
    {
        $this->postVenda(['venda_a_prazo' => true, 'prazo_pagamento_dias' => 30])
            ->assertStatus(201)
            ->assertJsonPath('data.valor_total', 50);
        $this->assertEquals(0, (float) OrdemServico::first()->valor_pago);
    }
}
