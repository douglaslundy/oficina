<?php
declare(strict_types=1);

namespace App\Exports;

use App\Models\OrdemServico;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class OsExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private readonly array $filters = []) {}

    public function query()
    {
        $q = OrdemServico::with(['cliente', 'mecanico']);
        if (!empty($this->filters['status']))      $q->where('status', $this->filters['status']);
        if (!empty($this->filters['data_inicio'])) $q->whereDate('criado_em', '>=', $this->filters['data_inicio']);
        if (!empty($this->filters['data_fim']))    $q->whereDate('criado_em', '<=', $this->filters['data_fim']);
        return $q->orderBy('numero');
    }

    public function headings(): array
    {
        return ['#OS', 'Cliente', 'Mecânico', 'Status', 'Valor Total', 'Valor Pago', 'Saldo', 'Data'];
    }

    public function map($row): array
    {
        return [
            $row->numero,
            $row->cliente?->nome ?? '-',
            $row->mecanico?->nome ?? '-',
            $row->status,
            number_format((float)$row->valor_total, 2, ',', '.'),
            number_format((float)$row->valor_pago, 2, ',', '.'),
            number_format($row->getSaldoDevedorAttribute(), 2, ',', '.'),
            $row->criado_em?->format('d/m/Y') ?? '-',
        ];
    }
}
