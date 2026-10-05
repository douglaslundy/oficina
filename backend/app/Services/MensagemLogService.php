<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AlertaLog;

/**
 * Registro, no histórico de mensagens da oficina (`alerta_logs`), de e-mails
 * que saem por fora do job de alerta — orçamento, pesquisa de satisfação e
 * recuperação de senha. Nunca lança: falhar em registrar não pode derrubar
 * o envio nem a requisição que o disparou.
 */
class MensagemLogService
{
    public function registrarEmail(
        string $oficinaId,
        string $tipo,
        string $destinatario,
        string $assunto,
        string $mensagem,
        bool $sucesso,
        ?string $erro = null,
        ?string $destinatarioTipo = null,
    ): void {
        try {
            AlertaLog::create([
                'oficina_id'        => $oficinaId,
                'tipo'              => $tipo,
                'canal'             => 'EMAIL',
                'assunto'           => mb_substr($assunto, 0, 200),
                'destinatario'      => mb_substr($destinatario, 0, 255),
                'destinatario_tipo' => $destinatarioTipo,
                'mensagem'          => $mensagem,
                'sucesso'           => $sucesso,
                'erro'              => $erro,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Falha ao registrar mensagem no histórico: ' . $e->getMessage());
        }
    }
}
