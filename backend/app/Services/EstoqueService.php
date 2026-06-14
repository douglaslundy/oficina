<?php
declare(strict_types=1);

namespace App\Services;

use App\Jobs\EnviarAlertaEstoque;
use App\Models\MovimentacaoEstoque;
use App\Models\OrdemServico;
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

    public function baixarEstoqueOs(OrdemServico $os): void
    {
        DB::transaction(function () use ($os) {
            foreach ($os->itens()->where('tipo', 'PECA')->get() as $item) {
                $produto = Produto::lockForUpdate()->findOrFail($item->produto_id);

                if ($produto->qty_atual < $item->quantidade) {
                    throw new \Exception("Estoque insuficiente para: {$produto->nome}");
                }

                $produto->decrement('qty_atual', (int)$item->quantidade);

                MovimentacaoEstoque::create([
                    'produto_id' => $produto->id,
                    'tipo'       => 'SAIDA',
                    'quantidade' => (int)$item->quantidade,
                    'motivo'     => 'Baixa automática OS #' . $os->numero,
                    'os_id'      => $os->id,
                    'usuario_id' => auth()->id(),
                ]);

                if ($produto->qty_atual < $produto->qty_minima) {
                    EnviarAlertaEstoque::dispatch($produto->fresh());
                }
            }
        });
    }
}
