<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'nome'           => $this->nome,
            'sku'            => $this->sku,
            'categoria'      => $this->categoria,
            'unidade'        => $this->unidade,
            'qty_atual'      => $this->qty_atual,
            'qty_minima'     => $this->qty_minima,
            'preco_custo'    => $this->preco_custo,
            'preco_venda'    => $this->preco_venda,
            'ativo'          => $this->ativo,
            'status_estoque' => $this->status_estoque,
            'criado_em'      => $this->criado_em?->format('d/m/Y'),
        ];
    }
}
