<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Services\CobrancaRecorrenteService;
use Illuminate\Console\Command;

/**
 * Corrige faturas de assinatura em aberto geradas antes da regra de fatura
 * única: soma os excedentes a faturar nelas e recria o link no gateway.
 */
class AgruparExcedentesNaFatura extends Command
{
    protected $signature = 'cobrancas:agrupar-excedentes {oficina : slug da oficina} {--dry-run}';
    protected $description = 'Inclui excedentes a faturar na fatura de assinatura em aberto da oficina';

    public function handle(CobrancaRecorrenteService $service): int
    {
        $oficina = Oficina::where('slug', $this->argument('oficina'))->firstOrFail();
        $fatura = Cobranca::where('oficina_id', $oficina->id)->where('tipo', 'ASSINATURA')
            ->where('status', 'PENDENTE')->orderBy('vencimento')->first();
        if (!$fatura) {
            $this->warn('Nenhuma fatura de assinatura PENDENTE.');
            return self::SUCCESS;
        }

        $excedentes = Cobranca::excedentesAFaturar($oficina->id)->get();
        $this->info("Fatura {$fatura->id} ({$fatura->descricao}) R$ {$fatura->valor}; excedentes: {$excedentes->count()} = R$ " . $excedentes->sum('valor'));
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $qtd = $service->agruparExcedentesNaFatura($fatura);
        $fatura->refresh();
        $this->info("Agrupados {$qtd}. Fatura agora: {$fatura->descricao} R$ {$fatura->valor} — {$fatura->link_pagamento}");
        return self::SUCCESS;
    }
}
