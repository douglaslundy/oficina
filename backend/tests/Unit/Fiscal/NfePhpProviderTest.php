<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Contracts\ConsultaNotaTerceiroProvider;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\NfePhp\MotorNfe;
use App\Services\Fiscal\NfePhp\MotorNfse;
use App\Services\Fiscal\Providers\NfePhpProvider;
use Mockery;
use Tests\TestCase;

/**
 * Usa Tests\TestCase (não PHPUnit\Framework\TestCase puro) só para poder
 * amarrar mocks de MotorNfe/MotorNfse no container via $this->app->instance()
 * — emitir()/consultar()/cancelar() do NfePhpProvider resolvem o motor via
 * app(MotorNfe::class)/app(MotorNfse::class), não por injeção de construtor.
 *
 * Os testes de dispatch (emitir/consultar com modelo NFE) mockam MotorNfe em
 * vez de deixar rodar de verdade: MotorNfe::emitir() consulta
 * Configuracao::first() ANTES do try/catch (bug pré-existente, documentado no
 * próprio MotorNfe.php como "não mexido aqui, fora do escopo desta task" —
 * corrigido em consultar()/cancelar() mas não em emitir()) e este ambiente de
 * dev não tem Postgres disponível (ver memória do projeto: "sem DB/Docker
 * local"), então uma chamada real a Configuracao::first() aqui lançaria um
 * \Error de conexão em vez de devolver um EmissaoResultado::erro() gracioso.
 * Mockar prova exatamente o que a Task 6 precisa provar — que o dispatch por
 * modelo chega no motor certo — sem depender desse bug pré-existente fora de
 * escopo (MotorNfe.php está na lista de arquivos que esta task não deve
 * tocar).
 */
class NfePhpProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function emissorCompleto(): EmissorData
    {
        return new EmissorData(
            cnpj: '12.345.678/0001-99', razaoSocial: 'Oficina Teste Ltda', nomeFantasia: 'Oficina Teste',
            inscricaoEstadual: '123456789', inscricaoMunicipal: '987654321', regimeTributario: 'Simples Nacional',
            email: 'contato@oficina.com', telefone: '11999999999', cep: '01310-100', logradouro: 'Av Paulista',
            numero: '1000', complemento: null, bairro: 'Bela Vista', cidade: 'São Paulo', uf: 'SP',
            codigoIbge: '3550308', cnae: '4520-0/01',
        );
    }

    private function notaNfe(string $ref = 'nfe-1'): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE', tomador: ['nome' => 'Cliente', 'cpf_cnpj' => '12345678000199'],
            descricao: 'Venda', valorServicos: 0.0, aliquotaIss: 0.0, issRetido: false,
            codigoServicoFederal: '', codigoServicoMunicipal: '',
            naturezaOperacao: 'Venda de Mercadoria', referenciaExterna: $ref, modelo: 'NFE',
        );
    }

    private function notaNfse(string $ref = 'nfse-1'): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE', tomador: ['nome' => 'Cliente', 'cpf_cnpj' => '12345678000199'],
            descricao: 'Troca de óleo', valorServicos: 150.0, aliquotaIss: 5.0, issRetido: false,
            codigoServicoFederal: '14.01', codigoServicoMunicipal: '1401',
            naturezaOperacao: 'Prestação de Serviços', referenciaExterna: $ref, modelo: 'NFSE',
        );
    }

    public function test_registrar_emissor_ok_com_dados_completos(): void
    {
        $p = new NfePhpProvider('HOMOLOGACAO');
        $r = $p->registrarEmissor($this->emissorCompleto());

        $this->assertSame('REGISTRADO', $r->status);
    }

    public function test_registrar_emissor_erro_com_cnae_vazio(): void
    {
        $incompleto = new EmissorData(
            cnpj: '12.345.678/0001-99', razaoSocial: 'Oficina Teste Ltda', nomeFantasia: null,
            inscricaoEstadual: '123456789', inscricaoMunicipal: '987654321', regimeTributario: 'Simples Nacional',
            email: 'contato@oficina.com', telefone: null, cep: '01310-100', logradouro: 'Av Paulista',
            numero: '1000', complemento: null, bairro: 'Bela Vista', cidade: 'São Paulo', uf: 'SP',
            codigoIbge: '3550308', cnae: '',
        );

        $p = new NfePhpProvider('HOMOLOGACAO');
        $r = $p->registrarEmissor($incompleto);

        $this->assertSame('ERRO', $r->status);
        $this->assertStringContainsString('CNAE', $r->mensagemErro);
    }

    public function test_emitir_com_modelo_nfe_despacha_para_motor_nfe_nao_para_rejeicao_fixa(): void
    {
        // Antes desta task, emitir(modelo=NFE) sempre retornava REJEITADA
        // com uma mensagem fixa de "ainda não disponível". Este teste
        // documenta que esse comportamento antigo foi removido — o
        // dispatch chega até MotorNfe::emitir(), que é quem decide o
        // resultado real (não testado aqui, ver MotorNfeEmitirTest).
        $nota = $this->notaNfe();
        $esperado = EmissaoResultado::erro('Configurações da empresa não encontradas.', $nota->referenciaExterna);

        $mock = Mockery::mock(MotorNfe::class);
        $mock->shouldReceive('emitir')->once()->with($nota, 'HOMOLOGACAO')->andReturn($esperado);
        $this->app->instance(MotorNfe::class, $mock);

        $provider = new NfePhpProvider('HOMOLOGACAO');
        $resultado = $provider->emitir($nota);

        $this->assertNotSame(
            'Emissão de NF-e pelo motor NFePHP ainda não disponível neste sistema. Use Focus NFe ou aguarde uma etapa futura.',
            $resultado->mensagemErro,
        );
        $this->assertSame($esperado, $resultado);
    }

    public function test_emitir_com_modelo_nfse_continua_despachando_para_motor_nfse(): void
    {
        $nota = $this->notaNfse();
        $esperado = EmissaoResultado::processando($nota->referenciaExterna);

        $mock = Mockery::mock(MotorNfse::class);
        $mock->shouldReceive('emitir')->once()->with($nota, 'HOMOLOGACAO')->andReturn($esperado);
        $this->app->instance(MotorNfse::class, $mock);

        $provider = new NfePhpProvider('HOMOLOGACAO');
        $resultado = $provider->emitir($nota);

        $this->assertSame($esperado, $resultado);
    }

    public function test_consultar_com_modelo_nfe_despacha_para_motor_nfe(): void
    {
        $chave = str_repeat('1', 44);
        $esperado = EmissaoResultado::autorizada($chave, '135000000000000', null, null, null, $chave);

        $mock = Mockery::mock(MotorNfe::class);
        $mock->shouldReceive('consultar')->once()->with($chave, 'HOMOLOGACAO')->andReturn($esperado);
        $this->app->instance(MotorNfe::class, $mock);

        $provider = new NfePhpProvider('HOMOLOGACAO');
        $resultado = $provider->consultar($chave, 'NFE');

        $this->assertSame($esperado, $resultado);
    }

    public function test_consultar_sem_modelo_usa_default_nfse(): void
    {
        $ref = 'nfse-77';
        $esperado = EmissaoResultado::autorizada(null, null, $ref, null, null, $ref);

        $mock = Mockery::mock(MotorNfse::class);
        $mock->shouldReceive('consultar')->once()->with($ref, 'HOMOLOGACAO')->andReturn($esperado);
        $this->app->instance(MotorNfse::class, $mock);

        $provider = new NfePhpProvider('HOMOLOGACAO');
        $resultado = $provider->consultar($ref);

        $this->assertSame($esperado, $resultado);
    }

    public function test_cancelar_modelo_nfe_lanca_excecao_direcionando_para_motor_nfe(): void
    {
        $p = new NfePhpProvider('HOMOLOGACAO');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MotorNfe::cancelar()');

        $p->cancelar(str_repeat('1', 44), 'Erro de digitação', 'NFE');
    }

    public function test_cancelar_sem_modelo_usa_default_nfse(): void
    {
        $ref = 'nfse-9';
        $esperado = EmissaoResultado::cancelada($ref);

        $mock = Mockery::mock(MotorNfse::class);
        $mock->shouldReceive('cancelar')->once()->with($ref, 'Motivo', 'HOMOLOGACAO')->andReturn($esperado);
        $this->app->instance(MotorNfse::class, $mock);

        $provider = new NfePhpProvider('HOMOLOGACAO');
        $resultado = $provider->cancelar($ref, 'Motivo');

        $this->assertSame($esperado, $resultado);
    }

    // --- ConsultaNotaTerceiroProvider (Distribuição DFe) -------------------
    //
    // Consulta de NF-e de TERCEIRO (nota de entrada), agora também suportada
    // pelo motor NFePHP — direto na SEFAZ via NFeDistribuicaoDFe, sem
    // provedor intermediário. Aqui só se testa a delegação (o parsing real
    // está em MotorNfeConsultarNotaRecebidaMappingTest /
    // MotorNfeListarNotasRecebidasMappingTest); mockar o motor é obrigatório
    // porque a chamada real precisaria de banco, certificado e rede.

    public function test_provider_implementa_o_contrato_de_consulta_de_nota_de_terceiro(): void
    {
        // Sem isso, EntradaNfController::consultar()/recebidas() barra o
        // motor com "ainda não suporta essa consulta" (checagem por
        // instanceof), por mais que os métodos existam.
        $this->assertInstanceOf(
            ConsultaNotaTerceiroProvider::class,
            new NfePhpProvider('HOMOLOGACAO'),
        );
    }

    public function test_consultar_nota_recebida_despacha_para_motor_nfe_com_o_ambiente(): void
    {
        $chave = str_repeat('7', 44);
        $esperado = ConsultaNotaTerceiroResultado::aguardandoManifestacao();

        $mock = Mockery::mock(MotorNfe::class);
        $mock->shouldReceive('consultarNotaRecebida')->once()->with($chave, 'PRODUCAO')->andReturn($esperado);
        $this->app->instance(MotorNfe::class, $mock);

        $provider = new NfePhpProvider('PRODUCAO');

        $this->assertSame($esperado, $provider->consultarNotaRecebida($chave));
    }

    public function test_listar_notas_recebidas_despacha_para_motor_nfe_com_o_ambiente(): void
    {
        $esperado = [new ConsultaNotaTerceiroResumo(
            chaveAcesso: str_repeat('8', 44),
            fornecedorNome: 'Fornecedor',
            fornecedorCnpj: '12345678000199',
            dataEmissao: '2026-08-01',
            valorTotal: 10.5,
            completa: false,
        )];

        $mock = Mockery::mock(MotorNfe::class);
        $mock->shouldReceive('listarNotasRecebidas')->once()->with('12345678000199', 'HOMOLOGACAO')->andReturn($esperado);
        $this->app->instance(MotorNfe::class, $mock);

        $provider = new NfePhpProvider('HOMOLOGACAO');

        // $desde é aceito pela interface mas ignorado: sefazDistDFe() só
        // ordena por NSU incremental, não filtra por data de emissão —
        // mesma limitação já documentada em FocusNfeProvider.
        $this->assertSame($esperado, $provider->listarNotasRecebidas('12345678000199', new \DateTimeImmutable('2026-01-01')));
    }

    public function test_falha_ao_listar_propaga_excecao_em_vez_de_lista_vazia(): void
    {
        // Contrato de ConsultaNotaTerceiroProvider: falha do provedor nunca
        // pode virar `[]`, senão a tela mostra "nenhuma nota recebida" pra
        // um erro real (ver EntradaNfController::recebidas()).
        $mock = Mockery::mock(MotorNfe::class);
        $mock->shouldReceive('listarNotasRecebidas')->once()
            ->andThrow(new \RuntimeException('Falha ao consultar notas recebidas na SEFAZ: certificado vencido'));
        $this->app->instance(MotorNfe::class, $mock);

        $provider = new NfePhpProvider('HOMOLOGACAO');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('certificado vencido');

        $provider->listarNotasRecebidas('12345678000199');
    }
}
