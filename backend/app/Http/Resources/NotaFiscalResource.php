<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotaFiscalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'numero'            => $this->numero,
            'serie'             => $this->serie,
            'modelo'            => $this->modelo,
            'cliente_id'        => $this->cliente_id,
            'cliente'           => $this->whenLoaded('cliente', fn() => [
                'id'       => $this->cliente->id,
                'nome'     => $this->cliente->nome,
                'cpf_cnpj' => $this->cliente->cpf_cnpj,
                'cidade'   => $this->cliente->cidade,
                'uf'       => $this->cliente->uf,
            ]),
            'os_id'             => $this->os_id,
            'natureza_operacao' => $this->natureza_operacao,
            'forma_pagamento'   => $this->forma_pagamento,
            'subtotal'          => $this->subtotal,
            'desconto'          => $this->desconto,
            'aliquota_iss'      => $this->aliquota_iss,
            'valor_iss'         => $this->valor_iss,
            'valor_total'       => $this->valor_total,
            'status'            => $this->status,
            'chave_acesso'      => $this->chave_acesso,
            'pdf_url'           => $this->pdf_url,
            'observacoes'       => $this->observacoes,
            'emitido_em'        => $this->emitido_em?->format('d/m/Y H:i'),
            'criado_em'         => $this->criado_em?->format('d/m/Y'),
        ];
    }
}
