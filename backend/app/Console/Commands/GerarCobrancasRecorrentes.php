<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CobrancaRecorrenteService;
use Illuminate\Console\Command;

class GerarCobrancasRecorrentes extends Command
{
    protected $signature   = 'cobrancas:gerar';
    protected $description = 'Gera cobrancas de assinatura pendentes e marca cobrancas vencidas como VENCIDA';

    public function __construct(private readonly CobrancaRecorrenteService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $geradas  = $this->service->gerarPendentes();
        $vencidas = $this->service->marcarVencidas();

        $this->info("Cobranças geradas: {$geradas}. Cobranças marcadas como vencidas: {$vencidas}.");
        return self::SUCCESS;
    }
}
