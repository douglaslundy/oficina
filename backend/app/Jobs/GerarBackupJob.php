<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Backup manual disparado pela tela do SaaS Admin. Roda no worker (não
 * bloqueia a API — o `artisan serve` do container web é single-thread).
 * O backup agendado diário NÃO usa este job: roda direto no container
 * scheduler.
 */
class GerarBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries   = 1;

    public function handle(BackupService $backup): void
    {
        $resultado = $backup->gerar();
        $backup->podarAntigos((int) config('backup.manter', 14));

        Log::info(sprintf(
            'Backup manual (job) OK: %s (%s MB%s).',
            $resultado['arquivo'],
            round($resultado['tamanho'] / 1048576, 2),
            !empty($resultado['cifrado']) ? ', cifrado' : '',
        ));
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Backup manual (job) falhou: ' . $e->getMessage());
    }
}
