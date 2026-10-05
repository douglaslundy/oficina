<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\Pdf;

use App\Models\Cliente;
use App\Models\NotaFiscal;
use App\Models\OrdemServico;
use App\Models\OsItem;
use App\Models\OsPagamento;
use App\Services\Fiscal\Pdf\CupomPdfService;
use App\Services\Fiscal\Pdf\CupomRenderer;
use App\Services\Fiscal\Pdf\NotaFiscalDocumentoService;
use Tests\TestCase;

/**
 * DANFE NFC-e (leiaute oficial) e cupom não fiscal, nos dois papéis (80 mm e A4).
 */
class CupomRendererTest extends TestCase
{
    private const CHAVE = '31260111222333000181650010000000121000000127';

    /** @return array<string, string> */
    private function empresa(string $impressora = '80MM'): array
    {
        return [
            'razao_social' => 'OFICINA EXEMPLO LTDA', 'nome_fantasia' => 'Oficina Exemplo',
            'cnpj' => '11222333000181', 'inscricao_estadual' => '1234567890',
            'logradouro' => 'Av Central', 'numero' => '100', 'bairro' => 'Centro', 'cidade' => 'Ilicínea', 'uf' => 'MG',
            'telefone' => '(35) 3333-4444', 'impressora_cupom' => $impressora,
        ];
    }

    private function nota(array $over = []): NotaFiscal
    {
        $nota = new NotaFiscal(array_merge([
            'numero' => 12, 'serie' => '1', 'modelo' => 'NFC-e', 'status' => 'AUTORIZADA',
            'chave_acesso' => self::CHAVE, 'protocolo' => '131260000000001', 'ambiente' => 'PRODUCAO',
            'xml_retorno' => (string) file_get_contents(base_path('tests/Fixtures/nfce_autorizada.xml')),
            'valor_total' => 242.60, 'forma_pagamento' => 'PIX',
        ], $over));
        $nota->setRelation('itens', collect());
        $nota->setRelation('cliente', null);

        return $nota;
    }

    private function os(): OrdemServico
    {
        $os = new OrdemServico([
            'numero' => 77, 'valor_total' => 242.6, 'valor_pago' => 200, 'desconto' => 0,
            'veiculo_descricao' => 'Honda CG 160', 'veiculo_placa' => 'ABC1D23',
        ]);
        $os->setRelation('cliente', new Cliente(['nome' => 'Maria Consumidora']));
        $os->setRelation('itens', collect([
            new OsItem(['tipo' => 'SERVICO', 'descricao' => 'Troca de óleo', 'quantidade' => 1, 'valor_unitario' => 80]),
            new OsItem(['tipo' => 'PECA', 'descricao' => 'Filtro de óleo', 'quantidade' => 2, 'valor_unitario' => 35.5]),
        ]));
        $os->setRelation('pagamentos', collect([new OsPagamento(['forma_pagamento' => 'PIX', 'valor' => 200])]));

        return $os;
    }

    public function test_dados_nfce_leem_o_que_foi_autorizado_no_xml(): void
    {
        $d = (new CupomRenderer())->dadosNfce($this->nota(), $this->empresa(), null);

        $this->assertSame(2, $d['qtd_itens']);
        $this->assertSame('FLT-001', $d['itens'][0]['codigo']);
        $this->assertSame(242.60, $d['valor_a_pagar']);
        $this->assertSame([['forma' => 'PIX', 'valor' => 242.60]], $d['pagamentos']);
        $this->assertSame(31.54, $d['tributos_totais']);
        $this->assertSame('https://nfce.exemplo.gov.br/consulta', $d['url_consulta']);
        $this->assertSame('3126 0111 2223 3300 0181 6500 1000 0000 1210 0000 0127', $d['chave_formatada']);
        $this->assertSame('529.982.247-25', $d['consumidor_doc']);
        $this->assertSame('131260000000001', $d['protocolo']);
        $this->assertSame('05/10/2026 10:15:40', $d['data_autorizacao']);
        $this->assertSame('05/10/2026 10:15:30', $d['emissao']);
        $this->assertSame('Empresa optante pelo Simples Nacional.', $d['info_adicional']);
        $this->assertSame([], $d['mensagens_fiscais']);
        $this->assertSame('11.222.333/0001-81', $d['emitente']['cnpj']);
    }

    public function test_homologacao_e_contingencia_imprimem_a_mensagem_fiscal(): void
    {
        $xml = str_replace('<tpAmb>1</tpAmb><finNFe>', '<tpAmb>2</tpAmb><finNFe>', (string) file_get_contents(base_path('tests/Fixtures/nfce_autorizada.xml')));
        $d = (new CupomRenderer())->dadosNfce($this->nota(['xml_retorno' => $xml, 'status' => 'CONTINGENCIA']), $this->empresa(), null);

        $this->assertContains('EMITIDA EM AMBIENTE DE HOMOLOGAÇÃO - SEM VALOR FISCAL', $d['mensagens_fiscais']);
        $this->assertContains('EMITIDA EM CONTINGÊNCIA', $d['mensagens_fiscais']);
        $this->assertFalse($d['autorizada']);
    }

    public function test_sem_xml_cai_pro_banco_e_consumidor_nao_identificado(): void
    {
        $nota = $this->nota(['xml_retorno' => null]);
        $nota->setRelation('itens', collect([new \App\Models\NotaFiscalItem([
            'descricao' => 'Filtro', 'sku' => 'FLT-001', 'unidade' => 'PC', 'quantidade' => 2, 'valor_unitario' => 35.5,
        ])]));

        $d = (new CupomRenderer())->dadosNfce($nota, $this->empresa(), null);

        $this->assertSame(1, $d['qtd_itens']);
        $this->assertSame(71.0, $d['itens'][0]['vl_total']);
        $this->assertSame('', $d['consumidor_doc']);
        $this->assertSame('', $d['url_consulta']);
        $this->assertNull($d['tributos_totais']);
    }

    public function test_tributos_totais_saem_do_percentual_quando_o_xml_nao_traz(): void
    {
        $xml = str_replace('<vTotTrib>31.54</vTotTrib>', '', (string) file_get_contents(base_path('tests/Fixtures/nfce_autorizada.xml')));
        $nota = $this->nota(['xml_retorno' => $xml]);

        $sem = (new CupomRenderer())->dadosNfce($nota, $this->empresa(), null);
        $com = (new CupomRenderer())->dadosNfce($nota, $this->empresa() + ['percentual_tributos_aproximados' => 10], null);

        $this->assertNull($sem['tributos_totais']);
        $this->assertSame(24.26, $com['tributos_totais']); // 10% de 242,60
    }

    public function test_cupom_80mm_e_uma_pagina_de_80mm_e_a4_e_folha_a4(): void
    {
        $svc = app(NotaFiscalDocumentoService::class);

        foreach (['80MM' => 226.77, 'A4' => 595.28] as $papel => $larguraEsperada) {
            $pdf = $svc->gerarCupomNfce($this->nota(), $this->empresa($papel));
            $this->assertStringStartsWith('%PDF', $pdf);
            $this->assertSame(1, preg_match_all('#/Type\s*/Page\b#', $pdf), "{$papel}: deve ter 1 página");
            preg_match('#/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]#', $pdf, $m);
            $this->assertEqualsWithDelta($larguraEsperada, (float) $m[1], 0.5, "{$papel}: largura");
            if ($papel === '80MM') {
                $this->assertLessThan(800.0, (float) $m[2], 'altura ajustada ao conteúdo, não folha cheia');
            }
        }
    }

    public function test_cupom_nao_fiscal_traz_so_dados_da_venda_e_aviso(): void
    {
        $d = (new CupomRenderer())->dadosNaoFiscal($this->os(), $this->empresa());

        $this->assertSame('77', $d['os_numero']);
        $this->assertSame(151.0, $d['subtotal']);
        $this->assertSame(242.6, $d['total']);
        $this->assertSame(42.6, $d['saldo']);
        $this->assertSame([['forma' => 'PIX', 'valor' => 200.0]], $d['pagamentos']);
        $this->assertArrayNotHasKey('chave', $d);
        $this->assertArrayNotHasKey('protocolo', $d);

        $html = view('pdf.cupom.nao_fiscal', $d + ['papel' => '80MM'])->render();
        $this->assertStringContainsString('SEM VALOR FISCAL', $html);
        $this->assertStringContainsString('NÃO É DOCUMENTO FISCAL', $html);
        $this->assertStringNotContainsString('DANFE', $html);
        $this->assertStringNotContainsString('CHAVE DE ACESSO', $html);

        $pdf = app(CupomPdfService::class)->gerar('pdf.cupom.nao_fiscal', $d, 'A4');
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_impressora_invalida_cai_em_80mm(): void
    {
        $this->assertSame('80MM', CupomPdfService::impressoraValida(null));
        $this->assertSame('80MM', CupomPdfService::impressoraValida('QUALQUER'));
        $this->assertSame('A4', CupomPdfService::impressoraValida('A4'));
    }
}
