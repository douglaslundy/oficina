<?php
declare(strict_types=1);

namespace App\Services;

use App\Jobs\EnviarAlertaWhatsAppJob;
use App\Models\AlertaConfig;
use App\Tenancy\TenancyContext;

class AlertaDispatchService
{
    public function __construct(private readonly WhatsAppService $whatsApp) {}

    /**
     * Dispara um alerta WhatsApp se estiver ativo para o tenant atual.
     *
     * @param string $tipo  Uma das constantes de AlertaConfig::TIPOS_PRE_DEFINIDOS()
     * @param array  $vars  Variáveis para substituição no template: ['cliente' => 'João', ...]
     * @param array  $extras Telefones extras além dos configurados no alerta
     */
    public function dispatch(string $tipo, array $vars = [], array $extras = []): void
    {
        $oficinaId = TenancyContext::get();
        if (!$oficinaId) return;
        if (!$this->whatsApp->estaAtivo()) return;

        $alerta = AlertaConfig::withoutGlobalScopes()
            ->where('oficina_id', $oficinaId)
            ->where('tipo', $tipo)
            ->where('ativo', true)
            ->first();

        if (!$alerta) return;

        $mensagem = $this->renderTemplate($alerta->template_mensagem ?? '', $vars);

        $telefones = array_merge(
            (array)($alerta->destinatarios ?? []),
            $extras
        );

        // Adiciona telefone do cliente se configurado e fornecido
        if ($alerta->enviar_cliente && isset($vars['_telefone_cliente'])) {
            $telefones[] = $vars['_telefone_cliente'];
        }

        // Adiciona telefone do mecânico se configurado e fornecido
        if ($alerta->enviar_mecanico && isset($vars['_telefone_mecanico'])) {
            $telefones[] = $vars['_telefone_mecanico'];
        }

        $telefones = array_unique(array_filter($telefones));

        foreach ($telefones as $telefone) {
            EnviarAlertaWhatsAppJob::dispatch($oficina_id: $oficinaId, telefone: $telefone, mensagem: $mensagem)
                ->onQueue('whatsapp');
        }
    }

    private function renderTemplate(string $template, array $vars): string
    {
        $search  = array_map(fn($k) => "{{$k}}", array_keys($vars));
        $replace = array_values($vars);
        return str_replace($search, $replace, $template);
    }

    /** Garante que todos os alertas pré-definidos existam para o tenant (cria se não existir). */
    public function garantirAlertasPreDefinidos(string $oficinaId): void
    {
        foreach (AlertaConfig::TIPOS_PRE_DEFINIDOS() as $tipo => $meta) {
            AlertaConfig::withoutGlobalScopes()->firstOrCreate(
                ['oficina_id' => $oficinaId, 'tipo' => $tipo, 'pre_definido' => true],
                [
                    'nome'              => $meta['nome'],
                    'ativo'             => false,
                    'template_mensagem' => $meta['template'],
                    'destinatarios'     => [],
                    'enviar_cliente'    => false,
                    'enviar_mecanico'   => false,
                ]
            );
        }
    }
}
