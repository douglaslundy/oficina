<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\SaasConfig;
use Illuminate\Support\Facades\DB;
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

    /**
     * Gera manualmente a cobrança de ASSINATURA do ciclo atual (mensalidade
     * ou anuidade), ignorando a janela de antecedência usada pelo job
     * automático. A checagem de duplicidade é a MESMA usada em
     * gerarPendentes() (mesma oficina + mesmo vencimento + status ativo) —
     * isso garante que, uma vez gerada manualmente, o job automático não
     * gera outra para o mesmo ciclo; no próximo ciclo o vencimento muda e a
     * geração volta a ocorrer normalmente. Não afeta cobranças AVULSA, que
     * usam outro fluxo sem essa checagem.
     */
    public function gerarManual(Oficina $oficina): array
    {
        $cfg = SaasConfig::get();

        if (!$oficina->plano || (float) $oficina->plano->preco_mensal <= 0) {
            return ['ok' => false, 'message' => 'Oficina sem plano pago — não há cobrança de assinatura a gerar.'];
        }

        if (!$oficina->proximo_vencimento) {
            return ['ok' => false, 'message' => 'Oficina sem próximo vencimento definido.'];
        }

        $jaExiste = Cobranca::where('oficina_id', $oficina->id)
            ->where('tipo', 'ASSINATURA')
            ->where('vencimento', $oficina->proximo_vencimento->toDateString())
            ->where('status', '!=', 'CANCELADA')
            ->exists();

        if ($jaExiste) {
            return [
                'ok'      => false,
                'message' => 'Já existe uma cobrança de assinatura gerada para o ciclo atual (vencimento ' . $oficina->proximo_vencimento->format('d/m/Y') . ').',
            ];
        }

        if (!$this->criarCobranca($oficina, $cfg)) {
            return ['ok' => false, 'message' => 'Falha ao gerar cobrança no gateway de pagamento. Verifique se a oficina possui customer configurado.'];
        }

        return ['ok' => true, 'message' => 'Cobrança do ciclo gerada manualmente com sucesso.'];
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

        // Excedentes gerados até agora entram nesta fatura (fatura única, paga
        // na totalidade); os gerados depois ficam para a próxima.
        $excedentes = Cobranca::excedentesAFaturar($oficina->id)->get(['id', 'valor']);
        $valorExcedentes = round((float) $excedentes->sum('valor'), 2);
        $valor = round($valor + $valorExcedentes, 2);

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
        $descricao .= self::sufixoExcedentes($excedentes->count(), $valorExcedentes);

        DB::transaction(function () use ($cobrancaId, $oficina, $descricao, $valor, $gateway, $payment, $vencimento, $linkPagamento, $excedentes) {
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
            Cobranca::whereIn('id', $excedentes->pluck('id'))->update(['fatura_id' => $cobrancaId, 'status' => 'FATURADA']);
        });

        return true;
    }

    public static function sufixoExcedentes(int $qtd, float $valor): string
    {
        if ($qtd === 0) {
            return '';
        }
        return sprintf(' + %d excedente%s (R$ %s)', $qtd, $qtd > 1 ? 's' : '', number_format($valor, 2, ',', '.'));
    }

    /**
     * Inclui numa fatura de assinatura já gerada (e ainda em aberto) os
     * excedentes a faturar: soma no valor e recria a cobrança no gateway com o
     * valor novo, invalidando o link antigo. Usado para corrigir faturas
     * geradas antes da regra de fatura única.
     */
    public function agruparExcedentesNaFatura(Cobranca $fatura): int
    {
        if ($fatura->tipo !== 'ASSINATURA' || $fatura->status !== 'PENDENTE') {
            return 0;
        }
        $oficina = Oficina::findOrFail($fatura->oficina_id);
        $excedentes = Cobranca::excedentesAFaturar($oficina->id)->get(['id', 'valor']);
        if ($excedentes->isEmpty()) {
            return 0;
        }

        $jaIncluidos = $fatura->itens()->get(['valor']);
        $valorItens = round((float) $jaIncluidos->sum('valor') + (float) $excedentes->sum('valor'), 2);
        $valor = round((float) $fatura->valor + (float) $excedentes->sum('valor'), 2);
        $vencimento = $fatura->vencimento->toDateString();

        if ($fatura->gateway === 'MERCADOPAGO') {
            $payment = $this->mercadoPago->criarCobrancaAvulsa((string) $oficina->mp_customer_id, $valor, $vencimento, $fatura->id);
            if ($fatura->mp_payment_id) {
                $this->mercadoPago->expirarPreferencia($fatura->mp_payment_id);
            }
            $gw = ['mp_payment_id' => $payment['id'] ?? null, 'link_pagamento' => $payment['init_point'] ?? null];
        } else {
            $payment = $this->asaas->criarCobrancaAvulsa((string) $oficina->asaas_customer_id, $valor, $vencimento, $fatura->id);
            if ($fatura->asaas_payment_id) {
                $this->asaas->cancelarPagamento($fatura->asaas_payment_id);
            }
            $gw = ['asaas_payment_id' => $payment['id'] ?? null, 'link_pagamento' => $payment['invoiceUrl'] ?? null];
        }

        $descricao = preg_replace('/ \+ \d+ excedentes? \(R\$ [\d.,]+\)$/u', '', (string) $fatura->descricao)
            . self::sufixoExcedentes($jaIncluidos->count() + $excedentes->count(), $valorItens);

        DB::transaction(function () use ($fatura, $gw, $valor, $descricao, $excedentes) {
            $fatura->update($gw + ['valor' => $valor, 'descricao' => $descricao]);
            Cobranca::whereIn('id', $excedentes->pluck('id'))->update(['fatura_id' => $fatura->id, 'status' => 'FATURADA']);
        });

        return $excedentes->count();
    }
}
