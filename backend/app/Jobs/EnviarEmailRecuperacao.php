<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\Usuario;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class EnviarEmailRecuperacao implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly Usuario $usuario,
        private readonly string $token
    ) {}

    public function handle(): void
    {
        $link = config('app.frontend_url', 'http://localhost:3000')
            . '/reset-password?token=' . $this->token;

        Mail::raw(
            "Olá {$this->usuario->nome},\n\nClique no link abaixo para redefinir sua senha:\n{$link}\n\nO link expira em 30 minutos.",
            function ($message) {
                $message->to($this->usuario->email)
                        ->subject('Redefinição de senha — MecânicaPro');
            }
        );
    }
}
