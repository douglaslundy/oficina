<?php
declare(strict_types=1);

namespace App\Services\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Services\Fiscal\RegistrarEmissorService;
use Illuminate\Support\Facades\Crypt;

/**
 * Decifra o certificado .pfx da oficina sob demanda, em memória. O .pfx
 * nunca é escrito em disco de forma persistente — comoArquivoTemporario()
 * existe só porque a biblioteca nfse-nacional/nfse-php exige um caminho de
 * arquivo (NfseContext::certificatePath), diferente do sped-nfe (que aceita
 * bytes direto via Certificate::readPfx()). O arquivo temporário é apagado
 * no finally, mesmo se o callback lançar exceção.
 */
class CertificadoStore
{
    /** @return array{pfx: string, senha: string} */
    public function obter(Configuracao $cfg): array
    {
        if (empty($cfg->certificado_pfx_encrypted) || empty($cfg->certificado_senha_encrypted)) {
            throw new \RuntimeException('Certificado digital não configurado para esta oficina.');
        }

        $pfx = RegistrarEmissorService::decifrarPfx($cfg->certificado_pfx_encrypted);
        if ($pfx === '') {
            throw new \RuntimeException('Não foi possível decifrar o certificado armazenado.');
        }

        $senha = Crypt::decryptString($cfg->certificado_senha_encrypted);

        return ['pfx' => $pfx, 'senha' => $senha];
    }

    public function comoArquivoTemporario(Configuracao $cfg, callable $callback): mixed
    {
        $dados = $this->obter($cfg);
        $caminho = tempnam(sys_get_temp_dir(), 'nfephp_cert_');
        if ($caminho === false) {
            throw new \RuntimeException('Não foi possível criar arquivo temporário para o certificado.');
        }

        file_put_contents($caminho, $dados['pfx']);
        chmod($caminho, 0600);

        try {
            return $callback($caminho);
        } finally {
            if (file_exists($caminho)) {
                unlink($caminho);
            }
        }
    }
}
