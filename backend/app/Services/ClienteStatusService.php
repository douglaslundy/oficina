<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Cliente;
use App\Models\OrdemServico;

class ClienteStatusService
{
    public function __construct(private readonly AlertaDispatchService $alertas) {}

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
            $cliente = Cliente::find($clienteId);
            if ($cliente && $cliente->status !== 'DIVIDA_VENCIDA') {
                $os = OrdemServico::where('cliente_id', $clienteId)
                    ->where('status', 'CONCLUIDA')->where('venda_a_prazo', true)
                    ->whereColumn('valor_pago', '<', 'valor_total')
                    ->first();
                $this->alertas->dispatch('DIVIDA_VENCIDA', [
                    'cliente'            => $cliente->nome,
                    'valor'              => 'R$ ' . number_format(max(0, $os?->valor_total - $os?->valor_pago), 2, ',', '.'),
                    'os_numero'          => $os?->numero ?? '-',
                    'vencimento'         => $os?->data_vencimento_pagamento?->format('d/m/Y') ?? '-',
                    '_telefone_cliente'  => $cliente->telefone ?? '',
                ]);
            }
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
            $cliente = Cliente::find($clienteId);
            if ($cliente && !in_array($cliente->status, ['DEVEDOR', 'DIVIDA_VENCIDA'], true)) {
                $os = OrdemServico::where('cliente_id', $clienteId)
                    ->where('status', 'CONCLUIDA')->whereColumn('valor_pago', '<', 'valor_total')->first();
                $this->alertas->dispatch('CLIENTE_DEVEDOR', [
                    'cliente'           => $cliente->nome,
                    'valor'             => 'R$ ' . number_format(max(0, $os?->valor_total - $os?->valor_pago), 2, ',', '.'),
                    'os_numero'         => $os?->numero ?? '-',
                    '_telefone_cliente' => $cliente->telefone ?? '',
                ]);
            }
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
