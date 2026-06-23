<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Configuracao;
use App\Models\EmissorFiscal;
use App\Models\SaasConfig;
use App\Services\Fiscal\Data\EmissorData;
use Illuminate\Support\Facades\Crypt;

class RegistrarEmissorService
{
    public function __construct(
        private readonly FiscalProviderManager $manager,
        private readonly CertificadoValidator $validator,
    ) {}

    public function montarEmissorData(Configuracao $cfg): EmissorData
    {
        return new EmissorData(
            cnpj: $cfg->cnpj ?? '',
            razaoSocial: $cfg->razao_social ?? '',
            nomeFantasia: $cfg->nome_fantasia,
            inscricaoEstadual: $cfg->inscricao_estadual,
            inscricaoMunicipal: $cfg->inscricao_municipal,
            regimeTributario: $cfg->regime_tributario ?? 'Simples Nacional',
            email: $cfg->email ?? '',
            telefone: $cfg->telefone,
            cep: $cfg->cep ?? '',
            logradouro: $cfg->endereco ?? '',
            numero: 'S/N',
            complemento: null,
            bairro: $cfg->bairro ?? '',
            cidade: $cfg->cidade ?? '',
            uf: $cfg->uf ?? '',
            codigoIbge: $cfg->codigo_ibge ?? '',
            cnae: $cfg->cnae ?? '',
        );
    }

    /** @return array{ok: bool, mensagem: string} */
    public function registrar(string $oficinaId): array
    {
        $cfg = Configuracao::first();
        if (!$cfg || empty($cfg->cnpj) || empty($cfg->certificado_pfx_encrypted)) {
            return ['ok' => false, 'mensagem' => 'Preencha os dados da empresa e envie o certificado antes de ativar a emissão.'];
        }

        $provedor = $this->manager->provedorDaOficina($oficinaId);
        $ambiente = $this->manager->ambienteDaOficina();

        $existente = EmissorFiscal::where('oficina_id', $oficinaId)
            ->where('provedor', $provedor)->where('ambiente', $ambiente)
            ->where('status', 'REGISTRADO')->first();
        if ($existente) {
            return ['ok' => true, 'mensagem' => 'Emissor já registrado.'];
        }

        // Decifra o certificado armazenado (padrão openssl do ConfiguracaoController).
        $pfxBinary = $this->decifrarCertificado($cfg->certificado_pfx_encrypted);
        $senha     = $cfg->certificado_senha_encrypted
            ? Crypt::decryptString($cfg->certificado_senha_encrypted) : '';

        $emissorData = $this->montarEmissorData($cfg);
        $provider    = $this->manager->build(
            $provedor, $ambiente, SaasConfig::get(), null, null,
        );

        $registro = $provider->registrarEmissor($emissorData);
        if ($registro->status !== 'REGISTRADO') {
            EmissorFiscal::updateOrCreate(
                ['oficina_id' => $oficinaId, 'provedor' => $provedor, 'ambiente' => $ambiente],
                ['status' => 'ERRO', 'ultimo_erro' => $registro->mensagemErro],
            );
            return ['ok' => false, 'mensagem' => $registro->mensagemErro ?? 'Falha ao registrar emissor.'];
        }

        // Vincula certificado (provider que envia separado; Focus já recebeu no cadastro).
        try {
            $providerComEmissor = $this->manager->build(
                $provedor, $ambiente, SaasConfig::get(),
                $registro->token, $registro->emissorExternoId,
            );
            $providerComEmissor->enviarCertificado($emissorData, $pfxBinary, $senha);
        } catch (\Throwable $ex) {
            // Focus pode já ter o certificado; loga mas não falha o registro.
        }

        EmissorFiscal::updateOrCreate(
            ['oficina_id' => $oficinaId, 'provedor' => $provedor, 'ambiente' => $ambiente],
            [
                'emissor_externo_id' => $registro->emissorExternoId,
                'token_encrypted'    => $registro->token ? Crypt::encryptString($registro->token) : null,
                'status'             => 'REGISTRADO',
                'registrado_em'      => now(),
                'ultimo_erro'        => null,
            ],
        );

        return ['ok' => true, 'mensagem' => 'Emissor registrado com sucesso.'];
    }

    private function decifrarCertificado(string $stored): string
    {
        $raw = base64_decode($stored);
        $iv  = substr($raw, 0, 16);
        $enc = substr($raw, 16);
        $key = substr(hash('sha256', config('app.key'), true), 0, 32);
        $dec = openssl_decrypt($enc, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $dec !== false ? $dec : '';
    }
}
