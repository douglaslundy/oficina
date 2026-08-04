<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\NfePhp\MotorNfse;
use Nfse\Enums\OpcaoSimplesNacional;
use Nfse\Enums\RegimeApuracaoSN;
use Nfse\Enums\TipoAmbiente;
use Nfse\Enums\TipoRetencaoIssqn;
use Nfse\Enums\TributacaoIssqn;
use Tests\TestCase;

/**
 * Testa MotorNfse::montarDps() isoladamente — sem I/O, sem rede, sem
 * certificado. Usa Tests\TestCase (não PHPUnit\Framework\TestCase puro, como
 * o brief original sugeria) porque montarDps() chama os helpers now() e
 * config() do Laravel, que exigem o container da aplicação estar de pé;
 * o mesmo padrão já usado por CertificadoStoreTest.php (mesmo diretório)
 * comprovadamente roda neste ambiente sem precisar de Postgres.
 *
 * As asserções abaixo leem os campos de volta pelo nome de propriedade
 * camelCase real de Nfse\Dto\Nfse\InfDpsData (confirmado lendo o vendor
 * depois do composer require, não a partir do README) — não o
 * assertNotNull frouxo que o brief tinha deixado como placeholder.
 */
class MotorNfseMontarDpsTest extends TestCase
{
    private function configuracaoSimplesNacional(): Configuracao
    {
        $cfg = new Configuracao();
        $cfg->cnpj = '12345678000199';
        $cfg->codigo_ibge = '3131307'; // Ilicínea/MG
        $cfg->ambiente_fiscal = 'HOMOLOGACAO';
        $cfg->regime_tributario = 'Simples Nacional';

        return $cfg;
    }

    private function notaServico(): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: ['nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678909', 'email' => 'c@x.com'],
            descricao: 'Troca de óleo',
            valorServicos: 150.00,
            aliquotaIss: 5.0,
            issRetido: false,
            codigoServicoFederal: '14.01',
            codigoServicoMunicipal: '1401',
            naturezaOperacao: 'Prestação de Serviços',
            referenciaExterna: 'nfse-1',
        );
    }

    public function test_monta_dps_com_dados_da_nota_e_configuracao(): void
    {
        $cfg = $this->configuracaoSimplesNacional();
        $nota = $this->notaServico();

        $motor = new MotorNfse();
        $dps = $motor->montarDps($nota, $cfg, 'HOMOLOGACAO');

        $this->assertSame('1.01', $dps->versao);
        $this->assertNotNull($dps->infDps);

        $inf = $dps->infDps;
        $this->assertSame(TipoAmbiente::Homologacao, $inf->tipoAmbiente);
        $this->assertSame('1', $inf->serie);
        $this->assertSame('1', $inf->numeroDps);
        $this->assertStringStartsWith('DPS3131307', $inf->id);
        $this->assertSame((string) $cfg->codigo_ibge, $inf->codigoLocalEmissao);

        // Prestador
        $this->assertSame('12345678000199', $inf->prestador->cnpj);
        $this->assertNotNull($inf->prestador->regimeTributario);
        // Simples Nacional (CrtResolver -> CRT 1) deve virar opSimpNac=ME/EPP,
        // NÃO o valor bruto do CRT (que usa outra escala) — é a correção mais
        // importante feita em cima do brief.
        $this->assertSame(OpcaoSimplesNacional::MeEpp, $inf->prestador->regimeTributario->opcaoSimplesNacional);
        $this->assertSame(RegimeApuracaoSN::SimplesNacional, $inf->prestador->regimeTributario->regimeApuracaoTributosSn);

        // Tomador
        $this->assertSame('12345678909', $inf->tomador->cpf);
        $this->assertNull($inf->tomador->cnpj);
        $this->assertSame('Cliente Teste', $inf->tomador->nome);

        // Serviço
        $this->assertSame('14.01', $inf->servico->codigoServico->codigoTributacaoNacional);
        $this->assertSame('1401', $inf->servico->codigoServico->codigoTributacaoMunicipal);
        $this->assertSame('Troca de óleo', $inf->servico->codigoServico->descricaoServico);
        $this->assertSame((string) $cfg->codigo_ibge, $inf->servico->localPrestacao->codigoLocalPrestacao);

        // Valores / tributação
        $this->assertSame(150.0, $inf->valores->valorServicoPrestado->valorServico);
        $this->assertSame(TributacaoIssqn::OperacaoTributavel, $inf->valores->tributacao->tributacaoIssqn);
        $this->assertSame(TipoRetencaoIssqn::NaoRetido, $inf->valores->tributacao->tipoRetencaoIssqn);
        $this->assertSame(5.0, $inf->valores->tributacao->aliquota);
    }

    public function test_iss_retido_marca_retido_pelo_tomador_nao_nao_retido(): void
    {
        $cfg = $this->configuracaoSimplesNacional();
        $nota = new NotaFiscalData(
            tipo: 'NFSE',
            tomador: ['nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678909'],
            descricao: 'Troca de óleo',
            valorServicos: 150.00,
            aliquotaIss: 5.0,
            issRetido: true,
            codigoServicoFederal: '14.01',
            codigoServicoMunicipal: '1401',
            naturezaOperacao: 'Prestação de Serviços',
            referenciaExterna: 'nfse-2',
        );

        $dps = (new MotorNfse())->montarDps($nota, $cfg, 'HOMOLOGACAO');

        // Regressão da inversão que existia no brief (issRetido true virava
        // "Não Retido" em vez de "Retido pelo Tomador").
        $this->assertSame(TipoRetencaoIssqn::RetidoTomador, $dps->infDps->valores->tributacao->tipoRetencaoIssqn);
    }

    public function test_regime_normal_nao_optante_pelo_simples(): void
    {
        $cfg = $this->configuracaoSimplesNacional();
        $cfg->regime_tributario = 'Lucro Presumido';

        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'HOMOLOGACAO');

        $this->assertSame(OpcaoSimplesNacional::NaoOptante, $dps->infDps->prestador->regimeTributario->opcaoSimplesNacional);
        $this->assertNull($dps->infDps->prestador->regimeTributario->regimeApuracaoTributosSn);
    }

    public function test_ambiente_producao_usa_tpamb_1(): void
    {
        $cfg = $this->configuracaoSimplesNacional();

        // $ambiente agora é um parâmetro explícito de montarDps() (Fix 5 da
        // revisão final) — $cfg->ambiente_fiscal já não é lido para tpAmb.
        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'PRODUCAO');

        $this->assertSame(TipoAmbiente::Producao, $dps->infDps->tipoAmbiente);
    }

    public function test_ambiente_do_parametro_prevalece_sobre_config_divergente(): void
    {
        // Regressão específica do Fix 5: mesmo com $cfg->ambiente_fiscal
        // dizendo PRODUCAO, o parâmetro $ambiente = HOMOLOGACAO deve
        // prevalecer — a config nunca mais deve ser lida aqui dentro.
        $cfg = $this->configuracaoSimplesNacional();
        $cfg->ambiente_fiscal = 'PRODUCAO';

        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'HOMOLOGACAO');

        $this->assertSame(TipoAmbiente::Homologacao, $dps->infDps->tipoAmbiente);
    }
}
