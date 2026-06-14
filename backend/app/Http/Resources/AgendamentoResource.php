<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgendamentoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'cliente_id'       => $this->cliente_id,
            'cliente'          => $this->whenLoaded('cliente', fn() => [
                'id'             => $this->cliente->id,
                'nome'           => $this->cliente->nome,
                'veiculo_modelo' => $this->cliente->veiculo_modelo,
                'veiculo_placa'  => $this->cliente->veiculo_placa,
            ]),
            'mecanico_id'      => $this->mecanico_id,
            'mecanico'         => $this->whenLoaded('mecanico', fn() => [
                'id'   => $this->mecanico->id,
                'nome' => $this->mecanico->nome,
            ]),
            'tipo_servico'     => $this->tipo_servico,
            'observacoes'      => $this->observacoes,
            'data_hora_inicio' => $this->data_hora_inicio?->toIso8601String(),
            'data_hora_fim'    => $this->data_hora_fim?->toIso8601String(),
            'status'           => $this->status,
            'os_id'            => $this->os_id,
            'criado_em'        => $this->criado_em?->format('d/m/Y'),
        ];
    }
}
