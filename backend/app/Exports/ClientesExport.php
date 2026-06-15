<?php
declare(strict_types=1);

namespace App\Exports;

use App\Models\Cliente;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ClientesExport implements FromQuery, WithHeadings, WithMapping
{
    public function query()
    {
        return Cliente::orderBy('nome');
    }

    public function headings(): array
    {
        return ['Nome', 'CPF/CNPJ', 'Telefone', 'E-mail', 'Cidade', 'UF', 'Status', 'Cadastro'];
    }

    public function map($row): array
    {
        return [
            $row->nome,
            $row->cpf_cnpj,
            $row->telefone ?? '-',
            $row->email ?? '-',
            $row->cidade ?? '-',
            $row->uf ?? '-',
            $row->status,
            $row->criado_em?->format('d/m/Y') ?? '-',
        ];
    }
}
