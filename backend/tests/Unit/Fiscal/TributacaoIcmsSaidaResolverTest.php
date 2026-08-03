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
}
