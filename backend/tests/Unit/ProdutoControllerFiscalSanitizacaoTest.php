<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\ProdutoController;
use App\Services\Fiscal\ProdutoFiscalService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ProdutoController::store()/update() agora sanitizam os campos fiscais
 * antes de gravar (gap apontado na revisão: o formulário manual validava
 * só tamanho/enum — "AAAAAAAA" passa size:8 sem ser NCM válido — e nunca
 * passava pelo mesmo filtro que o caminho de importação de XML usa).
 *
 * Testa só a parte pura desse fluxo — mesclarFiscalSanitizado() — via
 * reflection, sem DB. ProdutoFiscalService::sanitizarCampos() já tem
 * cobertura própria em Tests/Unit/Fiscal/ProdutoFiscalServiceSanitizacaoTest.
 */
class ProdutoControllerFiscalSanitizacaoTest extends TestCase
{
    private ProdutoController $controller;
    private ProdutoFiscalService $fiscalService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller   = new ProdutoController();
        $this->fiscalService = new ProdutoFiscalService();
    }

    private function mesclar(array $validated, array $sanitizado): array
    {
        $reflection = new ReflectionClass($this->controller);
        $metodo     = $reflection->getMethod('mesclarFiscalSanitizado');
        $metodo->setAccessible(true);

        return $metodo->invoke($this->controller, $validated, $sanitizado);
    }

    private function temMudancaFiscal(array $validated): bool
    {
        $reflection = new ReflectionClass($this->controller);
        $metodo     = $reflection->getMethod('temMudancaFiscalNoValidated');
        $metodo->setAccessible(true);

        return $metodo->invoke($this->controller, $validated);
    }

    public function test_ncm_malformado_com_tamanho_valido_vira_null(): void
    {
        // "AAAAAAAA" tem 8 caracteres — passaria em size:8 — mas não é NCM.
        $validated = ['nome' => 'Peça X', 'ncm' => 'AAAAAAAA', 'cest' => null, 'origem' => null, 'tributacao_icms' => null];
        $sanitizado = $this->fiscalService->sanitizarCampos($validated);

        $resultado = $this->mesclar($validated, $sanitizado);

        $this->assertNull($resultado['ncm']);
        $this->assertSame('Peça X', $resultado['nome'], 'campos não-fiscais não são tocados');
    }

    public function test_origem_zero_sobrevive_a_sanitizacao_no_controller(): void
    {
        $validated = ['ncm' => '87083090', 'cest' => null, 'origem' => 0, 'tributacao_icms' => 'NORMAL'];
        $sanitizado = $this->fiscalService->sanitizarCampos($validated);

        $resultado = $this->mesclar($validated, $sanitizado);

        $this->assertSame(0, $resultado['origem'], 'origem=0 (mercadoria nacional) nunca pode virar null');
    }

    public function test_nao_forca_presenca_de_chave_fiscal_ausente(): void
    {
        // Cliente só mandou 'nome' — nenhum campo fiscal na request.
        $validated = ['nome' => 'Peça Y'];
        $sanitizado = $this->fiscalService->sanitizarCampos($validated);

        $resultado = $this->mesclar($validated, $sanitizado);

        $this->assertArrayNotHasKey('ncm', $resultado);
        $this->assertArrayNotHasKey('cest', $resultado);
        $this->assertArrayNotHasKey('origem', $resultado);
        $this->assertArrayNotHasKey('tributacao_icms', $resultado);
    }

    public function test_valor_valido_e_preservado(): void
    {
        $validated = ['ncm' => '87083090', 'cest' => '0100100', 'origem' => 3, 'tributacao_icms' => 'ST'];
        $sanitizado = $this->fiscalService->sanitizarCampos($validated);

        $resultado = $this->mesclar($validated, $sanitizado);

        $this->assertSame('87083090', $resultado['ncm']);
        $this->assertSame('0100100', $resultado['cest']);
        $this->assertSame(3, $resultado['origem']);
        $this->assertSame('ST', $resultado['tributacao_icms']);
    }

    public function test_submeter_lixo_fiscal_nao_conta_como_revisao_manual(): void
    {
        // Todos os campos fiscais preenchidos, mas todos malformados —
        // depois da sanitização, tudo vira null, então não deve contar
        // como "usuário alterou dado fiscal" (evita carimbar MANUAL sobre
        // lixo, o que esconderia a pendência).
        $validated = [
            'ncm'             => 'ZZZZZZZZ',
            'cest'            => 'ZZZZZZZ',
            'origem'          => null,
            'tributacao_icms' => 'INVALIDO',
        ];
        $sanitizado = $this->fiscalService->sanitizarCampos($validated);
        $mesclado   = $this->mesclar($validated, $sanitizado);

        $this->assertFalse($this->temMudancaFiscal($mesclado));
    }

    public function test_submeter_origem_zero_conta_como_mudanca_fiscal_valida(): void
    {
        $validated = ['ncm' => null, 'cest' => null, 'origem' => 0, 'tributacao_icms' => null];
        $sanitizado = $this->fiscalService->sanitizarCampos($validated);
        $mesclado   = $this->mesclar($validated, $sanitizado);

        $this->assertTrue($this->temMudancaFiscal($mesclado), 'origem=0 é dado fiscal válido, não ausência');
    }
}
