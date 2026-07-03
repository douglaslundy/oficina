<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotaEntradaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'numero_nf'       => $this->numero_nf,
            'serie'           => $this->serie,
            'chave_acesso'    => $this->chave_acesso,
            'fornecedor_nome' => $this->fornecedor_nome,
            'fornecedor_cnpj' => $this->fornecedor_cnpj,
            'valor_total'     => $this->valor_total,
            'data_emissao'    => $this->data_emissao?->format('d/m/Y'),
            'criado_em'       => $this->criado_em?->format('d/m/Y'),
            'itens'           => $this->whenLoaded('itens', fn() => $this->itens->map(fn($i) => [
                'id'                => $i->id,
                'produto_id'        => $i->produto_id,
                'descricao_xml'     => $i->descricao_xml,
                'codigo_barras_xml' => $i->codigo_barras_xml,
                'quantidade'        => $i->quantidade,
                'valor_unitario'    => $i->valor_unitario,
                'produto_criado'    => $i->produto_criado,
            ])),
        ];
    }
}
