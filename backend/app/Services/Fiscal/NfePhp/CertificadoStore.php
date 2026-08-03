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
 * no finally, mesmo se a escrita, o chmod ou o callback lançarem exceção —
 * uma vez que tempnam() cria o arquivo em disco, nada entre esse ponto e o
 * retorno da função pode deixá-lo órfão com o PFX em texto claro.
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

    /**
     * @param callable(string $caminho, string $senha): mixed $callback Recebe também a senha já
     *   decifrada (mesma chamada a obter() que já busca o pfx), para que o chamador não precise
     *   decifrar o certificado uma segunda vez só para pegar a senha.
     */
    public function comoArquivoTemporario(Configuracao $cfg, callable $callback): mixed
    {
        $dados = $this->obter($cfg);
        $caminho = tempnam(sys_get_temp_dir(), 'nfephp_cert_');
        if ($caminho === false) {
            throw new \RuntimeException('Não foi possível criar arquivo temporário para o certificado.');
        }

        try {
            if (file_put_contents($caminho, $dados['pfx']) === false) {
                throw new \RuntimeException('Não foi possível escrever o certificado no arquivo temporário.');
            }
            chmod($caminho, 0600);

            return $callback($caminho, $dados['senha']);
        } finally {
            if (file_exists($caminho)) {
                unlink($caminho);
            }
        }
    }
}
