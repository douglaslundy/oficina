<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Cliente;
use App\Models\OrdemServico;

class ClienteStatusService
{
    public function recalcular(string $clienteId): string
    {
        $temDebito = OrdemServico::where('cliente_id', $clienteId)
            ->whereColumn('valor_pago', '<', 'valor_total')
            ->exists();

        if ($temDebito) {
            Cliente::where('id', $clienteId)->update(['status' => 'DEVEDOR']);
            return 'DEVEDOR';
        }

        $temOsAberta = OrdemServico::where('cliente_id', $clienteId)
            ->whereIn('status', ['ABERTA', 'EM_ANDAMENTO'])
            ->exists();

        $status = $temOsAberta ? 'OS_ABERTA' : 'REGULAR';
        Cliente::where('id', $clienteId)->update(['status' => $status]);
        return $status;
    }
}
