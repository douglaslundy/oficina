<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\PrazoContingencia;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class PrazoContingenciaTest extends TestCase
{
    public function test_dias_restantes_no_inicio_da_contingencia(): void
    {
        $inicio = Carbon::parse('2026-08-01 10:00:00');
        $agora  = Carbon::parse('2026-08-01 10:00:00');

        $this->assertSame(7, PrazoContingencia::diasRestantes($inicio, $agora));
    }

    public function test_precisa_alertar_a_2_dias_do_prazo(): void
    {
        $inicio = Carbon::parse('2026-08-01 10:00:00');
        $agora  = Carbon::parse('2026-08-06 10:00:00'); // 5 dias depois, 2 restantes

        $this->assertTrue(PrazoContingencia::precisaAlertar($inicio, $agora));
    }

    public function test_nao_precisa_alertar_com_mais_de_2_dias_restantes(): void
    {
        $inicio = Carbon::parse('2026-08-01 10:00:00');
        $agora  = Carbon::parse('2026-08-03 10:00:00'); // 2 dias depois, 5 restantes

        $this->assertFalse(PrazoContingencia::precisaAlertar($inicio, $agora));
    }

    public function test_prazo_estourado_retorna_zero_nao_negativo(): void
    {
        $inicio = Carbon::parse('2026-08-01 10:00:00');
        $agora  = Carbon::parse('2026-08-15 10:00:00'); // muito depois do prazo

        $this->assertSame(0, PrazoContingencia::diasRestantes($inicio, $agora));
    }
}
