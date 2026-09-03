<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BackupService;
use PHPUnit\Framework\TestCase;

class BackupServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/backup_test_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function service(): BackupService
    {
        return new BackupService($this->dir);
    }

    private function criarGz(string $nome, string $conteudo, int $mtime): string
    {
        $path = $this->dir . '/' . $nome;
        $fh = gzopen($path, 'wb');
        gzwrite($fh, $conteudo);
        gzclose($fh);
        touch($path, $mtime);
        return $path;
    }

    public function test_comprimir_produz_gz_valido_que_descomprime_no_original(): void
    {
        $origem = $this->dir . '/dump.sql';
        $conteudo = str_repeat("CREATE TABLE x (id int);\nINSERT INTO x VALUES (1);\n", 2000);
        file_put_contents($origem, $conteudo);

        $destino = $this->dir . '/dump.sql.gz';
        $svc = $this->service();
        $svc->comprimir($origem, $destino);

        $this->assertFileExists($destino);
        $this->assertTrue($svc->verificarGzip($destino), 'o .gz gerado deve passar na verificação de integridade');
        $this->assertSame($conteudo, gzdecode((string) file_get_contents($destino)));
    }

    public function test_verifica_gz_integro(): void
    {
        $path = $this->criarGz('ok.sql.gz', str_repeat('linha de dump;\n', 500), time());

        $this->assertTrue($this->service()->verificarGzip($path));
    }

    public function test_detecta_gz_truncado(): void
    {
        $path = $this->criarGz('trunc.sql.gz', str_repeat('conteudo importante;\n', 1000), time());
        // Corta os últimos 40 bytes — destrói o trailer (CRC32 + ISIZE).
        $bytes = file_get_contents($path);
        file_put_contents($path, substr($bytes, 0, -40));

        $this->assertFalse($this->service()->verificarGzip($path));
    }

    public function test_poda_mantem_os_n_mais_recentes(): void
    {
        $base = time();
        $this->criarGz('backup_a.sql.gz', 'a', $base - 300);
        $this->criarGz('backup_b.sql.gz', 'b', $base - 200);
        $this->criarGz('backup_c.sql.gz', 'c', $base - 100);
        $this->criarGz('backup_d.sql.gz', 'd', $base);

        $removidos = $this->service()->podarAntigos(2);

        sort($removidos);
        $this->assertSame(['backup_a.sql.gz', 'backup_b.sql.gz'], $removidos);
        $this->assertFileDoesNotExist($this->dir . '/backup_a.sql.gz');
        $this->assertFileExists($this->dir . '/backup_c.sql.gz');
        $this->assertFileExists($this->dir . '/backup_d.sql.gz');
    }

    public function test_poda_remove_o_sha256_irmao(): void
    {
        $this->criarGz('backup_x.sql.gz', 'x', time() - 100);
        file_put_contents($this->dir . '/backup_x.sql.gz.sha256', 'abc  backup_x.sql.gz');
        $this->criarGz('backup_y.sql.gz', 'y', time());

        $this->service()->podarAntigos(1);

        $this->assertFileDoesNotExist($this->dir . '/backup_x.sql.gz.sha256');
    }

    public function test_poda_nao_apaga_nada_quando_ha_menos_que_o_limite(): void
    {
        $this->criarGz('backup_1.sql.gz', '1', time());

        $this->assertSame([], $this->service()->podarAntigos(14));
        $this->assertFileExists($this->dir . '/backup_1.sql.gz');
    }

    public function test_cifra_e_decifra_round_trip(): void
    {
        exec('openssl version 2>&1', $o, $code);
        if ($code !== 0) {
            $this->markTestSkipped('openssl não disponível neste ambiente.');
        }

        $original = $this->dir . '/dados.sql.gz';
        $conteudo = random_bytes(4096) . 'CONTEUDO-SENSIVEL' . random_bytes(4096);
        file_put_contents($original, $conteudo);

        $enc = $this->dir . '/dados.sql.gz.enc';
        $svc = $this->service();
        $svc->cifrar($original, $enc, 'senha-secreta-123');

        // O arquivo cifrado não pode conter o texto em claro.
        $this->assertStringNotContainsString('CONTEUDO-SENSIVEL', (string) file_get_contents($enc));

        $volta = $this->dir . '/volta.sql.gz';
        $svc->decifrar($enc, $volta, 'senha-secreta-123');

        $this->assertSame($conteudo, file_get_contents($volta));
    }

    public function test_decifra_com_senha_errada_falha(): void
    {
        exec('openssl version 2>&1', $o, $code);
        if ($code !== 0) {
            $this->markTestSkipped('openssl não disponível neste ambiente.');
        }

        $original = $this->dir . '/x.sql.gz';
        file_put_contents($original, str_repeat('abc', 1000));
        $enc = $this->dir . '/x.sql.gz.enc';
        $svc = $this->service();
        $svc->cifrar($original, $enc, 'senha-certa');

        $this->expectException(\RuntimeException::class);
        $svc->decifrar($enc, $this->dir . '/nope.sql.gz', 'senha-errada');
    }
}
