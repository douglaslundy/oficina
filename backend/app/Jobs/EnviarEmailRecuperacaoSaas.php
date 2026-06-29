<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\SuperAdmin;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class EnviarEmailRecuperacaoSaas implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly SuperAdmin $admin,
        private readonly string $token
    ) {}

    public function handle(): void
    {
        $link = config('app.frontend_url', 'http://localhost:3000')
            . '/saas-admin/reset-password?token=' . $this->token;

        Mail::raw(
            "Olá {$this->admin->nome},\n\nClique no link abaixo para redefinir sua senha de administrador:\n{$link}\n\nO link expira em 30 minutos.\n\nSe você não solicitou a redefinição, ignore este e-mail.",
            function ($message) {
                $message->to($this->admin->email)
                        ->subject('Redefinição de senha — MecânicaPro Admin');
            }
        );
    }
}
