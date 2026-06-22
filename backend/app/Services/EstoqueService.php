<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\MovimentacaoEstoque;
use App\Models\OrdemServico;
use App\Models\OsItem;
use App\Models\Produto;
use Illuminate\Support\Facades\DB;

class EstoqueService
{
    public function getStatus(int $qtyAtual, int $qtyMinima): string
    {
        if ($qtyAtual <= 0)                      return 'SEM_ESTOQUE';
        if ($qtyAtual < $qtyMinima * 0.4)        return 'CRITICO';
        if ($qtyAtual < $qtyMinima)              return 'BAIXO';
        return 'NORMAL';
    }

    public function entradaManual(Produto $produto, int $quantidade, string $motivo, string $usuarioId): void
    {
        DB::transaction(function () use ($produto, $quantidade, $motivo, $usuarioId) {
            $produto->increment('qty_atual', $quantidade);
            MovimentacaoEstoque::create([
                'produto_id'  => $produto->id,
                'tipo'        => 'ENTRADA',
                'quantidade'  => $quantidade,
                'motivo'      => $motivo,
                'usuario_id'  => $usuarioId,
            ]);
        });
    }

    /**
     * Dá baixa imediata no estoque de um único item de peça da OS.
     * Cria a movimentação de SAIDA e dispara alerta se cruzar o mínimo.
     */
    public function darSaidaItem(OrdemServico $os, OsItem $item): void
    {
        if ($item->tipo !== 'PECA' || empty($item->produto_id)) {
            return;
        }

        DB::transaction(function () use ($os, $item) {
            $produto = Produto::lockForUpdate()->findOrFail($item->produto_id);
            $qty = (int) $item->quantidade;

            if ($produto->qty_atual < $qty) {
                throw new \RuntimeException("Estoque insuficiente para: {$produto->nome}");
            }

            $produto->decrement('qty_atual', $qty);

            MovimentacaoEstoque::create([
                'produto_id' => $produto->id,
                'tipo'       => 'SAIDA',
                'quantidade' => $qty,
                'motivo'     => 'Saída OS #' . $os->numero,
                'os_id'      => $os->id,
                'usuario_id' => auth()->id(),
            ]);

            if ($produto->qty_atual < $produto->qty_minima) {
                $this->dispararAlertaEstoque($produto->fresh());
            }
        });
    }

    /**
     * Dispara o alerta de estoque pelo sistema unificado de alertas (respeita
     * canais, destinatários, cota e ativação configurados em alerta_configs).
     */
    private function dispararAlertaEstoque(Produto $produto): void
    {
        $tipo = ($produto->qty_atual <= 0 || $produto->qty_atual < $produto->qty_minima * 0.4)
            ? 'ESTOQUE_CRITICO'
            : 'ESTOQUE_BAIXO';

        app(AlertaDispatchService::class)->dispatch($tipo, [
            'produto'    => $produto->nome,
            'quantidade' => (string) $produto->qty_atual,
            'unidade'    => $produto->unidade ?? '',
        ]);
    }

    /**
     * Devolve ao estoque a quantidade de um único item de peça (ao remover da OS
     * ou ao cancelar). Cria a movimentação de ENTRADA correspondente.
     */
    public function devolverItem(OrdemServico $os, OsItem $item): void
    {
        if ($item->tipo !== 'PECA' || empty($item->produto_id)) {
            return;
        }

        DB::transaction(function () use ($os, $item) {
            $produto = Produto::lockForUpdate()->findOrFail($item->produto_id);
            $qty = (int) $item->quantidade;

            $produto->increment('qty_atual', $qty);

            MovimentacaoEstoque::create([
                'produto_id' => $produto->id,
                'tipo'       => 'ENTRADA',
                'quantidade' => $qty,
                'motivo'     => 'Devolução OS #' . $os->numero,
                'os_id'      => $os->id,
                'usuario_id' => auth()->id(),
            ]);
        });
    }

    /**
     * Dá baixa em todas as peças de uma OS de uma vez.
     */
    public function baixarEstoqueOs(OrdemServico $os): void
    {
        DB::transaction(function () use ($os) {
            foreach ($os->itens()->where('tipo', 'PECA')->get() as $item) {
                $this->darSaidaItem($os, $item);
            }
        });
    }

    /**
     * Devolve ao estoque todas as peças de uma OS (ex.: cancelamento).
     */
    public function devolverEstoqueOs(OrdemServico $os): void
    {
        DB::transaction(function () use ($os) {
            foreach ($os->itens()->where('tipo', 'PECA')->get() as $item) {
                $this->devolverItem($os, $item);
            }
        });
    }
}
