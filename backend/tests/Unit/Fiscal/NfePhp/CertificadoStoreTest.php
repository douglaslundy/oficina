<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Services\Fiscal\NfePhp\CertificadoStore;
use Tests\TestCase;

class CertificadoStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Este worktree local não tem .env (nem APP_KEY) — ao contrário do
        // ambiente real/Docker, onde a chave já vem configurada. Sem chave,
        // nem config('app.key') (usado por RegistrarEmissorService::decifrarPfx)
        // nem Crypt::encryptString funcionam. Garantimos aqui uma chave válida
        // só para este teste, sem tocar phpunit.xml/.env compartilhados.
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
    }

    private function gerarPfxDeTeste(string $senha): string
    {
        $configArgs = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $privateKey = openssl_pkey_new($configArgs);
        $csr = openssl_csr_new(['commonName' => 'Teste'], $privateKey, $configArgs);
        $cert = openssl_csr_sign($csr, null, $privateKey, 365, $configArgs);
        openssl_pkcs12_export($cert, $pfxOut, $privateKey, $senha);
        return $pfxOut;
    }

    private function configuracaoComCertificado(string $senha = 'senha123'): Configuracao
    {
        $pfx = $this->gerarPfxDeTeste($senha);
        $key = substr(hash('sha256', config('app.key'), true), 0, 32);
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($pfx, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        $cfg = new Configuracao();
        $cfg->certificado_pfx_encrypted = base64_encode($iv . $enc);
        $cfg->certificado_senha_encrypted = \Illuminate\Support\Facades\Crypt::encryptString($senha);
        return $cfg;
    }

    public function test_obter_decifra_pfx_e_senha(): void
    {
        $cfg = $this->configuracaoComCertificado('minhasenha');
        $store = new CertificadoStore();

        $resultado = $store->obter($cfg);

        $this->assertSame('minhasenha', $resultado['senha']);
        $this->assertNotEmpty($resultado['pfx']);
        // Confirma que o PFX decifrado é válido de verdade (abre com a senha certa).
        $this->assertTrue(openssl_pkcs12_read($resultado['pfx'], $certs, 'minhasenha'));
    }

    public function test_obter_lanca_excecao_sem_certificado(): void
    {
        $cfg = new Configuracao();
        $store = new CertificadoStore();

        $this->expectException(\RuntimeException::class);
        $store->obter($cfg);
    }

    public function test_como_arquivo_temporario_apaga_arquivo_depois(): void
    {
        $cfg = $this->configuracaoComCertificado('minhasenha');
        $store = new CertificadoStore();
        $caminhoCapturado = null;

        $resultado = $store->comoArquivoTemporario($cfg, function (string $caminho) use (&$caminhoCapturado) {
            $caminhoCapturado = $caminho;
            $this->assertFileExists($caminho);
            return 'ok';
        });

        $this->assertSame('ok', $resultado);
        $this->assertNotNull($caminhoCapturado);
        $this->assertFileDoesNotExist($caminhoCapturado);
    }

    public function test_como_arquivo_temporario_apaga_arquivo_mesmo_se_callback_lancar(): void
    {
        $cfg = $this->configuracaoComCertificado('minhasenha');
        $store = new CertificadoStore();
        $caminhoCapturado = null;

        try {
            $store->comoArquivoTemporario($cfg, function (string $caminho) use (&$caminhoCapturado) {
                $caminhoCapturado = $caminho;
                throw new \RuntimeException('falha simulada');
            });
            $this->fail('Deveria ter propagado a exceção.');
        } catch (\RuntimeException $e) {
            $this->assertSame('falha simulada', $e->getMessage());
        }

        $this->assertNotNull($caminhoCapturado);
        $this->assertFileDoesNotExist($caminhoCapturado);
    }
}
