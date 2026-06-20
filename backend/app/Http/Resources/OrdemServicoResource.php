<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdemServicoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'numero'           => $this->numero,
            'tipo'             => $this->tipo ?? 'OS',
            'cliente_id'       => $this->cliente_id,
            'cliente'          => $this->whenLoaded('cliente', fn() => [
                'id'            => $this->cliente->id,
                'nome'          => $this->cliente->nome,
                'veiculo_placa' => $this->cliente->veiculo_placa,
            ]),
            'mecanico_id'      => $this->mecanico_id,
            'mecanico'         => $this->whenLoaded('mecanico', fn() => [
                'id'   => $this->mecanico->id,
                'nome' => $this->mecanico->nome,
            ]),
            'veiculo_descricao' => $this->veiculo_descricao,
            'veiculo_placa'    => $this->veiculo_placa,
            'problema_relatado' => $this->problema_relatado,
            'status'           => $this->status,
            'forma_pagamento'             => $this->forma_pagamento,
            'prazo_entrega'               => $this->prazo_entrega?->format('d/m/Y'),
            'venda_a_prazo'               => (bool) $this->venda_a_prazo,
            'prazo_pagamento_dias'        => $this->prazo_pagamento_dias,
            'data_vencimento_pagamento'   => $this->data_vencimento_pagamento?->format('d/m/Y'),
            'valor_total'                 => $this->valor_total,
            'valor_pago'                  => $this->valor_pago,
            'saldo_devedor'               => $this->saldo_devedor,
            'pagamentos'       => $this->whenLoaded('pagamentos', fn() => $this->pagamentos->map(fn($p) => [
                'id'              => $p->id,
                'forma_pagamento' => $p->forma_pagamento,
                'valor'           => $p->valor,
                'criado_em'       => $p->criado_em?->format('d/m/Y H:i'),
            ])),
            'itens'            => $this->whenLoaded('itens', fn() => $this->itens->map(fn($i) => [
                'id'             => $i->id,
                'tipo'           => $i->tipo,
                'produto_id'     => $i->produto_id,
                'descricao'      => $i->descricao,
                'quantidade'     => $i->quantidade,
                'valor_unitario' => $i->valor_unitario,
                'valor_total'    => $i->valor_total,
            ])),
            'criado_em'        => $this->criado_em?->format('d/m/Y H:i'),
        ];
    }
}
