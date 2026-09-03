<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class DecifrarBackup extends Command
{
    protected $signature = 'backup:decifrar
        {arquivo : Nome do .sql.gz.enc em storage/backups (ou caminho absoluto)}
        {--saida= : Caminho de saída (default: mesmo nome sem .enc)}';

    protected $description = 'Decifra um backup .sql.gz.enc usando BACKUP_PASSPHRASE';

    public function handle(BackupService $backup): int
    {
        $senha = $backup->passphrase();
        if ($senha === null) {
            $this->error('BACKUP_PASSPHRASE não está configurada.');
            return self::FAILURE;
        }

        $arg     = $this->argument('arquivo');
        $origem  = is_file($arg) ? $arg : $backup->diretorio() . '/' . basename($arg);
        if (!is_file($origem)) {
            $this->error("Arquivo não encontrado: {$origem}");
            return self::FAILURE;
        }

        $saida = $this->option('saida') ?: preg_replace('/\.enc$/', '', $origem);
        if ($saida === $origem) {
            $saida .= '.sql.gz';
        }

        try {
            $backup->decifrar($origem, $saida, $senha);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Decifrado: {$saida}");
        return self::SUCCESS;
    }
}
