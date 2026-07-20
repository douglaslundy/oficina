<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\SaasConfig;

class AssinaturaAlertaService
{
    public function status(Oficina $oficina): array
    {
        $cobranca = Cobranca::where('oficina_id', $oficina->id)
            ->where('tipo', 'ASSINATURA')
            ->whereIn('status', ['PENDENTE', 'VENCIDA'])
            ->orderByDesc('vencimento')
            ->first();

        if (!$cobranca) {
            return ['show' => false];
        }

        $cfg = SaasConfig::get();

        if ($cobranca->status === 'PENDENTE') {
            $diasParaVencer = (int) now()->diffInDays($cobranca->vencimento, false);
            if ($diasParaVencer > ($cfg->alerta_cobranca_dias_exibicao ?? 30)) {
                return ['show' => false];
            }
        }

        ['fase' => $fase, 'mensagem' => $mensagem] = $this->resolverFaseEMensagem($oficina, $cobranca, $cfg);

        if (!$this->podeExibirHoje($oficina, $cfg)) {
            return ['show' => false];
        }

        $this->registrarExibicao($oficina);

        return [
            'show'               => true,
            'fase'               => $fase,
            'mensagem'           => $mensagem,
            'cobranca_id'        => $cobranca->id,
            'gateway'            => $cobranca->gateway,
            'valor'              => number_format((float) $cobranca->valor, 2, '.', ''),
            'vencimento'         => $cobranca->vencimento->toDateString(),
            'link_pagamento'     => $cobranca->link_pagamento,
            'ciclo_atual'        => $oficina->ciclo_cobranca,
            'desconto_anual_pct' => (float) $cfg->desconto_anual_pct,
        ];
    }

    public function statusBloqueio(Oficina $oficina): array
    {
        $suspensa = $oficina->status === 'SUSPENSA';

        $cobranca = Cobranca::where('oficina_id', $oficina->id)
            ->where('tipo', 'ASSINATURA')
            ->whereIn('status', ['PENDENTE', 'VENCIDA'])
            ->orderByDesc('vencimento')
            ->first();

        if (!$cobranca) {
            return ['suspensa' => $suspensa, 'voto_confianca_disponivel' => false];
        }

        $cfg = SaasConfig::get();
        ['fase' => $fase, 'mensagem' => $mensagem] = $this->resolverFaseEMensagem($oficina, $cobranca, $cfg);

        if ($suspensa && $fase === 'VENCIDA') {
            $mensagem = 'Sua oficina está suspensa por falta de pagamento. Pague sua fatura para reativar o acesso imediatamente.';
        }

        return [
            'suspensa'                  => $suspensa,
            'fase'                      => $fase,
            'mensagem'                  => $mensagem,
            'cobranca_id'               => $cobranca->id,
            'gateway'                   => $cobranca->gateway,
            'valor'                     => number_format((float) $cobranca->valor, 2, '.', ''),
            'vencimento'                => $cobranca->vencimento->toDateString(),
            'link_pagamento'            => $cobranca->link_pagamento,
            'voto_confianca_disponivel' => $cobranca->status === 'VENCIDA' && $cobranca->voto_confianca_usado_em === null,
        ];
    }

    /** @return array{fase: string, mensagem: string} */
    private function resolverFaseEMensagem(Oficina $oficina, Cobranca $cobranca, SaasConfig $cfg): array
    {
        if ($cobranca->status === 'PENDENTE') {
            $fase     = 'DISPONIVEL';
            $mensagem = 'Sua fatura de ' . $this->formatarMoeda((float) $cobranca->valor)
                . ' está disponível para pagamento. Vencimento: ' . $cobranca->vencimento->format('d/m/Y') . '.';
        } else {
            $diasVencida   = (int) $cobranca->vencimento->diffInDays(now());
            $diasSuspensao = $oficina->dias_suspensao_vencido ?? $cfg->cobranca_dias_suspensao_padrao;
            $restante      = $diasSuspensao - $diasVencida;

            $fase     = 'VENCIDA';
            $mensagem = 'Sua fatura venceu em ' . $cobranca->vencimento->format('d/m/Y')
                . '. Pague sua fatura e evite a suspensão dos seus serviços no sistema. ';
            $mensagem .= $restante > 0
                ? 'Sua oficina será suspensa em ' . $restante . ' dia' . ($restante === 1 ? '' : 's')
                    . ' e seu acesso será bloqueado até a identificação do pagamento.'
                : 'Sua oficina pode ser suspensa a qualquer momento.';
        }

        return ['fase' => $fase, 'mensagem' => $mensagem];
    }

    private function exibicoesHoje(Oficina $oficina): int
    {
        $hoje = now()->toDateString();
        return $oficina->alerta_cobranca_ultima_exibicao_em?->toDateString() === $hoje
            ? $oficina->alerta_cobranca_exibicoes_hoje
            : 0;
    }

    private function podeExibirHoje(Oficina $oficina, SaasConfig $cfg): bool
    {
        return $this->exibicoesHoje($oficina) < ($cfg->alerta_cobranca_vezes_dia ?? 1);
    }

    private function registrarExibicao(Oficina $oficina): void
    {
        $oficina->update([
            'alerta_cobranca_exibicoes_hoje'     => $this->exibicoesHoje($oficina) + 1,
            'alerta_cobranca_ultima_exibicao_em' => now()->toDateString(),
        ]);
    }

    private function formatarMoeda(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }
}
