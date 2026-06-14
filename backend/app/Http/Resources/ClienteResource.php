<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClienteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'nome'           => $this->nome,
            'cpf_cnpj'       => $this->cpf_cnpj,
            'telefone'       => $this->telefone,
            'email'          => $this->email,
            'cep'            => $this->cep,
            'endereco'       => $this->endereco,
            'bairro'         => $this->bairro,
            'cidade'         => $this->cidade,
            'uf'             => $this->uf,
            'veiculo_modelo' => $this->veiculo_modelo,
            'veiculo_ano'    => $this->veiculo_ano,
            'veiculo_placa'  => $this->veiculo_placa,
            'status'         => $this->status,
            'criado_em'      => $this->criado_em?->format('d/m/Y'),
            'veiculos'       => $this->whenLoaded('veiculos', fn() => $this->veiculos->map(fn($v) => [
                'id'     => $v->id,
                'modelo' => $v->modelo,
                'ano'    => $v->ano,
                'placa'  => $v->placa,
                'chassi' => $v->chassi,
                'ativo'  => $v->ativo,
            ])),
        ];
    }
}
