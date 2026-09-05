<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Models\Cliente;
use App\Models\NotaFiscal;
use App\Services\NfeService;
use PHPUnit\Framework\TestCase;

class NfeServiceMontagemTest extends TestCase
{
    public function test_monta_nota_data_a_partir_da_nota(): void
    {
        $cliente = new Cliente([
            'nome' => 'Fulano', 'cpf_cnpj' => '12345678000199',
            'email' => 'f@x.com', 'cep' => '01310100', 'endereco' => 'Av A',
            'bairro' => 'Centro', 'cidade' => 'São Paulo', 'uf' => 'SP',
        ]);
        $nota = new NotaFiscal([
            'valor_total' => 150.0, 'aliquota_iss' => 5.0,
            'natureza_operacao' => 'Prestação de Serviços',
            'observacoes' => 'Troca de óleo', 'referencia_externa' => 'nf-abc',
        ]);
        $nota->setRelation('cliente', $cliente);

        $service = new NfeService();
        $data = $service->montarNotaData($nota, codigoServicoFederal: '14.01', codigoServicoMunicipal: '1401', codigoIbgeTomador: '3550308');

        $this->assertSame('NFSE', $data->tipo);
        $this->assertSame(150.0, $data->valorServicos);
        $this->assertSame(5.0, $data->aliquotaIss);
        $this->assertSame('nf-abc', $data->referenciaExterna);
        $this->assertSame('12345678000199', $data->tomador['cpf_cnpj']);
        $this->assertNull($data->numeroReservado);
    }

    /**
     * Finding 4 do fix wave pós-revisão da Etapa C2 (2026-08-11): uma
     * NotaFiscal NF-e/NFEPHP que já tem `numero` persistido (de uma
     * tentativa anterior rejeitada/com erro — ver
     * NotaFiscalController::emitir() e MotorNfe::emitir()) é uma
     * retentativa, não uma primeira emissão. montarNotaData() precisa
     * detectar isso e popular numeroReservado, senão MotorNfe::emitir()
     * aloca um número NOVO a cada retentativa e o antigo fica perdido pra
     * sempre (spec Seção B, "nota rejeitada não queima o número").
     */
    public function test_monta_nota_data_detecta_numero_reservado_em_retentativa_nfephp(): void
    {
        $cliente = new Cliente([
            'nome' => 'Fulano', 'cpf_cnpj' => '12345678000199',
            'email' => 'f@x.com', 'cep' => '01310100', 'endereco' => 'Av A',
            'bairro' => 'Centro', 'cidade' => 'São Paulo', 'uf' => 'SP',
        ]);
        $nota = new NotaFiscal([
            'valor_total' => 150.0, 'aliquota_iss' => 5.0,
            'natureza_operacao' => 'Prestação de Serviços',
            'observacoes' => 'Troca de óleo', 'referencia_externa' => 'nf-retry',
            'modelo' => 'NF-e', 'provedor' => 'NFEPHP', 'numero' => 7,
        ]);
        $nota->setRelation('cliente', $cliente);
        // modelo NF-e faz montarNotaData() acessar $nota->itens — sem
        // setRelation() aqui, o Eloquent tentaria lazy-load via DB (mesmo
        // padrão evitado em DanfeRendererTest.php).
        $nota->setRelation('itens', collect());

        $service = new NfeService();
        $data = $service->montarNotaData($nota, codigoServicoFederal: '14.01', codigoServicoMunicipal: '1401', codigoIbgeTomador: '3550308');

        $this->assertSame('7', $data->numeroReservado);
    }

    /**
     * Contraprova do teste acima: um `numero` vindo de Spedy/Focus (qualquer
     * provedor != NFEPHP) NÃO significa "reservado pra reenviar" — esses
     * provedores atribuem o número deles mesmos, não o contador
     * proximo_numero_nfe que numeroReservado existe pra proteger.
     */
    public function test_monta_nota_data_nao_reserva_numero_para_outros_provedores(): void
    {
        $cliente = new Cliente([
            'nome' => 'Fulano', 'cpf_cnpj' => '12345678000199',
            'email' => 'f@x.com', 'cep' => '01310100', 'endereco' => 'Av A',
            'bairro' => 'Centro', 'cidade' => 'São Paulo', 'uf' => 'SP',
        ]);
        $nota = new NotaFiscal([
            'valor_total' => 150.0, 'aliquota_iss' => 5.0,
            'natureza_operacao' => 'Prestação de Serviços',
            'observacoes' => 'Troca de óleo', 'referencia_externa' => 'nf-focus',
            'modelo' => 'NF-e', 'provedor' => 'FOCUS', 'numero' => 9,
        ]);
        $nota->setRelation('cliente', $cliente);
        $nota->setRelation('itens', collect());

        $service = new NfeService();
        $data = $service->montarNotaData($nota, codigoServicoFederal: '14.01', codigoServicoMunicipal: '1401', codigoIbgeTomador: '3550308');

        $this->assertNull($data->numeroReservado);
    }

    public function test_monta_nota_data_inclui_sku_e_unidade_do_item(): void
    {
        $cliente = new Cliente(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800']);
        $nota = new NotaFiscal([
            'modelo' => 'NF-e', 'valor_total' => 90.0,
            'natureza_operacao' => 'Venda de Mercadoria', 'referencia_externa' => 'nf-x',
        ]);
        $nota->setRelation('cliente', $cliente);
        $item = new \App\Models\NotaFiscalItem([
            'produto_id' => 'prod-uuid', 'sku' => 'FLT-001', 'descricao' => 'Filtro',
            'unidade' => 'Par', 'ncm' => '84212300', 'cfop' => '5102',
            'origem' => 0, 'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102',
            'quantidade' => 2, 'valor_unitario' => 45,
        ]);
        $nota->setRelation('itens', collect([$item]));

        $data = (new NfeService())->montarNotaData($nota);

        $this->assertSame('FLT-001', $data->itens[0]['sku']);
        $this->assertSame('PAR', $data->itens[0]['unidade']);
    }

    public function test_monta_nota_data_inclui_regime_tributario_da_configuracao(): void
    {
        $cliente = new Cliente(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800']);
        $nota = new NotaFiscal([
            'modelo' => 'NF-e', 'valor_total' => 90.0,
            'natureza_operacao' => 'Venda de Mercadoria', 'referencia_externa' => 'nf-y',
        ]);
        $nota->setRelation('cliente', $cliente);
        $nota->setRelation('itens', collect());
        $config = new \App\Models\Configuracao(['regime_tributario' => 'Simples Nacional']);

        $data = (new NfeService())->montarNotaData($nota, config: $config);

        $this->assertSame('Simples Nacional', $data->regimeTributario);
    }

    public function test_monta_nota_data_inclui_calculo_tributario_modo_da_configuracao(): void
    {
        $cliente = new Cliente(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800']);
        $nota = new NotaFiscal([
            'modelo' => 'NF-e', 'valor_total' => 90.0,
            'natureza_operacao' => 'Venda de Mercadoria', 'referencia_externa' => 'nf-modo',
        ]);
        $nota->setRelation('cliente', $cliente);
        $nota->setRelation('itens', collect());
        $config = new \App\Models\Configuracao(['calculo_tributario_modo' => 'AUTOMATICO_PROVEDOR']);

        $data = (new NfeService())->montarNotaData($nota, config: $config);

        $this->assertSame('AUTOMATICO_PROVEDOR', $data->calculoTributarioModo);
    }

    public function test_monta_nota_data_sem_config_calculo_tributario_modo_fica_manual(): void
    {
        $cliente = new Cliente(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800']);
        $nota = new NotaFiscal([
            'modelo' => 'NFS-e', 'valor_total' => 90.0,
            'natureza_operacao' => 'Prestação de Serviços', 'referencia_externa' => 'nf-modo2',
        ]);
        $nota->setRelation('cliente', $cliente);

        $data = (new NfeService())->montarNotaData($nota);

        $this->assertSame('MANUAL', $data->calculoTributarioModo);
    }

    public function test_monta_nota_data_sem_config_regime_tributario_fica_vazio(): void
    {
        $cliente = new Cliente(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800']);
        $nota = new NotaFiscal([
            'modelo' => 'NFS-e', 'valor_total' => 90.0,
            'natureza_operacao' => 'Prestação de Serviços', 'referencia_externa' => 'nf-z',
        ]);
        $nota->setRelation('cliente', $cliente);

        $data = (new NfeService())->montarNotaData($nota);

        $this->assertSame('', $data->regimeTributario);
    }

    public function test_monta_nota_data_item_sem_sku_ou_unidade_usa_fallback(): void
    {
        $cliente = new Cliente(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800']);
        $nota = new NotaFiscal([
            'modelo' => 'NF-e', 'valor_total' => 10.0,
            'natureza_operacao' => 'Venda de Mercadoria', 'referencia_externa' => 'nf-y',
        ]);
        $nota->setRelation('cliente', $cliente);
        $item = new \App\Models\NotaFiscalItem([
            'produto_id' => 'prod-uuid', 'descricao' => 'Item sem cadastro completo',
            'ncm' => '84212300', 'cfop' => '5102', 'origem' => 0,
            'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102',
            'quantidade' => 1, 'valor_unitario' => 10,
        ]);
        $nota->setRelation('itens', collect([$item]));

        $data = (new NfeService())->montarNotaData($nota);

        $this->assertSame('prod-uuid', $data->itens[0]['sku']);
        $this->assertSame('UN', $data->itens[0]['unidade']);
    }

    public function test_monta_nota_data_nfce_usa_modelo_interno_nfce_e_inclui_itens(): void
    {
        $cliente = new Cliente(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800']);
        $nota = new NotaFiscal([
            'modelo' => 'NFC-e', 'valor_total' => 90.0,
            'natureza_operacao' => 'Venda de Mercadoria',
            'forma_pagamento' => 'PIX', 'referencia_externa' => 'nfce-abc',
        ]);
        $nota->setRelation('cliente', $cliente);
        $item = new \App\Models\NotaFiscalItem([
            'descricao' => 'Filtro', 'ncm' => '84212300', 'cfop' => '5102',
            'origem' => 0, 'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102',
            'quantidade' => 2, 'valor_unitario' => 45,
        ]);
        $nota->setRelation('itens', collect([$item]));

        $service = new NfeService();
        $data = $service->montarNotaData($nota);

        $this->assertSame('NFCE', $data->modelo);
        $this->assertCount(1, $data->itens);
        $this->assertSame('PIX', $data->formaPagamento);
    }
}
