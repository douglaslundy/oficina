<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

class CertificadoValidator
{
    /**
     * @return array{ok: bool, validade: ?string, nome: ?string, erro: ?string}
     */
    public function validar(string $pfxBinary, string $senha): array
    {
        $certs = [];
        if (!openssl_pkcs12_read($pfxBinary, $certs, $senha)) {
            return ['ok' => false, 'validade' => null, 'nome' => null, 'erro' => 'Certificado inválido ou senha incorreta.'];
        }

        $info = openssl_x509_parse($certs['cert'] ?? '');
        if ($info === false) {
            return ['ok' => false, 'validade' => null, 'nome' => null, 'erro' => 'Não foi possível ler o certificado.'];
        }

        $validade = isset($info['validTo_time_t'])
            ? date('Y-m-d', (int) $info['validTo_time_t'])
            : null;
        $nome = $info['subject']['CN'] ?? null;

        if ($validade !== null && strtotime($validade) < time()) {
            return ['ok' => false, 'validade' => $validade, 'nome' => $nome, 'erro' => 'Certificado expirado.'];
        }

        return ['ok' => true, 'validade' => $validade, 'nome' => $nome, 'erro' => null];
    }
}
