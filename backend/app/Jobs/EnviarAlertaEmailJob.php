<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\AlertaLog;
use App\Services\EmailService;
use App\Tenancy\TenancyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnviarAlertaEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 30;

    /**
     * @param string[] $destinatarios
     */
    public function __construct(
        private readonly string $oficina_id,
        private readonly array $destinatarios,
        private readonly string $assunto,
        private readonly string $corpo,
        private readonly string $tipo = 'ALERTA',
        private readonly ?string $destinatarioTipo = null,
    ) {}

    public function handle(EmailService $email): void
    {
        TenancyContext::set($this->oficina_id);
        try {
            $resultado = $email->enviar($this->destinatarios, $this->assunto, $this->corpo);

            AlertaLog::create([
                'oficina_id'        => $this->oficina_id,
                'tipo'              => $this->tipo,
                'canal'             => 'EMAIL',
                'destinatario'      => implode(', ', $this->destinatarios),
                'destinatario_tipo' => $this->destinatarioTipo,
                'mensagem'          => $this->corpo,
                'sucesso'           => $resultado['ok'],
                'erro'              => $resultado['error'] ?? null,
            ]);
        } finally {
            TenancyContext::clear();
        }
    }
}
