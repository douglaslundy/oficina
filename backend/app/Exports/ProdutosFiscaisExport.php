<?php
declare(strict_types=1);

namespace App\Exports;

use App\Services\Fiscal\ExportacaoProdutosFiscal;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * Planilha "produtos + dados fiscais". Todas as células são gravadas como
 * TEXTO (StringValueBinder): sem isso o Excel trata "0000000722629" como
 * número e come os zeros à esquerda do código de barras, e "01.111.00" (CEST)
 * viraria data/número.
 *
 * WithStrictNullComparison: sem ele o PhpSpreadsheet compara a célula com
 * null usando `!=` frouxo e `0 != null` é falso — a origem 0 (mercadoria
 * nacional, valor fiscal válido) saía como célula VAZIA.
 */
class ProdutosFiscaisExport extends StringValueBinder implements FromArray, WithHeadings, WithCustomValueBinder, WithColumnWidths, WithStrictNullComparison
{
    /** @param list<array<string, mixed>> $linhas linhas de ExportacaoProdutosFiscal::linhas() */
    public function __construct(private readonly array $linhas)
    {
    }

    /** @return list<list<mixed>> */
    public function array(): array
    {
        return array_map(
            fn (array $linha) => array_map(fn (string $chave) => $linha[$chave] ?? null, array_keys(ExportacaoProdutosFiscal::COLUNAS)),
            $this->linhas,
        );
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_values(ExportacaoProdutosFiscal::COLUNAS);
    }

    /** @return array<string, int> */
    public function columnWidths(): array
    {
        return ['A' => 48, 'B' => 14, 'C' => 20, 'D' => 16, 'E' => 12, 'F' => 12, 'G' => 9, 'H' => 16, 'I' => 14, 'J' => 18, 'K' => 26];
    }
}
