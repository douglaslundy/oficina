<?php
declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AlertaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $assunto,
        public string $corpo,
        public string $fromAddress,
        public string $fromName,
    ) {}

    public function build(): self
    {
        return $this->from($this->fromAddress, $this->fromName)
            ->subject($this->assunto)
            ->html($this->renderHtml());
    }

    private function renderHtml(): string
    {
        $corpo = nl2br(e($this->corpo));

        return <<<HTML
        <div style="font-family: Arial, Helvetica, sans-serif; background:#0e0f11; padding:24px; color:#e8eaf0;">
          <div style="max-width:560px; margin:0 auto; background:#1c1e21; border:1px solid #2a2d33; border-radius:12px; overflow:hidden;">
            <div style="background:#f5a623; padding:16px 24px; color:#000; font-weight:800; font-size:18px;">
              🔧 MecânicaPro
            </div>
            <div style="padding:24px; font-size:15px; line-height:1.6; color:#e8eaf0;">
              {$corpo}
            </div>
            <div style="padding:14px 24px; border-top:1px solid #2a2d33; font-size:12px; color:#7a8090;">
              Mensagem automática enviada pelo MecânicaPro.
            </div>
          </div>
        </div>
        HTML;
    }
}
