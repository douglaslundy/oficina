<?php
declare(strict_types=1);

namespace App\Services;

use App\Jobs\EnviarAlertaEmailJob;
use App\Jobs\EnviarAlertaWhatsAppJob;
use App\Models\AlertaConfig;
use App\Models\Oficina;
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

        $alerta = AlertaConfig::withoutGlobalScopes()
            ->where('oficina_id', $oficinaId)
            ->where('tipo', $tipo)
            ->where('ativo', true)
            ->first();

        if (!$alerta) return;

        // Canais do alerta interseccionados com o que o plano libera.
        $permitidos = $this->canaisDoPlano($oficinaId);
        $canais     = array_intersect((array)($alerta->canais ?? ['WHATSAPP']), $permitidos);
        if (empty($canais)) return;

        $mensagem = $this->renderTemplate($alerta->template_mensagem ?? '', $vars);

        // ── Canal WhatsApp ───────────────────────────────────────────────
        if (in_array('WHATSAPP', $canais, true) && $this->whatsApp->estaAtivo()) {
            $telefones = array_merge((array)($alerta->destinatarios ?? []), $extras);
            if ($alerta->enviar_cliente && isset($vars['_telefone_cliente'])) {
                $telefones[] = $vars['_telefone_cliente'];
            }
            if ($alerta->enviar_mecanico && isset($vars['_telefone_mecanico'])) {
                $telefones[] = $vars['_telefone_mecanico'];
            }
            $telefones = array_unique(array_filter($telefones));

            foreach ($telefones as $telefone) {
                EnviarAlertaWhatsAppJob::dispatch(
                    oficina_id: $oficinaId,
                    telefone:   (string) $telefone,
                    mensagem:   $mensagem,
                    tipo:       $tipo,
                )->onQueue('whatsapp');
            }
        }

        // ── Canal E-mail ─────────────────────────────────────────────────
        if (in_array('EMAIL', $canais, true)) {
            $emails = (array)($alerta->emails ?? []);
            if ($alerta->enviar_cliente && !empty($vars['_email_cliente'])) {
                $emails[] = $vars['_email_cliente'];
            }
            if ($alerta->enviar_mecanico && !empty($vars['_email_mecanico'])) {
                $emails[] = $vars['_email_mecanico'];
            }
            $emails = array_values(array_unique(array_filter($emails)));

            if (!empty($emails)) {
                EnviarAlertaEmailJob::dispatch(
                    oficina_id:    $oficinaId,
                    destinatarios: $emails,
                    assunto:       'MecânicaPro · ' . ($alerta->nome ?: 'Alerta'),
                    corpo:         $mensagem,
                    tipo:          $tipo,
                )->onQueue('whatsapp');
            }
        }
    }

    /** Canais liberados pelo plano da oficina. */
    private function canaisDoPlano(string $oficinaId): array
    {
        $plano = Oficina::with('plano')->find($oficinaId)?->plano;

        $canais = [];
        if ($plano?->alerta_whatsapp) $canais[] = 'WHATSAPP';
        if ($plano?->alerta_email)    $canais[] = 'EMAIL';

        return $canais;
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
