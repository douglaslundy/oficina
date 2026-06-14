<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\Configuracao;
use App\Models\Produto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class EnviarAlertaEstoque implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly Produto $produto) {}

    public function handle(): void
    {
        $config = Configuracao::first();
        $email  = $config?->email_alertas ?? config('mail.from.address');

        if (!($config?->alertas_email ?? false) || !$email) return;

        $status = match(true) {
            $this->produto->qty_atual <= 0                               => 'SEM ESTOQUE',
            $this->produto->qty_atual < $this->produto->qty_minima * 0.4 => 'CRÍTICO',
            default                                                      => 'BAIXO',
        };

        Mail::raw(
            "Alerta de estoque — {$this->produto->nome}\n\nStatus: {$status}\nQuantidade atual: {$this->produto->qty_atual}\nQuantidade mínima: {$this->produto->qty_minima}\nSKU: {$this->produto->sku}",
            fn($m) => $m->to($email)->subject("⚠ Estoque {$status}: {$this->produto->nome}")
        );
    }
}
