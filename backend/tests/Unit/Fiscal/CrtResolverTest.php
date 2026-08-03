<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\CrtResolver;
use PHPUnit\Framework\TestCase;

class CrtResolverTest extends TestCase
{
    public function test_simples_nacional_e_crt_1(): void
    {
        $this->assertSame(1, CrtResolver::resolver('Simples Nacional'));
    }

    public function test_lucro_presumido_e_crt_3(): void
    {
        $this->assertSame(3, CrtResolver::resolver('Lucro Presumido'));
    }

    public function test_lucro_real_e_crt_3(): void
    {
        $this->assertSame(3, CrtResolver::resolver('Lucro Real'));
    }

    public function test_regime_vazio_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CrtResolver::resolver('');
    }
}
