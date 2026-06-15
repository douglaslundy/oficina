<?php
declare(strict_types=1);

namespace App\Exports;

use App\Models\Produto;
use App\Services\EstoqueService;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class EstoqueExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private readonly EstoqueService $estoqueService) {}

    public function query()
    {
        return Produto::where('ativo', true)->orderBy('nome');
    }

    public function headings(): array
    {
        return ['SKU', 'Nome', 'Categoria', 'Unidade', 'Qty Atual', 'Qty Mínima', 'Status', 'Preço Custo', 'Preço Venda'];
    }

    public function map($row): array
    {
        return [
            $row->sku,
            $row->nome,
            $row->categoria,
            $row->unidade,
            $row->qty_atual,
            $row->qty_minima,
            $this->estoqueService->getStatusEstoque($row->qty_atual, $row->qty_minima),
            number_format((float)($row->preco_custo ?? 0), 2, ',', '.'),
            number_format((float)($row->preco_venda ?? 0), 2, ',', '.'),
        ];
    }
}
