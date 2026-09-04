<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Jobs\GerarBackupJob;
use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    private string $backupPath;

    public function __construct(private readonly BackupService $backup)
    {
        $this->backupPath = $backup->diretorio();
    }

    public function gerar(): JsonResponse
    {
        // Enfileira em vez de rodar síncrono: o container web roda
        // `artisan serve` (single-thread) e um pg_dump longo congelaria a API
        // inteira. O job roda no worker; o frontend acompanha por polling
        // de listar() (um arquivo novo aparecendo = backup pronto).
        GerarBackupJob::dispatch();

        return response()->json([
            'status'  => 'enfileirado',
            'message' => 'Backup em andamento em segundo plano. O arquivo aparece na lista quando terminar.',
        ], 202);
    }

    public function listar(): JsonResponse
    {
        // .sql.gz e .sql.gz.enc — o .sha256 irmão é metadado, não um backup.
        $files = array_merge(
            glob($this->backupPath . '/*.sql.gz') ?: [],
            glob($this->backupPath . '/*.sql.gz.enc') ?: [],
        );

        $backups = array_map(function (string $file) {
            $sha     = @file_get_contents($file . '.sha256');
            $cifrado = str_ends_with($file, '.enc');
            return [
                'arquivo'   => basename($file),
                'tamanho'   => filesize($file),
                'checksum'  => $sha ? strtok(trim($sha), ' ') : null,
                'cifrado'   => $cifrado,
                // Não dá pra verificar o trailer gzip de um arquivo cifrado —
                // a integridade dele foi conferida na geração, antes de cifrar.
                'integro'   => $cifrado ? null : $this->backup->verificarGzip($file),
                'criado_em' => date('Y-m-d H:i:s', (int) filemtime($file)),
            ];
        }, $files);

        usort($backups, fn($a, $b) => strcmp($b['criado_em'], $a['criado_em']));

        return response()->json(['data' => array_values($backups)]);
    }

    /** Só nomes no formato exato que gerar() produz — nada de path, wildcard ou sidecar. */
    private function nomeValido(string $arquivo): bool
    {
        // O sufixo opcional entra como "_pre-deploy" etc. (underscore + [a-z0-9-]).
        return (bool) preg_match('/^backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}(_[a-z0-9\-]+)?\.sql\.gz(\.enc)?$/i', $arquivo);
    }

    /**
     * Gera uma URL assinada e curta (3 min) para o download. O frontend
     * navega direto para ela — o browser faz streaming pro disco, sem
     * carregar o arquivo inteiro na memória (o fluxo antigo com
     * fetch()+blob() estourava com backups grandes).
     */
    public function gerarLink(string $arquivo): JsonResponse
    {
        if (!$this->nomeValido($arquivo)) {
            return response()->json(['message' => 'Nome de arquivo inválido.'], 422);
        }
        if (!file_exists($this->backupPath . '/' . $arquivo)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], 404);
        }

        return response()->json([
            'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'saas.backup.download',
                now()->addMinutes(3),
                ['arquivo' => $arquivo],
                absolute: false,
            ),
        ]);
    }

    public function download(string $arquivo): StreamedResponse|JsonResponse
    {
        return $this->streamArquivo($arquivo);
    }

    /** Rota assinada (sem auth:saas — a assinatura É a credencial). */
    public function downloadAssinado(string $arquivo): StreamedResponse|JsonResponse
    {
        return $this->streamArquivo($arquivo);
    }

    private function streamArquivo(string $arquivo): StreamedResponse|JsonResponse
    {
        if (!$this->nomeValido($arquivo)) {
            return response()->json(['message' => 'Nome de arquivo inválido.'], 422);
        }

        $filepath = $this->backupPath . '/' . $arquivo;

        if (!file_exists($filepath)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], 404);
        }

        return response()->streamDownload(function () use ($filepath) {
            $handle = fopen($filepath, 'rb');
            while (!feof($handle)) {
                echo fread($handle, 65536);
                flush();
            }
            fclose($handle);
        }, $arquivo, [
            'Content-Type'        => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $arquivo . '"',
            'Content-Length'      => (string) filesize($filepath),
        ]);
    }

    public function apagar(string $arquivo): JsonResponse
    {
        if (!$this->nomeValido($arquivo)) {
            return response()->json(['message' => 'Nome de arquivo inválido.'], 422);
        }

        $filepath = $this->backupPath . '/' . $arquivo;

        if (!file_exists($filepath)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], 404);
        }

        unlink($filepath);
        @unlink($filepath . '.sha256');

        return response()->json(['message' => 'Backup apagado com sucesso.']);
    }

    public function importar(Request $request): JsonResponse
    {
        $request->validate([
            // Alinhado com client_max_body_size do nginx (docker/nginx/mecanicapro.conf).
            'arquivo' => ['required', 'file', 'max:512000'],
        ]);

        $file         = $request->file('arquivo');
        $tmpPath      = $file->getPathname();
        $originalName = $file->getClientOriginalName();

        $host     = config('database.connections.pgsql.host');
        $port     = config('database.connections.pgsql.port', 5432);
        $database = config('database.connections.pgsql.database');
        $username = config('database.connections.pgsql.username');
        $password = config('database.connections.pgsql.password');

        $env     = 'PGPASSWORD=' . escapeshellarg((string) $password);
        $sqlPath = $tmpPath;
        $tmpSql  = null;
        $tmpGz   = null;
        $gzPath  = $tmpPath;

        // Backup cifrado (.sql.gz.enc): decifra pra um .gz temporário primeiro.
        if (str_ends_with($originalName, '.enc')) {
            $senha = $this->backup->passphrase();
            if ($senha === null) {
                return response()->json([
                    'message' => 'Este backup está cifrado, mas BACKUP_PASSPHRASE não está configurada no servidor. Nenhum dado foi alterado.',
                ], 422);
            }
            $tmpGz = $tmpPath . '_dec.sql.gz';
            try {
                $this->backup->decifrar($tmpPath, $tmpGz, $senha);
            } catch (\Throwable $e) {
                if (is_file($tmpGz)) { @unlink($tmpGz); }
                return response()->json([
                    'message' => 'Não foi possível decifrar o backup (senha do servidor diferente da usada na geração?). Nenhum dado foi alterado.',
                ], 422);
            }
            $gzPath = $tmpGz;
        }

        if (str_ends_with($originalName, '.gz') || $tmpGz !== null) {
            // Rejeita um .gz corrompido/truncado ANTES de mexer no banco.
            if (!$this->backup->verificarGzip($gzPath)) {
                if ($tmpGz !== null && is_file($tmpGz)) { @unlink($tmpGz); }
                return response()->json([
                    'message' => 'O arquivo enviado está corrompido ou incompleto. Nenhum dado foi alterado.',
                ], 422);
            }

            $tmpSql = $tmpPath . '_restore.sql';
            $src    = gzopen($gzPath, 'rb');
            $dst    = fopen($tmpSql, 'wb');
            while (!gzeof($src)) {
                fwrite($dst, (string) gzread($src, 65536));
            }
            gzclose($src);
            fclose($dst);
            if ($tmpGz !== null && is_file($tmpGz)) { @unlink($tmpGz); }
            $sqlPath = $tmpSql;
        }

        $psqlBase = sprintf(
            '%s psql -h %s -p %s -U %s -d %s -v ON_ERROR_STOP=1',
            $env,
            escapeshellarg((string) $host),
            escapeshellarg((string) $port),
            escapeshellarg((string) $username),
            escapeshellarg((string) $database)
        );

        // Em vez de apagar o schema direto, ele é renomeado para um schema de
        // backup temporário. O dump é restaurado num "public" novo e vazio —
        // como o pg_dump referencia "public.*" explicitamente em cada
        // statement, a restauração cai automaticamente no schema certo sem
        // precisar reescrever o dump. Se a restauração falhar por qualquer
        // motivo (SQL inválido, incompatibilidade de versão do psql, etc.),
        // o schema original é trazido de volta e nenhum dado é perdido.
        $backupSchema = '_restore_backup_' . date('Ymd_His');

        $renameCmd = $psqlBase . ' -c ' . escapeshellarg(
            sprintf('ALTER SCHEMA public RENAME TO %s; CREATE SCHEMA public;', $backupSchema)
        ) . ' 2>&1';

        exec($renameCmd, $renameOutput, $renameExitCode);

        if ($renameExitCode !== 0) {
            if ($tmpSql && file_exists($tmpSql)) {
                unlink($tmpSql);
            }

            return response()->json([
                'message' => 'Erro ao preparar o banco para a restauração. Nenhum dado foi alterado.',
                'detalhe' => implode("\n", array_slice($renameOutput, -10)),
            ], 500);
        }

        $restoreCmd = $psqlBase . ' -f ' . escapeshellarg($sqlPath) . ' 2>&1';
        exec($restoreCmd, $output, $exitCode);

        if ($tmpSql && file_exists($tmpSql)) {
            unlink($tmpSql);
        }

        if ($exitCode !== 0) {
            $rollbackCmd = $psqlBase . ' -c ' . escapeshellarg(
                sprintf('DROP SCHEMA public CASCADE; ALTER SCHEMA %s RENAME TO public;', $backupSchema)
            ) . ' 2>&1';
            exec($rollbackCmd, $rollbackOutput, $rollbackExitCode);

            if ($rollbackExitCode !== 0) {
                return response()->json([
                    'message' => "Erro ao importar backup, e a reversão automática também falhou. Os dados originais estão preservados no schema \"{$backupSchema}\" — restaure-o manualmente (ALTER SCHEMA ... RENAME TO public) antes de tentar novamente.",
                    'detalhe' => implode("\n", array_slice($output, -10)) . "\n---\n" . implode("\n", array_slice($rollbackOutput, -10)),
                ], 500);
            }

            return response()->json([
                'message' => 'Erro ao importar backup. A restauração foi cancelada e os dados originais foram preservados.',
                'detalhe' => implode("\n", array_slice($output, -10)),
            ], 500);
        }

        // O schema de backup NÃO é apagado automaticamente aqui. Extensões
        // (ex.: pgcrypto) pertencem a um único schema do banco mas são usadas
        // por tabelas em qualquer schema — um DROP SCHEMA ... CASCADE no
        // schema antigo pode arrastar consigo uma extensão que as tabelas
        // recém-restauradas em "public" ainda dependem, destruindo os dados
        // que acabaram de ser restaurados (isso foi reproduzido manualmente
        // durante o diagnóstico deste bug). Fica a critério do admin apagar
        // o schema antigo manualmente quando tiver certeza de que é seguro.

        return response()->json([
            'message' => "Backup importado com sucesso. Os dados anteriores foram preservados no schema \"{$backupSchema}\" — remova-o manualmente quando não precisar mais dele.",
        ]);
    }
}
