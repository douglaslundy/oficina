<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\CfopConsumidorResolver;
use PHPUnit\Framework\TestCase;

class CfopConsumidorResolverTest extends TestCase
{
    public function test_dentro_do_estado(): void
    {
        $this->assertSame('5102', CfopConsumidorResolver::resolver('MG', 'MG'));
    }

    public function test_fora_do_estado(): void
    {
        $this->assertSame('6108', CfopConsumidorResolver::resolver('MG', 'SP'));
    }

    public function test_uf_vazia_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CfopConsumidorResolver::resolver('', 'SP');
    }

    public function test_uf_invalida_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CfopConsumidorResolver::resolver('MG', 'XX');
    }
}
