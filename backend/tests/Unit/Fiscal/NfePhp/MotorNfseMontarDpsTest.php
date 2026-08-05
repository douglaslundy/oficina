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
        $dps = $motor->montarDps($nota, $cfg, 'HOMOLOGACAO', 1);

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

        $dps = (new MotorNfse())->montarDps($nota, $cfg, 'HOMOLOGACAO', 1);

        // Regressão da inversão que existia no brief (issRetido true virava
        // "Não Retido" em vez de "Retido pelo Tomador").
        $this->assertSame(TipoRetencaoIssqn::RetidoTomador, $dps->infDps->valores->tributacao->tipoRetencaoIssqn);
    }

    public function test_mei_classifica_opsimpnac_2_em_vez_de_me_epp(): void
    {
        $cfg = $this->configuracaoSimplesNacional();
        $cfg->regime_tributario = 'Simples Nacional - MEI';

        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'HOMOLOGACAO', 1);

        $this->assertSame(OpcaoSimplesNacional::Mei, $dps->infDps->prestador->regimeTributario->opcaoSimplesNacional);
    }

    public function test_simples_nacional_sem_mei_continua_me_epp(): void
    {
        // Regressão: garante que o fix de MEI não alterou o caso comum
        // (Simples Nacional sem MEI continua opSimpNac=3/ME-EPP).
        $dps = (new MotorNfse())->montarDps($this->notaServico(), $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1);

        $this->assertSame(OpcaoSimplesNacional::MeEpp, $dps->infDps->prestador->regimeTributario->opcaoSimplesNacional);
    }

    public function test_regime_normal_nao_optante_pelo_simples(): void
    {
        $cfg = $this->configuracaoSimplesNacional();
        $cfg->regime_tributario = 'Lucro Presumido';

        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'HOMOLOGACAO', 1);

        $this->assertSame(OpcaoSimplesNacional::NaoOptante, $dps->infDps->prestador->regimeTributario->opcaoSimplesNacional);
        $this->assertNull($dps->infDps->prestador->regimeTributario->regimeApuracaoTributosSn);
    }

    public function test_ambiente_producao_usa_tpamb_1(): void
    {
        $cfg = $this->configuracaoSimplesNacional();

        // $ambiente agora é um parâmetro explícito de montarDps() (Fix 5 da
        // revisão final) — $cfg->ambiente_fiscal já não é lido para tpAmb.
        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'PRODUCAO', 1);

        $this->assertSame(TipoAmbiente::Producao, $dps->infDps->tipoAmbiente);
    }

    public function test_ambiente_do_parametro_prevalece_sobre_config_divergente(): void
    {
        // Regressão específica do Fix 5: mesmo com $cfg->ambiente_fiscal
        // dizendo PRODUCAO, o parâmetro $ambiente = HOMOLOGACAO deve
        // prevalecer — a config nunca mais deve ser lida aqui dentro.
        $cfg = $this->configuracaoSimplesNacional();
        $cfg->ambiente_fiscal = 'PRODUCAO';

        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'HOMOLOGACAO', 1);

        $this->assertSame(TipoAmbiente::Homologacao, $dps->infDps->tipoAmbiente);
    }

    public function test_numero_dps_recebido_por_parametro_prevalece_no_id_e_no_ndps(): void
    {
        // Regressão do fix de numeração: nDPS não pode mais ser fixo em '1'
        // — o parâmetro $numeroDps (vindo de NfeService::proximoNumeroDps())
        // precisa aparecer tanto no atributo Id da DPS quanto no campo nDPS.
        $cfg = $this->configuracaoSimplesNacional();

        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'HOMOLOGACAO', 42);

        $this->assertSame('42', $dps->infDps->numeroDps);
        $this->assertStringContainsString('42', $dps->infDps->id);
    }

    public function test_serie_dps_da_configuracao_e_usada_quando_definida(): void
    {
        $cfg = $this->configuracaoSimplesNacional();
        $cfg->serie_dps = '2';

        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'HOMOLOGACAO', 1);

        $this->assertSame('2', $dps->infDps->serie);
    }

    public function test_sem_endereco_decomposto_prest_end_fica_ausente(): void
    {
        // Configuracao padrão do fixture não seta logradouro/numero/bairro —
        // regressão do comportamento "nunca enviar grupo end incompleto"
        // (xLgr/nro/xBairro são obrigatórios se end for enviado).
        $cfg = $this->configuracaoSimplesNacional();

        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'HOMOLOGACAO', 1);

        $this->assertNull($dps->infDps->prestador->endereco);
    }

    public function test_endereco_decomposto_completo_preenche_prest_end(): void
    {
        $cfg = $this->configuracaoSimplesNacional();
        $cfg->logradouro = 'Rua das Oficinas';
        $cfg->numero = '123';
        $cfg->bairro = 'Centro';
        $cfg->cep = '37130-000';

        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'HOMOLOGACAO', 1);

        $end = $dps->infDps->prestador->endereco;
        $this->assertNotNull($end);
        $this->assertSame('Rua das Oficinas', $end->logradouro);
        $this->assertSame('123', $end->numero);
        $this->assertSame('Centro', $end->bairro);
        $this->assertSame((string) $cfg->codigo_ibge, $end->codigoMunicipio);
        $this->assertSame('37130000', $end->cep);
    }

    public function test_endereco_parcial_nao_envia_prest_end(): void
    {
        // Só logradouro preenchido, sem numero/bairro — grupo continua
        // ausente em vez de ir incompleto (rejeitaria no schema).
        $cfg = $this->configuracaoSimplesNacional();
        $cfg->logradouro = 'Rua das Oficinas';

        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'HOMOLOGACAO', 1);

        $this->assertNull($dps->infDps->prestador->endereco);
    }
}
