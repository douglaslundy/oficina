<?php
declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BoasVindasOficina extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nomeAdmin,
        public readonly string $nomeOficina,
        public readonly string $email,
        public readonly string $senha,
        public readonly string $slug,
        public readonly string $urlAcesso,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bem-vindo ao MecânicaPro! Sua oficina está pronta.');
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->buildHtml());
    }

    private function buildHtml(): string
    {
        $nome        = htmlspecialchars($this->nomeAdmin);
        $oficina     = htmlspecialchars($this->nomeOficina);
        $email       = htmlspecialchars($this->email);
        $senha       = htmlspecialchars($this->senha);
        $slug        = htmlspecialchars($this->slug);
        $url         = htmlspecialchars($this->urlAcesso);

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Bem-vindo ao MecânicaPro!</title>
</head>
<body style="margin:0;padding:0;background:#0e0f11;font-family:'Helvetica Neue',Arial,sans-serif;-webkit-font-smoothing:antialiased;">
  <div style="max-width:560px;margin:40px auto;background:#161719;border-radius:12px;overflow:hidden;border:1px solid #2a2d33;">

    <!-- Header âmbar -->
    <div style="background:#f5a623;padding:28px 32px;text-align:center;">
      <div style="font-size:30px;font-weight:800;color:#000;letter-spacing:-.5px;font-family:Georgia,serif;">
        MecânicaPro
      </div>
      <div style="font-size:13px;color:rgba(0,0,0,.65);margin-top:5px;font-weight:500;">
        Sistema SaaS de Gestão para Oficinas
      </div>
    </div>

    <!-- Body -->
    <div style="padding:36px 32px 28px;">
      <h1 style="color:#e8eaf0;font-size:22px;font-weight:700;margin:0 0 10px;line-height:1.3;">
        Parabéns, {$nome}!<br>
        <span style="color:#f5a623;">Sua oficina está pronta.</span>
      </h1>
      <p style="color:#7a8090;font-size:14px;line-height:1.7;margin:0 0 28px;">
        A oficina <strong style="color:#e8eaf0;">{$oficina}</strong> foi cadastrada com sucesso
        na plataforma MecânicaPro. Use as credenciais abaixo para acessar o sistema.
      </p>

      <!-- Credenciais -->
      <div style="background:#1c1e21;border:1px solid #2a2d33;border-radius:10px;padding:22px 24px;margin-bottom:28px;">
        <div style="font-size:11px;font-weight:700;color:#7a8090;text-transform:uppercase;letter-spacing:.08em;margin-bottom:16px;">
          Seus dados de acesso
        </div>
        <table style="width:100%;border-collapse:collapse;">
          <tr>
            <td style="padding:7px 0;color:#7a8090;font-size:13px;width:80px;vertical-align:top;">E-mail</td>
            <td style="padding:7px 0;color:#e8eaf0;font-size:13px;font-family:'Courier New',monospace;">
              {$email}
            </td>
          </tr>
          <tr style="border-top:1px solid #2a2d33;">
            <td style="padding:7px 0;color:#7a8090;font-size:13px;vertical-align:top;">Senha</td>
            <td style="padding:7px 0;">
              <span style="color:#f5a623;font-size:15px;font-family:'Courier New',monospace;font-weight:700;letter-spacing:.08em;background:rgba(245,166,35,.1);padding:3px 10px;border-radius:5px;">
                {$senha}
              </span>
            </td>
          </tr>
        </table>
      </div>

      <!-- Botão de acesso -->
      <div style="text-align:center;margin-bottom:28px;">
        <a href="{$url}"
           style="display:inline-block;background:#f5a623;color:#000;text-decoration:none;
                  padding:14px 36px;border-radius:8px;font-size:15px;font-weight:800;
                  letter-spacing:.02em;font-family:'Helvetica Neue',Arial,sans-serif;">
          Acessar o Sistema
        </a>
        <div style="margin-top:10px;font-size:12px;color:#7a8090;">
          ou acesse: <a href="{$url}" style="color:#f5a623;text-decoration:none;">{$url}</a>
        </div>
      </div>

      <!-- Alerta de segurança -->
      <div style="background:rgba(229,57,53,.08);border:1px solid rgba(229,57,53,.35);border-radius:8px;padding:16px 18px;">
        <div style="color:#e53935;font-size:13px;font-weight:700;margin-bottom:6px;">
          ⚠&nbsp;&nbsp;Altere sua senha imediatamente após o primeiro acesso
        </div>
        <div style="color:#7a8090;font-size:13px;line-height:1.6;">
          Por segurança, nunca compartilhe esta senha. Após entrar no sistema,
          vá em <strong style="color:#e8eaf0;">Configurações → Usuários → seu perfil</strong>
          para cadastrar uma senha pessoal.
        </div>
      </div>
    </div>

    <!-- Footer -->
    <div style="padding:16px 32px 20px;border-top:1px solid #2a2d33;text-align:center;">
      <p style="color:#7a8090;font-size:12px;margin:0;line-height:1.6;">
        MecânicaPro &mdash; Sistema SaaS de Gestão para Oficinas Mecânicas<br>
        <span style="font-size:11px;">Este e-mail foi gerado automaticamente. Não responda.</span>
      </p>
    </div>
  </div>
</body>
</html>
HTML;
    }
}
