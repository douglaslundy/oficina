<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use Carbon\CarbonInterface;

/**
 * Cálculo puro do prazo de 7 dias da contingência EPEC — extraído do
 * comando agendado pra ser testável sem I/O. Ver spec Seção C: se o XML
 * normal não for transmitido em até 7 dias, a SEFAZ bloqueia novos EPEC.
 */
final class PrazoContingencia
{
    private const PRAZO_DIAS = 7;
    private const ALERTA_DIAS_RESTANTES = 2;

    public static function diasRestantes(CarbonInterface $contingenciaDesde, CarbonInterface $agora): int
    {
        $prazoFinal = $contingenciaDesde->copy()->addDays(self::PRAZO_DIAS);
        return max(0, (int) $agora->diffInDays($prazoFinal, false));
    }

    public static function precisaAlertar(CarbonInterface $contingenciaDesde, CarbonInterface $agora): bool
    {
        $restantes = self::diasRestantes($contingenciaDesde, $agora);
        return $restantes <= self::ALERTA_DIAS_RESTANTES;
    }
}
