<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Models\Produto;
use App\Services\Fiscal\ExportacaoProdutosFiscal;
use PHPUnit\Framework\TestCase;

class ExportacaoProdutosFiscalTest extends TestCase
{
    private ExportacaoProdutosFiscal $servico;

    protected function setUp(): void
    {
        $this->servico = new ExportacaoProdutosFiscal();
    }

    private function produto(array $atributos): Produto
    {
        return new Produto(array_merge(['nome' => 'Produto', 'sku' => 'SKU', 'categoria' => 'Outros'], $atributos));
    }

    /** @return list<string> */
    private function nomes(array $linhas): array
    {
        return array_column($linhas, 'nome');
    }

    public function test_produtos_sem_nenhum_campo_fiscal_vao_por_ultimo(): void
    {
        $linhas = $this->servico->linhas([
            $this->produto(['nome' => 'Alfa']),                                   // nenhum campo fiscal
            $this->produto(['nome' => 'Zeta', 'ncm' => '87141000']),              // completo o bastante
            $this->produto(['nome' => 'Beta', 'cest' => '01.076.00']),            // só CEST
        ]);

        $this->assertSame(['Beta', 'Zeta', 'Alfa'], $this->nomes($linhas));
    }

    public function test_origem_zero_conta_como_campo_fiscal_preenchido(): void
    {
        // origem 0 = mercadoria nacional, valor válido — não pode ser tratado como ausente.
        $linhas = $this->servico->linhas([
            $this->produto(['nome' => 'Sem nada']),
            $this->produto(['nome' => 'Com origem zero', 'origem' => 0]),
        ]);

        $this->assertSame(['Com origem zero', 'Sem nada'], $this->nomes($linhas));
        $this->assertSame(0, $linhas[0]['origem']);
    }

    public function test_string_vazia_conta_como_campo_ausente(): void
    {
        $linhas = $this->servico->linhas([
            $this->produto(['nome' => 'Vazios', 'ncm' => '', 'cest' => '']),
            $this->produto(['nome' => 'Preenchido', 'ncm' => '87141000']),
        ]);

        $this->assertSame(['Preenchido', 'Vazios'], $this->nomes($linhas));
    }

    public function test_ordena_por_nome_sem_diferenciar_acento_nem_caixa_dentro_de_cada_grupo(): void
    {
        $linhas = $this->servico->linhas([
            $this->produto(['nome' => 'Zebra', 'ncm' => '1']),
            $this->produto(['nome' => 'Óleo', 'ncm' => '1']),
            $this->produto(['nome' => 'ar', 'ncm' => '1']),
            $this->produto(['nome' => 'Sem b']),
            $this->produto(['nome' => 'Sem a']),
        ]);

        $this->assertSame(['ar', 'Óleo', 'Zebra', 'Sem a', 'Sem b'], $this->nomes($linhas));
    }

    public function test_ordem_natural_para_numeros_no_nome(): void
    {
        $linhas = $this->servico->linhas([
            $this->produto(['nome' => 'Titan 125', 'ncm' => '1']),
            $this->produto(['nome' => 'Titan 99', 'ncm' => '1']),
            $this->produto(['nome' => 'Titan 150', 'ncm' => '1']),
        ]);

        $this->assertSame(['Titan 99', 'Titan 125', 'Titan 150'], $this->nomes($linhas));
    }

    public function test_situacao_fiscal(): void
    {
        $situacao = fn (array $attrs, bool $div = false) => $this->servico->situacao($this->produto($attrs), $div);

        $this->assertSame('Sem NCM', $situacao([]));
        $this->assertSame('Sem NCM', $situacao(['ncm' => '']));
        $this->assertSame('ICMS-ST sem CEST', $situacao(['ncm' => '87141000', 'tributacao_icms' => 'ST']));
        $this->assertSame('ICMS-ST sem CEST', $situacao(['ncm' => '87141000', 'tributacao_icms' => 'ST', 'cest' => '']));
        $this->assertSame('Padrão da categoria', $situacao(['ncm' => '87141000', 'fiscal_fonte' => 'PADRAO']));
        $this->assertSame('Divergência com fornecedor', $situacao(['ncm' => '87141000'], true));
        $this->assertSame('Completo', $situacao(['ncm' => '87141000', 'tributacao_icms' => 'ST', 'cest' => '01.076.00']));
        $this->assertSame('Completo', $situacao(['ncm' => '87141000', 'fiscal_fonte' => 'XML']));
    }

    public function test_sem_ncm_tem_prioridade_sobre_divergencia(): void
    {
        $this->assertSame('Sem NCM', $this->servico->situacao($this->produto([]), true));
    }

    public function test_linha_tem_todas_as_colunas_na_ordem_esperada(): void
    {
        $linhas = $this->servico->linhas([$this->produto(['nome' => 'A', 'ncm' => '87141000'])]);

        $this->assertSame(array_keys(ExportacaoProdutosFiscal::COLUNAS), array_keys($linhas[0]));
    }

    public function test_json_e_valido_utf8_e_mantem_origem_zero_como_numero(): void
    {
        $linhas = $this->servico->linhas([
            $this->produto(['nome' => 'Óleo Genuíno', 'ncm' => '27101932', 'origem' => 0]),
        ]);

        $json = $this->servico->paraJson($linhas, '20/09/2026 10:00');
        $dados = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('Óleo Genuíno', $json, 'acentos não devem sair como \\uXXXX');
        $this->assertSame(1, $dados['total']);
        $this->assertSame('20/09/2026 10:00', $dados['gerado_em']);
        $this->assertSame('Óleo Genuíno', $dados['produtos'][0]['nome']);
        $this->assertSame(0, $dados['produtos'][0]['origem']);
        $this->assertNull($dados['produtos'][0]['cest']);
    }

    public function test_xml_e_valido_escapa_caracteres_e_mantem_origem_zero(): void
    {
        $linhas = $this->servico->linhas([
            $this->produto(['nome' => 'Bomba <A&B> "X"', 'ncm' => '84133000', 'origem' => 0]),
        ]);

        $xml = $this->servico->paraXml($linhas, '20/09/2026 10:00');

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml), 'XML precisa ser bem formado');
        $raiz = $doc->documentElement;
        $this->assertSame('produtos', $raiz->nodeName);
        $this->assertSame('1', $raiz->getAttribute('total'));

        $produto = $doc->getElementsByTagName('produto')->item(0);
        $valor = fn (string $tag) => $produto->getElementsByTagName($tag)->item(0)->textContent;
        $this->assertSame('Bomba <A&B> "X"', $valor('nome'));
        $this->assertSame('0', $valor('origem'));
        $this->assertSame('', $valor('cest'));
        $this->assertSame('Completo', $valor('situacao_fiscal'));
    }

    public function test_xlsx_preserva_zeros_a_esquerda_do_codigo_de_barras(): void
    {
        // O Excel trata "0000000722629" como número e come os zeros. O export
        // precisa forçar texto em todas as células (StringValueBinder).
        $export = new \App\Exports\ProdutosFiscaisExport([
            ['nome' => 'Corrente', 'sku' => 'A1', 'codigo_barras' => '0000000722629', 'categoria' => 'Filtros',
             'ncm' => '73151100', 'cest' => '01.111.00', 'origem' => 0, 'tributacao_icms' => null,
             'fiscal_fonte' => null, 'revisado_em' => null, 'situacao_fiscal' => 'Completo'],
        ]);

        $planilha = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $folha = $planilha->getActiveSheet();
        $export->bindValue($folha->getCell('A1'), '0000000722629');

        $this->assertSame('0000000722629', $folha->getCell('A1')->getValue());
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $folha->getCell('A1')->getDataType());
        $this->assertSame(ExportacaoProdutosFiscal::COLUNAS['nome'], $export->headings()[0]);
        $this->assertSame(0, $export->array()[0][6], 'origem 0 vira a célula "0", não vazio');
    }
}
