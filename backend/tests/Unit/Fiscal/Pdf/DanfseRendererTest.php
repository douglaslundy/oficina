<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\Pdf;

use App\Models\Cliente;
use App\Models\NotaFiscal;
use App\Services\Fiscal\Pdf\DanfseRenderer;
use App\Services\Fiscal\Pdf\NotaFiscalDocumentoService;
use Tests\TestCase;

/**
 * DANFSe v2.0 — clone do PDF oficial do Emissor Nacional.
 * A fixture é o XML de uma NFS-e nacional autorizada, ANONIMIZADO (dados fictícios, sem
 * assinatura); a estrutura é a real. O PDF-modelo original é particular e não fica no repositório.
 */
class DanfseRendererTest extends TestCase
{
    private function notaNacional(array $atributos = []): NotaFiscal
    {
        $n = new NotaFiscal();
        $n->modelo = 'NFS-e';
        $n->numero = 2;
        $n->status = 'AUTORIZADA';
        $n->ambiente = 'PRODUCAO';
        $n->xml_retorno = file_get_contents(base_path('tests/Fixtures/nfse_nacional_autorizada.xml'));
        $n->chave_acesso = '31305072212345678000195000000000000226096821694069';
        foreach ($atributos as $k => $v) {
            $n->{$k} = $v;
        }

        return $n;
    }

    /** @return array<string, string> texto por "x|y" não serve; indexa por conteúdo pra checar presença */
    private function textos(NotaFiscal $n, array $empresa = []): array
    {
        $d = (new DanfseRenderer())->dadosParaTemplate($n, $empresa, null, 'data:image/png;base64,AA==');

        return array_column($d['textos'], 't');
    }

    public function test_campos_saem_do_xml_como_no_danfse_oficial(): void
    {
        $t = $this->textos($this->notaNacional());

        foreach ([
            'DANFSe v2.0', 'Município: Ilicínea - MG', 'Ambiente Gerador: 2', 'Tipo de Ambiente: 1',
            '31305072212345678000195000000000000226096821694069',      // chave SEM o prefixo "NFS"
            '25/09/2026', '25/09/2026 15:51:40', '25/09/2026 15:51:39', // competência, emissão, DPS
            'Prestador', 'NFS-e Gerada',
            '12.345.678/0001-95', 'OFICINA EXEMPLO LTDA', 'Ilicínea / MG', '31.30507 / 37.175-000',
            'RUA EXEMPLO, 100, CENTRO',
            'Regime de apuração dos tributos federais e municipal pelo Simples Nacional',
            '11.222.333/0001-81', 'EMPRESA CLIENTE LTDA',
            '14.01.01 / -', 'Operação Tributável', 'Não Retido', 'R$ 100,00',
            'regular valvula; tirar vazamento na tampa de valvul',
            'Inf. Cont.: Empresa optante pelo Simples Nacional. Placa: ABC1D23 | Modelo: HONDA CG 160 CARGO C - 2019 - ABC1D23 | KM: 40332',
            'Totais aproximados dos Tributos cfe. Lei n° 12.741/2012: Federais: -; Estaduais: -; Municipais: -;',
        ] as $esperado) {
            $this->assertContains($esperado, $t, "faltou: $esperado");
        }
    }

    public function test_textos_longos_sao_cortados_com_reticencias_como_no_original(): void
    {
        $t = $this->textos($this->notaNacional());

        $this->assertContains('Optante - Microempresa ou Empresa de ...', $t);
        $cortado = array_values(array_filter($t, static fn (string $s): bool => str_starts_with($s, 'Lubrificação, limpeza')))[0];
        $this->assertStringEndsWith('aparelhos, equipamentos, ...', $cortado);
    }

    public function test_nota_cancelada_mostra_situacao_cancelada(): void
    {
        $this->assertContains('NFS-e Cancelada', $this->textos($this->notaNacional(['status' => 'CANCELADA'])));
    }

    public function test_descricao_longa_quebra_em_linhas_e_empurra_o_resto_da_pagina(): void
    {
        $longa = str_repeat('troca de oleo e filtro com revisao completa do motor ', 30);
        $xml = str_replace('regular valvula; tirar vazamento na tampa de valvul', $longa, (string) $this->notaNacional()->xml_retorno);
        $r = new DanfseRenderer();

        $normal = $r->dadosParaTemplate($this->notaNacional(), [], null, 'x');
        $comLonga = $r->dadosParaTemplate($this->notaNacional(['xml_retorno' => $xml]), [], null, 'x');

        $yTotal = static fn (array $d): float => (float) array_values(array_filter($d['textos'], static fn (array $t): bool => $t['t'] === 'VALOR TOTAL DA NFS-e'))[0]['y'];
        $this->assertGreaterThan($yTotal($normal) + 15, $yTotal($comLonga), 'o bloco de valores deve descer quando a descrição ocupa mais linhas');
    }

    public function test_sem_xml_nacional_usa_os_dados_do_banco_e_nao_quebra(): void
    {
        $n = new NotaFiscal();
        $n->modelo = 'NFS-e';
        $n->numero = 7;
        $n->status = 'AUTORIZADA';
        $n->valor_total = 250.5;
        $n->observacoes = 'Troca de pastilhas';
        $n->setRelation('cliente', new Cliente(['nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678909']));

        $t = $this->textos($n, ['razao_social' => 'OFICINA X', 'cnpj' => '12345678000199', 'cidade' => 'Ilicínea', 'uf' => 'MG', 'regime_tributario' => 'Simples Nacional']);

        $this->assertContains('OFICINA X', $t);
        $this->assertContains('12.345.678/0001-99', $t);
        $this->assertContains('123.456.789-09', $t);
        $this->assertContains('R$ 250,50', $t);
        $this->assertContains('Troca de pastilhas', $t);
    }

    public function test_pdf_da_nfse_sai_como_nfse_a4_595x842(): void
    {
        $arquivo = app(NotaFiscalDocumentoService::class)->montarPdfArquivo($this->notaNacional(), []);
        $bytes = $arquivo['pdf']->output();

        $this->assertSame('NFSe-2.pdf', $arquivo['filename']);
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertMatchesRegularExpression('#/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+595(\.0+)?\s+842(\.0+)?\s*\]#', $bytes);
    }
}
