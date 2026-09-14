<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\CodigoTributacaoNacionalResolver;
use PHPUnit\Framework\TestCase;

class CodigoTributacaoNacionalResolverTest extends TestCase
{
    public function test_item_14_01_resolve_para_140101(): void
    {
        // Confirmado na tabela oficial gov.br/nfse/pt-br/mei-e-demais-empresas/
        // codigos-de-tributacao-nacional-nbs (consultada em 2026-09-14):
        // "Lubrificação, limpeza, lustração, revisão, carga e recarga,
        // conserto, restauração, blindagem, manutenção e conservação de
        // máquinas, veículos..." — único item usado por este sistema.
        $this->assertSame('140101', CodigoTributacaoNacionalResolver::resolver('14.01'));
    }

    public function test_codigo_desconhecido_lanca_excecao_em_vez_de_chutar(): void
    {
        // Mesma regra do CrtResolver: nunca um default silencioso pra uma
        // decisão fiscal sem base confirmada.
        $this->expectException(\InvalidArgumentException::class);
        CodigoTributacaoNacionalResolver::resolver('17.01');
    }
}
