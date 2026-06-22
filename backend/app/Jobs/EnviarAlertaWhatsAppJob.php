<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Services\WhatsAppService;
use App\Tenancy\TenancyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnviarAlertaWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 30;

    public function __construct(
        private readonly string $oficina_id,
        private readonly string $telefone,
        private readonly string $mensagem,
        private readonly string $tipo = 'ALERTA',
        private readonly ?string $destinatarioTipo = null,
    ) {}

    public function handle(WhatsAppService $whatsApp): void
    {
        TenancyContext::set($this->oficina_id);
        try {
            $whatsApp->enviarMensagem($this->telefone, $this->mensagem, $this->tipo, $this->destinatarioTipo);
        } finally {
            TenancyContext::clear();
        }
    }
}
