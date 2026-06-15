<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Services\ClienteStatusService;
use Illuminate\Console\Command;

class RecalcularStatusClientes extends Command
{
    protected $signature   = 'oficina:recalcular-status-clientes';
    protected $description = 'Recalcula status de todos os clientes com débito em aberto (DEVEDOR/DIVIDA_VENCIDA)';

    public function handle(ClienteStatusService $service): int
    {
        $clientes = Cliente::whereIn('status', ['DEVEDOR', 'DIVIDA_VENCIDA', 'OS_ABERTA'])->get();

        $this->info("Recalculando {$clientes->count()} clientes...");

        foreach ($clientes as $cliente) {
            $service->recalcular($cliente->id);
        }

        $this->info('Concluído.');
        return self::SUCCESS;
    }
}
