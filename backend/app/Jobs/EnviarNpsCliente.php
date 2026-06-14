<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\OrdemServico;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class EnviarNpsCliente implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(private readonly OrdemServico $os) {}

    public function handle(): void
    {
        $cliente = $this->os->cliente;

        // Não enviar se cliente não tem e-mail ou OS não está concluída
        if (!$cliente?->email || $this->os->status !== 'CONCLUIDA') {
            return;
        }

        $nomeOficina = config('app.name', 'MecânicaPro');
        $osNumero    = $this->os->numero;
        $clienteNome = $cliente->nome;
        $valorTotal  = 'R$ ' . number_format((float)$this->os->valor_total, 2, ',', '.');

        $corpo = <<<TEXT
Olá, {$clienteNome}!

Obrigado por escolher a {$nomeOficina}.

Sua Ordem de Serviço #{$osNumero} foi concluída no valor de {$valorTotal}.

Gostaríamos de saber como foi sua experiência. Em uma escala de 0 a 10, o quanto você nos recomendaria a um amigo ou familiar?

Por favor, responda a este e-mail com sua nota de 0 a 10 e qualquer comentário que queira compartilhar.

Sua opinião é muito importante para nós!

Atenciosamente,
Equipe {$nomeOficina}
TEXT;

        Mail::raw($corpo, function ($message) use ($cliente, $nomeOficina, $osNumero) {
            $message->to($cliente->email, $cliente->nome)
                    ->subject("Como foi sua experiência na {$nomeOficina}? (OS #{$osNumero})");
        });
    }
}
