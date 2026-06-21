<?php
declare(strict_types=1);

namespace App\Services;

use App\Mail\AlertaMail;
use App\Models\SaasConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailService
{
    /** SMTP está configurado e ativo na plataforma? */
    public function configurado(): bool
    {
        return SaasConfig::get()->smtpConfigurado();
    }

    /**
     * Envia um e-mail usando o SMTP configurado na plataforma (SaaS).
     *
     * @param string[] $destinatarios
     * @return array{ok: bool, error?: string}
     */
    public function enviar(array $destinatarios, string $assunto, string $corpo): array
    {
        $cfg = SaasConfig::get();
        if (!$cfg->smtpConfigurado()) {
            return ['ok' => false, 'error' => 'SMTP não configurado. Configure em SaaS Admin → Configurações.'];
        }

        $destinatarios = array_values(array_unique(array_filter(
            array_map('trim', $destinatarios),
            fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false,
        )));

        if (empty($destinatarios)) {
            return ['ok' => false, 'error' => 'Nenhum destinatário de e-mail válido informado.'];
        }

        $this->configurarMailer($cfg);

        try {
            Mail::mailer('saas_smtp')->to($destinatarios)->send(
                new AlertaMail(
                    assunto: $assunto,
                    corpo: $corpo,
                    fromAddress: (string) $cfg->smtp_from_address,
                    fromName: (string) $cfg->smtp_from_name,
                )
            );
            return ['ok' => true];
        } catch (\Throwable $e) {
            Log::warning('Falha no envio de e-mail: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function enviarTeste(string $destinatario): array
    {
        return $this->enviar(
            [$destinatario],
            'MecânicaPro — E-mail de teste',
            "✅ Este é um e-mail de teste do MecânicaPro.\n\nSe você recebeu esta mensagem, a configuração de SMTP está funcionando corretamente!"
        );
    }

    /** Configura, em runtime, um mailer SMTP a partir das credenciais do SaaS. */
    private function configurarMailer(SaasConfig $cfg): void
    {
        config([
            'mail.mailers.saas_smtp' => [
                'transport'  => 'smtp',
                'host'       => $cfg->smtp_host,
                'port'       => $cfg->smtp_port,
                'encryption' => $cfg->smtp_encryption ?: null,
                'username'   => $cfg->smtp_username ?: null,
                'password'   => $cfg->getRawOriginal('smtp_password') ?: null,
                'timeout'    => 15,
            ],
        ]);
    }
}
