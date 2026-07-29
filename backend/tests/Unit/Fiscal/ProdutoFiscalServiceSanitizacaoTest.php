<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\ProdutoFiscalService;
use PHPUnit\Framework\TestCase;

/**
 * Cobre apenas ProdutoFiscalService::sanitizarCampos() (lógica pura, sem
 * Eloquent/DB) — a garantia de finding 4 de que nenhum valor malformado
 * entra por essa via, incluindo o caso crítico de origem = 0 (mercadoria
 * nacional, valor válido, não pode virar null).
 */
class ProdutoFiscalServiceSanitizacaoTest extends TestCase
{
    private ProdutoFiscalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProdutoFiscalService();
    }

    public function test_valores_validos_passam_intactos(): void
    {
        $resultado = $this->service->sanitizarCampos([
            'ncm'             => '87083090',
            'cest'            => '0100100',
            'origem'          => 3,
            'tributacao_icms' => 'ST',
        ]);

        $this->assertSame([
            'ncm'             => '87083090',
            'cest'            => '0100100',
            'origem'          => 3,
            'tributacao_icms' => 'ST',
        ], $resultado);
    }

    public function test_ncm_malformado_vira_null_nao_lixo(): void
    {
        $resultado = $this->service->sanitizarCampos([
            'ncm'             => 'XX083090',
            'cest'            => null,
            'origem'          => null,
            'tributacao_icms' => null,
        ]);

        $this->assertNull($resultado['ncm']);
    }

    public function test_ncm_curto_ou_longo_vira_null(): void
    {
        $this->assertNull($this->service->sanitizarCampos(['ncm' => '870830'])['ncm']);
        $this->assertNull($this->service->sanitizarCampos(['ncm' => '8708309099'])['ncm']);
    }

    public function test_cest_malformado_vira_null(): void
    {
        $resultado = $this->service->sanitizarCampos(['cest' => 'ABCDEFG']);
        $this->assertNull($resultado['cest']);
    }

    public function test_origem_zero_e_valor_valido_nunca_vira_null(): void
    {
        // 0 = mercadoria nacional. Regressão: absence-check errado trataria
        // 0 como ausente e o descartaria.
        $resultado = $this->service->sanitizarCampos([
            'ncm'             => '87083090',
            'origem'          => 0,
            'tributacao_icms' => 'NORMAL',
        ]);

        $this->assertSame(0, $resultado['origem']);
    }

    public function test_origem_fora_da_tabela_vira_null(): void
    {
        $this->assertNull($this->service->sanitizarCampos(['origem' => 9])['origem']);
        $this->assertNull($this->service->sanitizarCampos(['origem' => -1])['origem']);
    }

    public function test_tributacao_icms_fora_do_dominio_vira_null(): void
    {
        $resultado = $this->service->sanitizarCampos(['tributacao_icms' => 'ISENTO']);
        $this->assertNull($resultado['tributacao_icms']);
    }

    public function test_campos_ausentes_viram_null_sem_lancar(): void
    {
        $resultado = $this->service->sanitizarCampos([]);

        $this->assertSame([
            'ncm'             => null,
            'cest'            => null,
            'origem'          => null,
            'tributacao_icms' => null,
        ], $resultado);
    }

    public function test_ncm_com_pontuacao_e_normalizado(): void
    {
        $resultado = $this->service->sanitizarCampos(['ncm' => '8708.30.90']);
        $this->assertSame('87083090', $resultado['ncm']);
    }
}
