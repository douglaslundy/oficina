<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Cobranca;
use App\Models\Oficina;

class AssinaturaService
{
    /** Cancela a cobrança de assinatura pendente do ciclo atual e recalcula o vencimento a partir de hoje. */
    public function mudarCiclo(Oficina $oficina, string $ciclo): void
    {
        Cobranca::where('oficina_id', $oficina->id)
            ->where('tipo', 'ASSINATURA')
            ->where('status', 'PENDENTE')
            ->update(['status' => 'CANCELADA']);

        $meses = $ciclo === 'ANUAL' ? 12 : 1;
        $oficina->update([
            'ciclo_cobranca'     => $ciclo,
            'proximo_vencimento' => now()->addMonths($meses)->toDateString(),
        ]);
    }
}
