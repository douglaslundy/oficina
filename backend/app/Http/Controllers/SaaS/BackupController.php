<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
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
        try {
            $resultado = $this->backup->gerar();
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Erro ao gerar backup.',
                'detalhe' => $e->getMessage(),
            ], 500);
        }

        // Mantém o disco sob controle mesmo com geração manual frequente.
        $this->backup->podarAntigos((int) config('backup.manter', 14));

        return response()->json($resultado);
    }

    public function listar(): JsonResponse
    {
        // Só .sql.gz — o .sha256 irmão é metadado, não um backup.
        $files = glob($this->backupPath . '/*.sql.gz') ?: [];

        $backups = array_map(function (string $file) {
            $sha = @file_get_contents($file . '.sha256');
            return [
                'arquivo'   => basename($file),
                'tamanho'   => filesize($file),
                'checksum'  => $sha ? strtok(trim($sha), ' ') : null,
                'integro'   => $this->backup->verificarGzip($file),
                'criado_em' => date('Y-m-d H:i:s', (int) filemtime($file)),
            ];
        }, $files);

        usort($backups, fn($a, $b) => strcmp($b['criado_em'], $a['criado_em']));

        return response()->json(['data' => array_values($backups)]);
    }

    /** Só nomes no formato exato que gerar() produz — nada de path, wildcard ou sidecar. */
    private function nomeValido(string $arquivo): bool
    {
        return (bool) preg_match('/^backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}[a-z0-9\-]*\.sql\.gz$/i', $arquivo);
    }

    public function download(string $arquivo): StreamedResponse|JsonResponse
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

        if (str_ends_with($originalName, '.gz')) {
            // Rejeita um .gz corrompido/truncado ANTES de mexer no banco.
            if (!$this->backup->verificarGzip($tmpPath)) {
                return response()->json([
                    'message' => 'O arquivo .gz enviado está corrompido ou incompleto. Nenhum dado foi alterado.',
                ], 422);
            }

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
