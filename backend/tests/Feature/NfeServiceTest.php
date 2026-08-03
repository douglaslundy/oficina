<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Configuracao;
use App\Services\NfeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NfeServiceTest extends TestCase
{
    use RefreshDatabase;

    private NfeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(NfeService::class);

        // Garantir que exista uma configuração base
        Configuracao::create([
            'razao_social'        => 'Oficina Teste Ltda',
            'cnpj'                => '12.345.678/0001-90',
            'proximo_numero_nf'   => 1,
            'ambiente_fiscal'     => 'HOMOLOGACAO',
            'serie_nf'            => '001',
            'aliquota_iss'        => 5.00,
            'estoque_limite_padrao' => 5,
            'alertas_email'       => false,
        ]);
    }

    public function test_proximo_numero_incrementa(): void
    {
        $primeiro  = $this->service->proximoNumeroNf();
        $segundo   = $this->service->proximoNumeroNf();

        $this->assertSame(1, $primeiro);
        $this->assertSame(2, $segundo);
    }

    public function test_numeros_unicos_concorrentes(): void
    {
        // Simula duas chamadas "simultâneas" rodando em sequência dentro do mesmo processo.
        // Como usamos lockForUpdate() + transaction, cada chamada deve retornar um número único.
        $numeros = [];
        for ($i = 0; $i < 5; $i++) {
            $numeros[] = $this->service->proximoNumeroNf();
        }

        // Todos os números devem ser únicos (sem duplicatas)
        $this->assertCount(5, array_unique($numeros));

        // E devem ser sequenciais começando em 1
        sort($numeros);
        $this->assertSame([1, 2, 3, 4, 5], $numeros);

        // O banco deve refletir o contador correto
        $this->assertSame(6, Configuracao::first()->proximo_numero_nf);
    }

    public function test_proximo_numero_lanca_excecao_sem_configuracao(): void
    {
        // Remove todas as configurações
        Configuracao::query()->delete();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Configurações da empresa não encontradas.');

        $this->service->proximoNumeroNf();
    }

    public function test_montar_nota_data_nfe_inclui_itens(): void
    {
        Configuracao::create([
            'razao_social' => 'Oficina Teste', 'cnpj' => '12.345.678/0001-90',
            'proximo_numero_nf' => 1, 'ambiente_fiscal' => 'HOMOLOGACAO',
            'estoque_limite_padrao' => 5, 'alertas_email' => false,
        ]);
        $cliente = \App\Models\Cliente::create(['nome' => 'Cliente', 'cpf_cnpj' => '87748248800']);
        $nota = \App\Models\NotaFiscal::create([
            'cliente_id' => $cliente->id, 'modelo' => 'NF-e',
            'natureza_operacao' => 'Venda de Mercadoria', 'subtotal' => 90, 'valor_total' => 90,
        ]);
        \App\Models\NotaFiscalItem::create([
            'nota_fiscal_id' => $nota->id, 'descricao' => 'Filtro',
            'ncm' => '84212300', 'cfop' => '5102', 'origem' => 0,
            'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102',
            'quantidade' => 2, 'valor_unitario' => 45,
        ]);

        $data = $this->service->montarNotaData($nota->load('itens'));

        $this->assertSame('NFE', $data->modelo);
        $this->assertCount(1, $data->itens);
        $this->assertSame('84212300', $data->itens[0]['ncm']);
    }
}
