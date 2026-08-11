<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Models\SaasConfig;
use App\Services\Fiscal\FiscalProviderManager;
use App\Services\Fiscal\Providers\NfePhpProvider;
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

    public function test_resolver_provedor_aceita_nfephp(): void
    {
        $this->assertSame('NFEPHP', FiscalProviderManager::resolverProvedor('NFEPHP', 'SPEDY'));
    }

    public function test_build_nfephp_retorna_nfe_php_provider(): void
    {
        $manager = new FiscalProviderManager();
        $cfg = new SaasConfig();

        $provider = $manager->build('NFEPHP', 'HOMOLOGACAO', $cfg, null, null);

        $this->assertInstanceOf(NfePhpProvider::class, $provider);
    }
}
