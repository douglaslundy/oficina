<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Oficina;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OficinaVencimentoTest extends TestCase
{
    public function test_ciclo_mensal_soma_um_mes(): void
    {
        $oficina = new Oficina();
        $oficina->ciclo_cobranca = 'MENSAL';
        $oficina->proximo_vencimento = Carbon::parse('2026-01-15');

        $this->assertSame('2026-02-15', $oficina->calcularProximoVencimento()->toDateString());
    }

    public function test_ciclo_anual_soma_doze_meses(): void
    {
        $oficina = new Oficina();
        $oficina->ciclo_cobranca = 'ANUAL';
        $oficina->proximo_vencimento = Carbon::parse('2026-01-15');

        $this->assertSame('2027-01-15', $oficina->calcularProximoVencimento()->toDateString());
    }
}
