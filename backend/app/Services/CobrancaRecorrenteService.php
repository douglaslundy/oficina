<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\SaasConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CobrancaRecorrenteService
{
    private const MESES_PT = [
        1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr', 5 => 'Mai', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez',
    ];

    public function __construct(
        private readonly AsaasService $asaas,
        private readonly MercadoPagoService $mercadoPago,
    ) {}

    public function gerarPendentes(): int
    {
        $cfg = SaasConfig::get();
        $geradas = 0;

        $oficinas = Oficina::with('plano')
            ->whereIn('status', ['ATIVA', 'INADIMPLENTE'])
            ->whereNotNull('proximo_vencimento')
            ->get();

        foreach ($oficinas as $oficina) {
            if (!$oficina->plano || (float) $oficina->plano->preco_mensal <= 0) {
                continue;
            }

            $dias = $oficina->dias_antecedencia_cobranca ?? $cfg->cobranca_dias_antecedencia_padrao;
            $geraApartirDe = $oficina->proximo_vencimento->copy()->subDays($dias);

            if (now()->toDateString() < $geraApartirDe->toDateString()) {
                continue;
            }

            $jaExiste = Cobranca::where('oficina_id', $oficina->id)
                ->where('tipo', 'ASSINATURA')
                ->where('vencimento', $oficina->proximo_vencimento->toDateString())
                ->where('status', '!=', 'CANCELADA')
                ->exists();

            if ($jaExiste) {
                continue;
            }

            if ($this->criarCobranca($oficina, $cfg)) {
                $geradas++;
            }
        }

        return $geradas;
    }

    public function marcarVencidas(): int
    {
        $vencidas = Cobranca::where('tipo', 'ASSINATURA')
            ->where('status', 'PENDENTE')
            ->whereDate('vencimento', '<', now()->toDateString())
            ->get();

        foreach ($vencidas as $cobranca) {
            $cobranca->update(['status' => 'VENCIDA']);

            $oficina = Oficina::find($cobranca->oficina_id);
            if ($oficina && $oficina->status === 'ATIVA') {
                $oficina->update(['status' => 'INADIMPLENTE']);
            }
        }

        return $vencidas->count();
    }

    public function suspenderVencidas(): int
    {
        $cfg = SaasConfig::get();
        $suspensas = 0;

        $oficinas = Oficina::whereIn('status', ['ATIVA', 'INADIMPLENTE'])->get();

        foreach ($oficinas as $oficina) {
            $cobranca = Cobranca::where('oficina_id', $oficina->id)
                ->where('tipo', 'ASSINATURA')
                ->where('status', 'VENCIDA')
                ->orderByDesc('vencimento')
                ->first();

            if (!$cobranca) {
                continue;
            }

            $diasVencida   = (int) $cobranca->vencimento->diffInDays(now());
            $diasSuspensao = $oficina->dias_suspensao_vencido ?? $cfg->cobranca_dias_suspensao_padrao;

            if ($diasVencida < $diasSuspensao) {
                continue;
            }

            if ($oficina->voto_confianca_ate && $oficina->voto_confianca_ate->isFuture()) {
                continue;
            }

            $oficina->update(['status' => 'SUSPENSA']);
            $suspensas++;
        }

        return $suspensas;
    }

    private function criarCobranca(Oficina $oficina, SaasConfig $cfg): bool
    {
        $gateway    = $oficina->gateway ?: ($cfg->gateway_preferido ?? 'ASAAS');
        $customerId = $gateway === 'MERCADOPAGO' ? $oficina->mp_customer_id : $oficina->asaas_customer_id;

        if (!$customerId) {
            Log::warning("CobrancaRecorrente: oficina {$oficina->id} sem customer no {$gateway}, pulando.");
            return false;
        }

        $valor = $oficina->ciclo_cobranca === 'ANUAL'
            ? round((float) $oficina->plano->preco_mensal * 12 * (1 - (float) $cfg->desconto_anual_pct / 100), 2)
            : (float) $oficina->plano->preco_mensal;

        $cobrancaId = (string) Str::uuid();
        $vencimento = $oficina->proximo_vencimento->toDateString();

        try {
            $payment = $gateway === 'MERCADOPAGO'
                ? $this->mercadoPago->criarCobrancaAvulsa($customerId, $valor, $vencimento, $cobrancaId)
                : $this->asaas->criarCobrancaAvulsa($customerId, $valor, $vencimento, $cobrancaId);
        } catch (\Throwable $e) {
            Log::warning("CobrancaRecorrente: falha ao gerar cobrança para oficina {$oficina->id} ({$gateway}): {$e->getMessage()}");
            return false;
        }

        $linkPagamento = $gateway === 'MERCADOPAGO'
            ? ($payment['init_point'] ?? null)
            : ($payment['invoiceUrl'] ?? null);

        $descricao = $oficina->ciclo_cobranca === 'ANUAL'
            ? sprintf('Assinatura anual %d–%d', $oficina->proximo_vencimento->year, $oficina->proximo_vencimento->year + 1)
            : sprintf('Mensalidade %s/%d', self::MESES_PT[$oficina->proximo_vencimento->month], $oficina->proximo_vencimento->year);

        Cobranca::create([
            'id'               => $cobrancaId,
            'oficina_id'       => $oficina->id,
            'tipo'             => 'ASSINATURA',
            'descricao'        => $descricao,
            'mes_referencia'   => $oficina->proximo_vencimento->copy()->startOfMonth(),
            'valor'            => $valor,
            'status'           => 'PENDENTE',
            'gateway'          => $gateway,
            'asaas_payment_id' => $gateway === 'ASAAS' ? ($payment['id'] ?? null) : null,
            'mp_payment_id'    => $gateway === 'MERCADOPAGO' ? ($payment['id'] ?? null) : null,
            'vencimento'       => $vencimento,
            'link_pagamento'   => $linkPagamento,
        ]);

        return true;
    }
}
