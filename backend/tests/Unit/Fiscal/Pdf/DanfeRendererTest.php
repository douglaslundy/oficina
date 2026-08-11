<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\Pdf;

use App\Models\NotaFiscal;
use App\Services\Fiscal\Pdf\DanfeRenderer;
use PHPUnit\Framework\TestCase;

class DanfeRendererTest extends TestCase
{
    public function test_dados_para_template_sem_xml_retorna_itens_vazios(): void
    {
        $nota = new NotaFiscal(['numero' => 1, 'valor_total' => 100]);
        $nota->setRelation('itens', collect());

        $renderer = new DanfeRenderer();
        $dados = $renderer->dadosParaTemplate($nota);

        $this->assertSame([], $dados['itens']);
        $this->assertSame($nota, $dados['nota']);
    }

    public function test_dados_para_template_com_xml_malformado_cai_no_fallback_sem_lancar(): void
    {
        $nota = new NotaFiscal(['numero' => 1, 'valor_total' => 100, 'xml_retorno' => '<<<not valid xml']);
        $nota->setRelation('itens', collect());

        $renderer = new DanfeRenderer();
        $dados = $renderer->dadosParaTemplate($nota);

        $this->assertSame([], $dados['itens']);
        $this->assertSame($nota, $dados['nota']);
    }
}
