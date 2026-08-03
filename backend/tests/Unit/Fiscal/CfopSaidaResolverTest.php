<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\CfopSaidaResolver;
use PHPUnit\Framework\TestCase;

class CfopSaidaResolverTest extends TestCase
{
    public function test_dentro_do_estado_normal(): void
    {
        $this->assertSame('5102', CfopSaidaResolver::resolver('MG', 'MG', false));
    }

    public function test_dentro_do_estado_com_st(): void
    {
        $this->assertSame('5405', CfopSaidaResolver::resolver('MG', 'MG', true));
    }

    public function test_fora_do_estado_normal(): void
    {
        $this->assertSame('6102', CfopSaidaResolver::resolver('MG', 'SP', false));
    }

    public function test_fora_do_estado_com_st(): void
    {
        $this->assertSame('6404', CfopSaidaResolver::resolver('MG', 'SP', true));
    }

    public function test_uf_vazia_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CfopSaidaResolver::resolver('', 'SP', false);
    }

    public function test_uf_invalida_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CfopSaidaResolver::resolver('MG', 'XX', false);
    }
}
