<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Exports\ProdutosFiscaisExport;
use App\Models\Produto;
use App\Services\Fiscal\ExportacaoProdutosFiscal;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Gera um XLSX de verdade e lê de volta — o erro que este teste protege só
 * aparece no arquivo escrito (o array de linhas estava certo): o fromArray do
 * PhpSpreadsheet compara com `!=` frouxo, e `0 != null` é falso, então a
 * origem 0 (mercadoria nacional, valor fiscal válido) saía como célula vazia.
 */
class ProdutosFiscaisExportXlsxTest extends TestCase
{
    private function planilha(array $produtos): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $linhas = (new ExportacaoProdutosFiscal())->linhas($produtos);
        $binario = Excel::raw(new ProdutosFiscaisExport($linhas), ExcelWriter::XLSX);

        $arquivo = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($arquivo, $binario);
        try {
            return IOFactory::load($arquivo)->getActiveSheet();
        } finally {
            unlink($arquivo);
        }
    }

    public function test_origem_zero_sai_como_zero_e_nao_como_celula_vazia(): void
    {
        $folha = $this->planilha([
            new Produto(['nome' => 'Capacete', 'sku' => 'C1', 'categoria' => 'Outros', 'ncm' => '65061000', 'origem' => 0]),
        ]);

        $this->assertSame('0', (string) $folha->getCell('G2')->getValue(), 'Origem 0 não pode virar célula vazia');
    }

    public function test_origem_ausente_sai_vazia(): void
    {
        $folha = $this->planilha([
            new Produto(['nome' => 'Capacete', 'sku' => 'C1', 'categoria' => 'Outros', 'ncm' => '65061000']),
        ]);

        $this->assertNull($folha->getCell('G2')->getValue());
    }

    public function test_codigo_de_barras_mantem_zeros_a_esquerda_e_cest_continua_texto(): void
    {
        $folha = $this->planilha([
            new Produto(['nome' => 'Corrente', 'sku' => 'A1', 'categoria' => 'Outros', 'codigo_barras' => '0000000722629', 'cest' => '01.111.00']),
        ]);

        $this->assertSame('0000000722629', $folha->getCell('C2')->getValue());
        $this->assertSame('01.111.00', $folha->getCell('F2')->getValue());
    }

    public function test_cabecalho_e_uma_linha_por_produto(): void
    {
        $folha = $this->planilha([
            new Produto(['nome' => 'A', 'sku' => '1', 'categoria' => 'Outros']),
            new Produto(['nome' => 'B', 'sku' => '2', 'categoria' => 'Outros']),
        ]);

        $this->assertSame('Produto', $folha->getCell('A1')->getValue());
        $this->assertSame('Situação fiscal', $folha->getCell('K1')->getValue());
        $this->assertSame(3, $folha->getHighestDataRow());
    }
}
