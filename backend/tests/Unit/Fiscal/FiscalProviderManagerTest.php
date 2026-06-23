<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\FiscalProviderManager;
use PHPUnit\Framework\TestCase;

class FiscalProviderManagerTest extends TestCase
{
    public function test_override_da_oficina_prevalece(): void
    {
        $this->assertSame('FOCUS', FiscalProviderManager::resolverProvedor('FOCUS', 'SPEDY'));
    }

    public function test_sem_override_usa_padrao_global(): void
    {
        $this->assertSame('SPEDY', FiscalProviderManager::resolverProvedor(null, 'SPEDY'));
    }

    public function test_override_invalido_cai_no_padrao(): void
    {
        $this->assertSame('SPEDY', FiscalProviderManager::resolverProvedor('XPTO', 'SPEDY'));
    }
}
