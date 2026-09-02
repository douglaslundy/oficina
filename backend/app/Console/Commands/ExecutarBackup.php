<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExecutarBackup extends Command
{
    protected $signature = 'backup:executar
        {--sufixo= : Sufixo opcional no nome do arquivo (ex.: pre-deploy)}
        {--manter= : Sobrescreve config(backup.manter) para esta execução}';

    protected $description = 'Gera um backup verificado do PostgreSQL e poda os antigos';

    public function handle(BackupService $backup): int
    {
        $inicio = microtime(true);

        try {
            $resultado = $backup->gerar($this->option('sufixo') ?: null);
        } catch (\Throwable $e) {
            Log::error('Backup automático falhou: ' . $e->getMessage());
            $this->error('Falha ao gerar backup: ' . $e->getMessage());
            return self::FAILURE;
        }

        $manter   = (int) ($this->option('manter') ?? config('backup.manter', 14));
        $removidos = $backup->podarAntigos($manter);

        $segundos = round(microtime(true) - $inicio, 1);
        $mb       = round($resultado['tamanho'] / 1048576, 2);

        $msg = sprintf(
            'Backup OK: %s (%s MB, sha256 %s…) em %ss. Podados: %d.',
            $resultado['arquivo'],
            $mb,
            substr($resultado['checksum'], 0, 12),
            $segundos,
            count($removidos),
        );

        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }
}
