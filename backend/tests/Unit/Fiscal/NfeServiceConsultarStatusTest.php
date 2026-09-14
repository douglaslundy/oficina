<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Models\NotaFiscal;
use App\Services\Fiscal\Contracts\FiscalProvider;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\FiscalProviderManager;
use App\Services\NfeService;
use Mockery;
use Tests\TestCase;

/**
 * Bug real de produção (2026-09-14): `consultarStatus()` sempre mandava
 * `referencia_externa` (nossa referência interna, `nf-<uuid>`) pro provider,
 * não importa qual fosse — mas o NFePHP fala DIRETO com a SEFAZ/ADN usando a
 * chave de acesso real (44 dígitos). Isso dava "Consulta chave: chave
 * 'nf-<uuid>' invalida!" — confirmado ao vivo, causa real de uma NF-e via
 * NFePHP mostrar CONTINGÊNCIA com mensagem de erro de consulta.
 */
class NfeServiceConsultarStatusTest extends TestCase
{
    private function notaComProvedor(string $provedor, ?string $chaveAcesso, string $modelo = 'NF-e'): NotaFiscal
    {
        $nota = new NotaFiscal([
            'modelo' => $modelo, 'provedor' => $provedor,
            'referencia_externa' => 'nf-fake-uuid-123', 'chave_acesso' => $chaveAcesso,
        ]);
        $nota->id = 'fake-id';
        return $nota;
    }

    public function test_nfephp_com_chave_consulta_pela_chave_de_acesso_nao_pela_referencia_interna(): void
    {
        $nota = $this->notaComProvedor('NFEPHP', '31260950388509000121550010000000014082390387');

        $provider = Mockery::mock(FiscalProvider::class);
        $provider->shouldReceive('consultar')
            ->once()
            ->with('31260950388509000121550010000000014082390387', 'NFE')
            ->andReturn(EmissaoResultado::autorizada('CHAVE', 'PROT', '1', '<xml/>', null));

        $this->mock(FiscalProviderManager::class, function ($m) use ($provider) {
            $m->shouldReceive('forTenant')->once()->andReturn($provider);
        });

        $resultado = app(NfeService::class)->consultarStatus($nota);

        $this->assertSame('AUTORIZADA', $resultado['status']);
    }

    public function test_nfephp_sem_chave_ainda_nao_tenta_consultar_fica_processando(): void
    {
        $nota = $this->notaComProvedor('NFEPHP', null);

        $provider = Mockery::mock(FiscalProvider::class);
        $provider->shouldNotReceive('consultar');

        $this->mock(FiscalProviderManager::class, function ($m) use ($provider) {
            $m->shouldReceive('forTenant')->once()->andReturn($provider);
        });

        $resultado = app(NfeService::class)->consultarStatus($nota);

        $this->assertSame('PROCESSANDO', $resultado['status']);
    }

    public function test_spedy_continua_consultando_pela_referencia_interna(): void
    {
        $nota = $this->notaComProvedor('SPEDY', null);

        $provider = Mockery::mock(FiscalProvider::class);
        $provider->shouldReceive('consultar')
            ->once()
            ->with('nf-fake-uuid-123', 'NFE')
            ->andReturn(EmissaoResultado::processando('nf-fake-uuid-123'));

        $this->mock(FiscalProviderManager::class, function ($m) use ($provider) {
            $m->shouldReceive('forTenant')->once()->andReturn($provider);
        });

        $resultado = app(NfeService::class)->consultarStatus($nota);

        $this->assertSame('PROCESSANDO', $resultado['status']);
    }
}
