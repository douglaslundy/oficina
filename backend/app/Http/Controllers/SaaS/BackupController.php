<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    private string $backupPath;

    public function __construct()
    {
        $this->backupPath = storage_path('backups');
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
    }

    public function gerar(): JsonResponse
    {
        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $this->backupPath . '/' . $filename;

        $host     = config('database.connections.pgsql.host');
        $port     = config('database.connections.pgsql.port', 5432);
        $database = config('database.connections.pgsql.database');
        $username = config('database.connections.pgsql.username');
        $password = config('database.connections.pgsql.password');

        $env = 'PGPASSWORD=' . escapeshellarg((string) $password);
        $cmd = sprintf(
            '%s pg_dump -h %s -p %s -U %s -d %s --no-owner --no-acl --clean --if-exists -F p -f %s 2>&1',
            $env,
            escapeshellarg((string) $host),
            escapeshellarg((string) $port),
            escapeshellarg((string) $username),
            escapeshellarg((string) $database),
            escapeshellarg($filepath)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return response()->json([
                'message' => 'Erro ao gerar backup.',
                'detalhe' => implode("\n", $output),
            ], 500);
        }

        $gzFilepath = $filepath . '.gz';
        $source = fopen($filepath, 'rb');
        $dest   = gzopen($gzFilepath, 'wb9');
        while (!feof($source)) {
            gzwrite($dest, (string) fread($source, 65536));
        }
        fclose($source);
        gzclose($dest);
        unlink($filepath);

        return response()->json([
            'arquivo'   => $filename . '.gz',
            'tamanho'   => filesize($gzFilepath),
            'criado_em' => date('Y-m-d H:i:s'),
        ]);
    }

    public function listar(): JsonResponse
    {
        $files = glob($this->backupPath . '/*.sql*') ?: [];

        $backups = array_map(function (string $file) {
            return [
                'arquivo'   => basename($file),
                'tamanho'   => filesize($file),
                'criado_em' => date('Y-m-d H:i:s', (int) filemtime($file)),
            ];
        }, $files);

        usort($backups, fn($a, $b) => strcmp($b['criado_em'], $a['criado_em']));

        return response()->json(['data' => array_values($backups)]);
    }

    public function download(string $arquivo): StreamedResponse|JsonResponse
    {
        if (str_contains($arquivo, '..') || str_contains($arquivo, '/')) {
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
        if (str_contains($arquivo, '..') || str_contains($arquivo, '/')) {
            return response()->json(['message' => 'Nome de arquivo inválido.'], 422);
        }

        $filepath = $this->backupPath . '/' . $arquivo;

        if (!file_exists($filepath)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], 404);
        }

        unlink($filepath);

        return response()->json(['message' => 'Backup apagado com sucesso.']);
    }

    public function importar(Request $request): JsonResponse
    {
        $request->validate([
            'arquivo' => ['required', 'file', 'max:204800'],
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

        if (str_ends_with($originalName, '.gz')) {
            $tmpSql = $tmpPath . '_restore.sql';
            $src    = gzopen($tmpPath, 'rb');
            $dst    = fopen($tmpSql, 'wb');
            while (!gzeof($src)) {
                fwrite($dst, (string) gzread($src, 65536));
            }
            gzclose($src);
            fclose($dst);
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

        $cleanupCmd = $psqlBase . ' -c ' . escapeshellarg(
            sprintf('DROP SCHEMA %s CASCADE;', $backupSchema)
        ) . ' 2>&1';
        exec($cleanupCmd, $cleanupOutput, $cleanupExitCode);
        // Falha na limpeza não compromete a restauração em si — só deixa um
        // schema órfão para remover depois; não é motivo para reportar erro.

        return response()->json(['message' => 'Backup importado com sucesso.']);
    }
}
