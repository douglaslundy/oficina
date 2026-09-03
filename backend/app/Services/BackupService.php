<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Geração e manutenção de backups do PostgreSQL.
 *
 * Um backup é um `pg_dump` em texto (apenas do schema `public`), comprimido
 * em gzip, com um arquivo irmão `.sha256` para verificação de integridade.
 * A restrição a `-n public` é deliberada: `importar()` no controller renomeia
 * só o schema `public`, então um dump que contenha outros schemas
 * (ex.: `_restore_backup_*` deixados por uma restauração anterior) ficaria
 * irrestaurável.
 */
class BackupService
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? storage_path('backups');
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    public function diretorio(): string
    {
        return $this->dir;
    }

    /**
     * Gera um backup completo e verificado.
     *
     * @return array{arquivo: string, tamanho: int, checksum: string, criado_em: string}
     */
    public function gerar(?string $sufixo = null): array
    {
        $marca    = date('Y-m-d_H-i-s') . ($sufixo ? '_' . preg_replace('/[^a-z0-9\-]/i', '', $sufixo) : '');
        $sqlPath  = $this->dir . '/backup_' . $marca . '.sql';
        $gzPath   = $sqlPath . '.gz';

        [$exitCode, $output] = $this->rodarPgDump($sqlPath);

        if ($exitCode !== 0) {
            @unlink($sqlPath);
            throw new RuntimeException('pg_dump falhou (exit ' . $exitCode . '): ' . implode("\n", array_slice($output, -10)));
        }

        if (!is_file($sqlPath) || filesize($sqlPath) === 0) {
            @unlink($sqlPath);
            throw new RuntimeException('pg_dump terminou sem erro mas o arquivo .sql ficou vazio.');
        }

        if (!$this->pareceUmDump($sqlPath)) {
            @unlink($sqlPath);
            throw new RuntimeException('O arquivo gerado não tem o cabeçalho esperado de um pg_dump.');
        }

        $this->comprimir($sqlPath, $gzPath);
        @unlink($sqlPath);

        if (!$this->verificarGzip($gzPath)) {
            @unlink($gzPath);
            throw new RuntimeException('O arquivo .gz gerado está corrompido/truncado (falha na verificação de integridade).');
        }

        // Integridade verificada ANTES de cifrar (depois não dá pra checar o
        // trailer gzip sem a senha). A partir daqui o arquivo final pode ser
        // o .gz ou o .gz.enc.
        $final = $gzPath;
        $senha = $this->passphrase();
        if ($senha !== null) {
            $encPath = $gzPath . '.enc';
            $this->cifrar($gzPath, $encPath, $senha);
            @unlink($gzPath);
            $final = $encPath;
        }

        $checksum = hash_file('sha256', $final);
        file_put_contents($final . '.sha256', $checksum . '  ' . basename($final) . "\n");

        return [
            'arquivo'   => basename($final),
            'tamanho'   => (int) filesize($final),
            'checksum'  => $checksum,
            'cifrado'   => $final !== $gzPath,
            'criado_em' => date('Y-m-d H:i:s'),
        ];
    }

    public function passphrase(): ?string
    {
        $p = config('backup.passphrase');
        return is_string($p) && $p !== '' ? $p : null;
    }

    /**
     * Cifra um arquivo com AES-256-CBC (openssl, PBKDF2 + salt). Formato
     * padrão do `openssl enc` — decifrável em qualquer máquina com openssl,
     * sem depender deste sistema.
     */
    public function cifrar(string $origem, string $destino, string $senha): void
    {
        putenv('BACKUP_OPENSSL_PASS=' . $senha);
        $cmd = sprintf(
            'openssl enc -aes-256-cbc -pbkdf2 -salt -in %s -out %s -pass env:BACKUP_OPENSSL_PASS 2>&1',
            escapeshellarg($origem),
            escapeshellarg($destino),
        );
        exec($cmd, $output, $exitCode);
        putenv('BACKUP_OPENSSL_PASS');

        if ($exitCode !== 0 || !is_file($destino) || filesize($destino) === 0) {
            @unlink($destino);
            throw new RuntimeException('Falha ao cifrar o backup (openssl exit ' . $exitCode . '): ' . implode("\n", array_slice($output, -5)));
        }
    }

    public function decifrar(string $origem, string $destino, string $senha): void
    {
        putenv('BACKUP_OPENSSL_PASS=' . $senha);
        $cmd = sprintf(
            'openssl enc -d -aes-256-cbc -pbkdf2 -in %s -out %s -pass env:BACKUP_OPENSSL_PASS 2>&1',
            escapeshellarg($origem),
            escapeshellarg($destino),
        );
        exec($cmd, $output, $exitCode);
        putenv('BACKUP_OPENSSL_PASS');

        if ($exitCode !== 0 || !is_file($destino) || filesize($destino) === 0) {
            @unlink($destino);
            throw new RuntimeException('Falha ao decifrar o backup — senha errada ou arquivo corrompido.');
        }
    }

    /**
     * Verifica a integridade de um .gz descomprimindo o conteúdo inteiro e
     * conferindo contra o trailer do formato gzip (CRC32 dos dados + ISIZE,
     * os últimos 8 bytes). É o que `gzip -t` faz — pega truncamento por
     * disco cheio, download interrompido, escrita parcial, etc.
     */
    public function verificarGzip(string $path): bool
    {
        $tamanho = @filesize($path);
        if ($tamanho === false || $tamanho < 18) {
            return false; // 10 (header mínimo) + 8 (trailer) — abaixo disso nem é gzip
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        fseek($fh, -8, SEEK_END);
        $trailer = (string) fread($fh, 8);
        fclose($fh);
        if (strlen($trailer) !== 8) {
            return false;
        }
        $crcEsperado   = unpack('V', substr($trailer, 0, 4))[1];
        $isizeEsperado = unpack('V', substr($trailer, 4, 4))[1];

        $gz = @gzopen($path, 'rb');
        if ($gz === false) {
            return false;
        }
        $crc = hash_init('crc32b');
        $len = 0;
        while (!gzeof($gz)) {
            $chunk = @gzread($gz, 262144);
            if ($chunk === false) {
                gzclose($gz);
                return false;
            }
            hash_update($crc, $chunk);
            $len += strlen($chunk);
        }
        gzclose($gz);

        return hexdec(hash_final($crc)) === $crcEsperado
            && ($len & 0xFFFFFFFF) === $isizeEsperado;
    }

    /**
     * Remove os backups mais antigos, mantendo os $manter mais recentes.
     * Só considera arquivos .sql.gz e apaga o .sha256 irmão junto.
     *
     * @return list<string> nomes dos arquivos removidos
     */
    public function podarAntigos(int $manter): array
    {
        if ($manter < 0) {
            $manter = 0;
        }

        $arquivos = glob($this->dir . '/*.sql.gz') ?: [];
        usort($arquivos, static fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        $remover  = array_slice($arquivos, $manter);
        $removidos = [];

        foreach ($remover as $arquivo) {
            @unlink($arquivo);
            @unlink($arquivo . '.sha256');
            $removidos[] = basename($arquivo);
        }

        return $removidos;
    }

    /** @return array{0: int, 1: list<string>} [exitCode, output] */
    private function rodarPgDump(string $destino): array
    {
        $conn = config('database.connections.pgsql');

        $env = 'PGPASSWORD=' . escapeshellarg((string) ($conn['password'] ?? ''));
        $cmd = sprintf(
            '%s pg_dump -h %s -p %s -U %s -d %s -n public --no-owner --no-acl --clean --if-exists -F p -f %s 2>&1',
            $env,
            escapeshellarg((string) ($conn['host'] ?? 'localhost')),
            escapeshellarg((string) ($conn['port'] ?? 5432)),
            escapeshellarg((string) ($conn['username'] ?? '')),
            escapeshellarg((string) ($conn['database'] ?? '')),
            escapeshellarg($destino),
        );

        exec($cmd, $output, $exitCode);

        return [$exitCode, $output];
    }

    private function pareceUmDump(string $sqlPath): bool
    {
        $fh = fopen($sqlPath, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = (string) fread($fh, 4096);
        fclose($fh);

        return str_contains($head, 'PostgreSQL database dump');
    }

    /** Comprime $origem em gzip nível 9 para $destino, com checagem de erro de I/O. */
    public function comprimir(string $origem, string $destino): void
    {
        $src = fopen($origem, 'rb');
        $dst = gzopen($destino, 'wb9');
        if ($src === false || $dst === false) {
            throw new RuntimeException('Não foi possível abrir os arquivos para compressão do backup.');
        }

        while (!feof($src)) {
            $chunk = fread($src, 65536);
            if ($chunk === false) {
                fclose($src);
                gzclose($dst);
                throw new RuntimeException('Erro de leitura ao comprimir o backup.');
            }
            // gzwrite devolve os bytes escritos (>0), ou 0/false em erro. Como
            // o chunk aqui é sempre não-vazio, um write bem-sucedido é > 0.
            if ($chunk !== '' && !gzwrite($dst, $chunk)) {
                fclose($src);
                gzclose($dst);
                throw new RuntimeException('Erro de escrita ao comprimir o backup (disco cheio?).');
            }
        }

        fclose($src);
        // gzclose devolve bool (true em sucesso) — NÃO 0.
        if (!gzclose($dst)) {
            throw new RuntimeException('Erro ao finalizar o arquivo comprimido do backup.');
        }
    }
}
