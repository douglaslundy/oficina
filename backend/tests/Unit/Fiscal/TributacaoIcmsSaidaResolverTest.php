<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\TributacaoIcmsSaidaResolver;
use PHPUnit\Framework\TestCase;

class TributacaoIcmsSaidaResolverTest extends TestCase
{
    public function test_simples_nacional_normal(): void
    {
        $this->assertSame('102', TributacaoIcmsSaidaResolver::resolver('Simples Nacional', 'NORMAL'));
    }

    public function test_simples_nacional_st(): void
    {
        $this->assertSame('500', TributacaoIcmsSaidaResolver::resolver('Simples Nacional', 'ST'));
    }

    public function test_lucro_presumido_normal(): void
    {
        $this->assertSame('00', TributacaoIcmsSaidaResolver::resolver('Lucro Presumido', 'NORMAL'));
    }

    public function test_lucro_real_st(): void
    {
        $this->assertSame('60', TributacaoIcmsSaidaResolver::resolver('Lucro Real', 'ST'));
    }

    public function test_tributacao_invalida_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TributacaoIcmsSaidaResolver::resolver('Simples Nacional', 'ISENTO');
    }

    /**
     * Achado 2026-09-23 (auditoria fiscal): este resolver classificava
     * Simples/não-Simples checando só a substring "simples" no texto livre
     * de regime_tributario — mas o CrtResolver irmão (usado pro CRT da
     * mesma nota) já reconhecia "MEI" como Simples Nacional (CRT=1) desde
     * antes. Uma oficina cadastrada literalmente como "MEI" saía com CRT=1
     * mas CST (não CSOSN) no item — NF-e internamente inconsistente e
     * rejeitável pela SEFAZ. Corrigido delegando a classificação ao
     * CrtResolver (fonte única).
     */
    public function test_mei_e_tratado_como_simples_nacional_mesmo_sem_a_palavra_simples(): void
    {
        $this->assertSame('102', TributacaoIcmsSaidaResolver::resolver('MEI', 'NORMAL'));
        $this->assertSame('500', TributacaoIcmsSaidaResolver::resolver('MEI', 'ST'));
    }

    public function test_simples_nacional_mei_formato_composto(): void
    {
        $this->assertSame('102', TributacaoIcmsSaidaResolver::resolver('Simples Nacional - MEI', 'NORMAL'));
    }
}
