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
        // Bug real de produção (2026-09-14): mandar o "14.01" (formato LC116
        // clássico) direto como cTribNac dava erro de schema na SEFAZ/ADN
        // ("E1235: Falha no esquema XML do DF-e" — TSCodTribNac exige 6
        // dígitos numéricos). Precisa do código de 6 dígitos real
        // (CodigoTributacaoNacionalResolver, confirmado na tabela oficial
        // gov.br/nfse).
        $this->assertSame('140101', $inf->servico->codigoServico->codigoTributacaoNacional);
        // Bug irmão, achado na mesma investigação: cTribMun (TCCodTribMun)
        // exige exatamente 3 dígitos numéricos — é um código MUNICIPAL (cada
        // cidade tem a própria tabela, sem fonte nacional única pra
        // confirmar), e o "1401" que este sistema sempre usa (formato
        // LC116/Spedy/Focus, 4 dígitos) nunca bate com esse padrão. Campo é
        // opcional no schema (minOccurs="0") — omitido em vez de chutar um
        // código municipal que ninguém confirmou.
        $this->assertNull($inf->servico->codigoServico->codigoTributacaoMunicipal);
        $this->assertSame('Troca de óleo', $inf->servico->codigoServico->descricaoServico);
        $this->assertSame((string) $cfg->codigo_ibge, $inf->servico->localPrestacao->codigoLocalPrestacao);

        // Valores / tributação
        $this->assertSame(150.0, $inf->valores->valorServicoPrestado->valorServico);
        $this->assertSame(TributacaoIssqn::OperacaoTributavel, $inf->valores->tributacao->tributacaoIssqn);
        $this->assertSame(TipoRetencaoIssqn::NaoRetido, $inf->valores->tributacao->tipoRetencaoIssqn);
        // Bug real de produção (2026-09-14, 5ª camada): Simples Nacional sem
        // retenção não pode informar pAliq (ver test_simples_nacional_nao_
        // retido_nao_manda_paliq abaixo, com a mensagem completa da ADN).
        $this->assertNull($inf->valores->tributacao->aliquota);
        // Bug real de produção (2026-09-14, 6ª camada): pra ME/EPP,
        // `indTotTrib` é PROIBIDO (E0712) — usa `pTotTribSN` em vez disso
        // (ver test_me_epp_usa_ptotribsn_em_vez_de_indtotrib abaixo).
        $this->assertNull($inf->valores->tributacao->indicadorTotalTributos);
        $this->assertNotNull($inf->valores->tributacao->percentualTotalTributosSN);
    }

    public function test_ctribmun_valido_de_3_digitos_e_enviado(): void
    {
        // Quando o código municipal REALMENTE tem o formato exigido (3
        // dígitos — TCCodTribMun), deve ser enviado normalmente.
        $cfg = $this->configuracaoSimplesNacional();
        $nota = new NotaFiscalData(
            tipo: 'NFSE',
            tomador: ['nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678909'],
            descricao: 'Troca de óleo',
            valorServicos: 150.00,
            aliquotaIss: 5.0,
            issRetido: false,
            codigoServicoFederal: '14.01',
            codigoServicoMunicipal: '104',
            naturezaOperacao: 'Prestação de Serviços',
            referenciaExterna: 'nfse-ctribmun',
        );

        $dps = (new MotorNfse())->montarDps($nota, $cfg, 'HOMOLOGACAO', 1);

        $this->assertSame('104', $dps->infDps->servico->codigoServico->codigoTributacaoMunicipal);
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

    public function test_simples_nacional_nao_retido_nao_manda_paliq(): void
    {
        // Bug real de produção (2026-09-14, 5ª camada de validação da mesma
        // investigação): a ADN rejeitou com "E0625: Não é permitido
        // informar alíquota quando não há indicação de retenção do ISSQN
        // (tpRetISSQN = 1) para o prestador de serviço ME/EPP (opSimpNac =
        // 3)... com apuração do ISSQN pelo simples nacional (regApTribISSQN
        // = 1)" — confirmado ao vivo contra o ambiente de homologação real
        // do governo. Pra Simples Nacional (regApTribSN=1) sem retenção
        // (tpRetISSQN=1), a alíquota é calculada pela própria ADN a partir
        // da tabela do Simples Nacional — informar pAliq é proibido, não
        // opcional.
        $dps = (new MotorNfse())->montarDps($this->notaServico(), $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1);

        $this->assertNull($dps->infDps->valores->tributacao->aliquota);
    }

    public function test_simples_nacional_retido_ainda_manda_paliq(): void
    {
        // A regra da ADN só proíbe pAliq no caso SEM retenção — com
        // retenção (tpRetISSQN=2, "Retido pelo Tomador") a alíquota
        // continua sendo informada normalmente pelo prestador.
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
            referenciaExterna: 'nfse-retido',
        );

        $dps = (new MotorNfse())->montarDps($nota, $cfg, 'HOMOLOGACAO', 1);

        $this->assertSame(5.0, $dps->infDps->valores->tributacao->aliquota);
    }

    public function test_regime_normal_nao_retido_ainda_manda_paliq(): void
    {
        // A regra da ADN é específica do Simples Nacional (regApTribSN=1) —
        // Regime Normal (Lucro Presumido/Real) continua informando pAliq
        // mesmo sem retenção.
        $cfg = $this->configuracaoSimplesNacional();
        $cfg->regime_tributario = 'Lucro Presumido';

        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'HOMOLOGACAO', 1);

        $this->assertSame(5.0, $dps->infDps->valores->tributacao->aliquota);
    }

    public function test_me_epp_usa_ptotribsn_em_vez_de_indtotrib(): void
    {
        // Bug real de produção (2026-09-14, 6ª camada de validação da mesma
        // investigação): a ADN rejeitou com "E0712: Para ME/EPP o indicador
        // de informação de valor total de tributos não pode ser informado."
        // — confirmado ao vivo contra o ambiente de homologação real do
        // governo. `indTotTrib` (usado até aqui) é proibido pra ME/EPP —
        // existe uma opção dedicada no mesmo xs:choice, `pTotTribSN`
        // ("percentual aproximado do total dos tributos da alíquota do
        // Simples Nacional"), que é a variante correta pra esse regime.
        $dps = (new MotorNfse())->montarDps($this->notaServico(), $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1);

        $this->assertNull($dps->infDps->valores->tributacao->indicadorTotalTributos);
        // Bug REAL na lib vendor (2026-09-14, confirmado lendo
        // DpsXmlBuilder.php linha ~378): usa `if ($valor)` — truthy — em vez
        // de `!== null` pra decidir se inclui `pTotTribSN` no XML. `0.0`
        // (nosso valor original) é falsy em PHP, então a lib pulava o
        // elemento inteiro e o schema rejeitava "trib com conteúdo
        // incompleto" — mesmo bug já corrigido, agora dentro de código de
        // terceiro que não dá pra editar (seria sobrescrito no próximo
        // `composer install`). Workaround do nosso lado: manda 0.001 (não é
        // falsy em PHP) que a própria lib formata com `number_format(...,2)`
        // pra "0.00" no XML final — o valor transmitido pro governo é
        // idêntico ao que já era a intenção original, só engana o `if()`.
        $this->assertSame(0.001, $dps->infDps->valores->tributacao->percentualTotalTributosSN);
    }

    public function test_regime_normal_continua_usando_indtotrib(): void
    {
        $cfg = $this->configuracaoSimplesNacional();
        $cfg->regime_tributario = 'Lucro Presumido';

        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'HOMOLOGACAO', 1);

        $this->assertSame(
            \Nfse\Enums\IndicadorTotalTributos::Nenhum,
            $dps->infDps->valores->tributacao->indicadorTotalTributos,
        );
        $this->assertNull($dps->infDps->valores->tributacao->percentualTotalTributosSN);
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

    public function test_prest_end_nunca_e_enviado_mesmo_com_endereco_completo(): void
    {
        // Bug real de produção (2026-09-14, 4ª camada de validação da mesma
        // investigação de erro de schema): a ADN rejeitou com
        // "E0128: O endereço nacional do prestador do serviço não deve ser
        // informado na DPS quando o próprio prestador for o emitente da
        // DPS." — confirmado ao vivo contra o ambiente de homologação real
        // do governo. Este sistema SEMPRE emite com tpEmit=1 (prestador é
        // sempre o emitente, nunca um intermediário/tomador) — logo o grupo
        // `end` dentro de `prest` nunca pode ser enviado, mesmo quando a
        // Configuracao tem endereço completo (o dado já está no cadastro
        // nacional do CNPJ, reenviar é que causa a rejeição).
        $cfg = $this->configuracaoSimplesNacional();
        $cfg->logradouro = 'Rua das Oficinas';
        $cfg->numero = '123';
        $cfg->bairro = 'Centro';
        $cfg->cep = '37130-000';

        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'HOMOLOGACAO', 1);

        $this->assertNull($dps->infDps->prestador->endereco);
    }

    /**
     * REVERTIDO 2026-09-16 (rejeição real "E0120: IM do prestador não deve
     * ser informado, pois não existem informações complementares
     * registradas no CNC NFS-e do município emissor"). A Rodada 51
     * (2026-09-15) tinha introduzido o envio condicional de `IM` baseado em
     * `Configuracao.inscricao_municipal` estar preenchida, supondo que isso
     * indicava registro no CNC NFS-e — suposição errada (são cadastros
     * diferentes: inscrição municipal comum vs. CNC do Sistema Nacional
     * NFS-e). Sem forma confiável de saber se o CNC existe, `IM` nunca deve
     * ser mandado — mesmo quando `inscricao_municipal` está preenchida.
     */
    public function test_prest_im_nunca_e_enviada_mesmo_com_inscricao_municipal_configurada(): void
    {
        $cfg = $this->configuracaoSimplesNacional();
        $cfg->inscricao_municipal = '801944'; // valor real que causou a rejeição E0120 em produção

        $dps = (new MotorNfse())->montarDps($this->notaServico(), $cfg, 'HOMOLOGACAO', 1);

        $this->assertNull($dps->infDps->prestador->inscricaoMunicipal);
    }
}
