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
    public function __construct(
        private readonly WhatsAppService $whatsApp,
        private readonly EntitlementService $ent,
    ) {}

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

        // Canais do alerta interseccionados com o que está disponível (plano OU grant).
        $permitidos = $this->ent->canaisDisponiveis($oficinaId);
        $canais     = array_intersect((array)($alerta->canais ?? ['WHATSAPP']), $permitidos);
        if (empty($canais)) return;

        $mensagem = $this->renderTemplate($alerta->template_mensagem ?? '', $vars);

        // ── Canal WhatsApp ───────────────────────────────────────────────
        if (in_array('WHATSAPP', $canais, true)
            && $this->whatsApp->estaAtivo()
            && $this->ent->permiteEnvio($oficinaId, 'ALERTA_WHATSAPP')) {

            $alvos = $this->montarAlvos(
                cadastrados: array_merge((array)($alerta->destinatarios ?? []), $extras),
                cliente: $alerta->enviar_cliente ? ($vars['_telefone_cliente'] ?? null) : null,
                mecanico: $alerta->enviar_mecanico ? ($vars['_telefone_mecanico'] ?? null) : null,
            );

            foreach ($alvos as $telefone => $origem) {
                EnviarAlertaWhatsAppJob::dispatch(
                    oficina_id:       $oficinaId,
                    telefone:         (string) $telefone,
                    mensagem:         $mensagem,
                    tipo:             $tipo,
                    destinatarioTipo: $origem,
                )->onQueue('whatsapp');
            }
        }

        // ── Canal E-mail ─────────────────────────────────────────────────
        if (in_array('EMAIL', $canais, true) && $this->ent->permiteEnvio($oficinaId, 'ALERTA_EMAIL')) {

            $alvos = $this->montarAlvos(
                cadastrados: (array)($alerta->emails ?? []),
                cliente: $alerta->enviar_cliente ? ($vars['_email_cliente'] ?? null) : null,
                mecanico: $alerta->enviar_mecanico ? ($vars['_email_mecanico'] ?? null) : null,
            );

            foreach ($alvos as $email => $origem) {
                EnviarAlertaEmailJob::dispatch(
                    oficina_id:       $oficinaId,
                    destinatarios:    [(string) $email],
                    assunto:          'MecânicaPro · ' . ($alerta->nome ?: 'Alerta'),
                    corpo:            $mensagem,
                    tipo:             $tipo,
                    destinatarioTipo: $origem,
                )->onQueue('whatsapp');
            }
        }
    }

    /**
     * Monta o mapa destino => origem (CLIENTE | MECANICO | CADASTRADO), removendo
     * vazios e duplicados. Cliente/mecânico têm prioridade sobre o número fixo.
     *
     * @return array<string,string>
     */
    private function montarAlvos(array $cadastrados, ?string $cliente, ?string $mecanico): array
    {
        $alvos = [];
        if ($cliente)  { $c = trim($cliente);  if ($c !== '') $alvos[$c] ??= 'CLIENTE'; }
        if ($mecanico) { $m = trim($mecanico); if ($m !== '') $alvos[$m] ??= 'MECANICO'; }
        foreach ($cadastrados as $d) {
            $d = trim((string) $d);
            if ($d !== '') $alvos[$d] ??= 'CADASTRADO';
        }
        return $alvos;
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
        // Alertas de resposta do orçamento já nascem avisando cliente e mecânico.
        $orcamento = ['ORCAMENTO_APROVADO', 'ORCAMENTO_RECUSADO'];

        foreach (AlertaConfig::TIPOS_PRE_DEFINIDOS() as $tipo => $meta) {
            $ehOrcamento = in_array($tipo, $orcamento, true);
            AlertaConfig::withoutGlobalScopes()->firstOrCreate(
                ['oficina_id' => $oficinaId, 'tipo' => $tipo, 'pre_definido' => true],
                [
                    'nome'              => $meta['nome'],
                    'ativo'             => false,
                    'template_mensagem' => $meta['template'],
                    'destinatarios'     => [],
                    'enviar_cliente'    => $ehOrcamento,
                    'enviar_mecanico'   => $ehOrcamento,
                ]
            );
        }
    }
}
