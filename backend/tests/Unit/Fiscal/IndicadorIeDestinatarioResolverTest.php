<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Exceptions\EmissaoBloqueadaException;
use App\Services\Fiscal\IndicadorIeDestinatarioResolver;
use PHPUnit\Framework\TestCase;

/**
 * Cobre a correção do bug real da NF-e #13 (cStat=232 — "IE do destinatário
 * não informada"): antes, todo destinatário de NF-e era tratado como não
 * contribuinte (indIEDest=9), mesmo quando era uma pessoa jurídica com IE
 * real cadastrada na SEFAZ.
 */
class IndicadorIeDestinatarioResolverTest extends TestCase
{
    public function test_pessoa_fisica_e_sempre_nao_contribuinte(): void
    {
        $r = IndicadorIeDestinatarioResolver::resolver('123.456.789-00', null, false, 'João');
        $this->assertSame(9, $r['indicador']);
        $this->assertNull($r['inscricao_estadual']);
    }

    public function test_pessoa_fisica_ignora_ie_isento_marcado_por_engano(): void
    {
        // CPF nunca tem IE — mesmo que alguém marque "isento" por engano
        // num cadastro de pessoa física, o resultado é o mesmo (9, sem IE).
        $r = IndicadorIeDestinatarioResolver::resolver('123.456.789-00', null, true, 'João');
        $this->assertSame(9, $r['indicador']);
    }

    public function test_pessoa_juridica_com_ie_cadastrada_e_contribuinte(): void
    {
        $r = IndicadorIeDestinatarioResolver::resolver('12.345.678/0001-99', '123.456.789', false, 'Empresa LTDA');
        $this->assertSame(1, $r['indicador']);
        $this->assertSame('123456789', $r['inscricao_estadual']);
    }

    public function test_pessoa_juridica_isenta_nao_manda_ie(): void
    {
        $r = IndicadorIeDestinatarioResolver::resolver('12.345.678/0001-99', null, true, 'Empresa Isenta LTDA');
        $this->assertSame(2, $r['indicador']);
        $this->assertNull($r['inscricao_estadual']);
    }

    public function test_pessoa_juridica_sem_ie_e_sem_isento_bloqueia(): void
    {
        // Este é exatamente o bug real: nunca assumir "não contribuinte"
        // pra uma PJ sem essa informação — bloquear e pedir, não adivinhar.
        $this->expectException(EmissaoBloqueadaException::class);
        $this->expectExceptionMessage('Empresa Sem Cadastro');
        IndicadorIeDestinatarioResolver::resolver('12.345.678/0001-99', null, false, 'Empresa Sem Cadastro');
    }

    public function test_pessoa_juridica_com_ie_vazia_e_tratada_como_ausente(): void
    {
        $this->expectException(EmissaoBloqueadaException::class);
        IndicadorIeDestinatarioResolver::resolver('12.345.678/0001-99', '', false, 'Empresa');
    }

    public function test_ie_com_ie_tem_prioridade_sobre_isento(): void
    {
        // Se por algum motivo os dois estão marcados (dado inconsistente no
        // cadastro), uma IE real presente vence — ela é a afirmação mais
        // forte/específica.
        $r = IndicadorIeDestinatarioResolver::resolver('12.345.678/0001-99', '123.456.789', true, 'Empresa');
        $this->assertSame(1, $r['indicador']);
    }
}
