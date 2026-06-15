<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Cliente;
use App\Models\OrdemServico;

class ClienteStatusService
{
    public function recalcular(string $clienteId): string
    {
        // 1. Dívida vencida: OS concluída + venda a prazo + prazo expirado + saldo em aberto
        $temDividaVencida = OrdemServico::where('cliente_id', $clienteId)
            ->where('status', 'CONCLUIDA')
            ->where('venda_a_prazo', true)
            ->whereNotNull('data_vencimento_pagamento')
            ->whereDate('data_vencimento_pagamento', '<', now()->toDateString())
            ->whereColumn('valor_pago', '<', 'valor_total')
            ->where('valor_total', '>', 0)
            ->exists();

        if ($temDividaVencida) {
            Cliente::where('id', $clienteId)->update(['status' => 'DIVIDA_VENCIDA']);
            return 'DIVIDA_VENCIDA';
        }

        // 2. Devedor: OS concluída + saldo em aberto (dentro do prazo ou sem prazo)
        $temDebito = OrdemServico::where('cliente_id', $clienteId)
            ->where('status', 'CONCLUIDA')
            ->whereColumn('valor_pago', '<', 'valor_total')
            ->where('valor_total', '>', 0)
            ->exists();

        if ($temDebito) {
            Cliente::where('id', $clienteId)->update(['status' => 'DEVEDOR']);
            return 'DEVEDOR';
        }

        // 3. OS em andamento
        $temOsAberta = OrdemServico::where('cliente_id', $clienteId)
            ->whereIn('status', ['ABERTA', 'EM_ANDAMENTO'])
            ->exists();

        $status = $temOsAberta ? 'OS_ABERTA' : 'REGULAR';
        Cliente::where('id', $clienteId)->update(['status' => $status]);
        return $status;
    }
}
