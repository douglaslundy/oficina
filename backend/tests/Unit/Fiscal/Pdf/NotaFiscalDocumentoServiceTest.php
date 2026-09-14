<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\Pdf;

use App\Models\NotaFiscal;
use App\Services\Fiscal\Pdf\NotaFiscalDocumentoService;
use Mockery;
use Tests\TestCase;

/**
 * Pedido explícito do usuário (2026-09-14): botão de baixar XML e e-mail
 * automático com PDF+XML pra nota autorizada — nenhum dos dois existia.
 * `NotaFiscalDocumentoService` foi extraído de `NotaFiscalController` (que
 * já tinha a lógica de PDF) especificamente pra ser reusado aqui também.
 */
class NotaFiscalDocumentoServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function notaSemPersistir(array $overrides = []): NotaFiscal
    {
        $nota = new NotaFiscal(array_merge([
            'modelo' => 'NF-e', 'numero' => 42, 'status' => 'AUTORIZADA',
        ], $overrides));
        $nota->id = 'fake-id-123';
        return $nota;
    }

    public function test_xml_retorna_null_quando_nota_nao_tem_xml_salvo(): void
    {
        $nota = $this->notaSemPersistir(['xml_retorno' => null]);

        $this->assertNull((new NotaFiscalDocumentoService())->xml($nota));
    }

    public function test_xml_retorna_conteudo_e_nome_de_arquivo_por_modelo(): void
    {
        $svc = new NotaFiscalDocumentoService();

        $nfe = $this->notaSemPersistir(['modelo' => 'NF-e', 'numero' => 10, 'xml_retorno' => '<NFe/>']);
        $this->assertSame(['conteudo' => '<NFe/>', 'filename' => 'NFe-10.xml'], $svc->xml($nfe));

        $nfse = $this->notaSemPersistir(['modelo' => 'NFS-e', 'numero' => 11, 'xml_retorno' => '<Nfse/>']);
        $this->assertSame(['conteudo' => '<Nfse/>', 'filename' => 'NFSe-11.xml'], $svc->xml($nfse));

        $nfce = $this->notaSemPersistir(['modelo' => 'NFC-e', 'numero' => 12, 'xml_retorno' => '<NFCe/>']);
        $this->assertSame(['conteudo' => '<NFCe/>', 'filename' => 'NFCe-12.xml'], $svc->xml($nfce));
    }

    public function test_xml_usa_id_no_nome_do_arquivo_quando_nao_tem_numero_ainda(): void
    {
        $nota = $this->notaSemPersistir(['modelo' => 'NF-e', 'numero' => null, 'xml_retorno' => '<NFe/>']);

        $this->assertSame('NFe-fake-id-123.xml', (new NotaFiscalDocumentoService())->xml($nota)['filename']);
    }

    public function test_montar_anexos_email_inclui_pdf_e_xml_quando_ambos_disponiveis(): void
    {
        $nota = $this->notaSemPersistir(['xml_retorno' => '<NFe/>']);

        /** @var NotaFiscalDocumentoService&\Mockery\MockInterface $svc */
        $svc = Mockery::mock(NotaFiscalDocumentoService::class)->makePartial();
        $svc->shouldReceive('gerarPdf')->once()->with($nota)
            ->andReturn(['conteudo' => '%PDF-1.4...', 'filename' => 'NFe-42.pdf']);

        $anexos = $svc->montarAnexosEmail($nota);

        $this->assertCount(2, $anexos);
        $this->assertSame('application/pdf', $anexos[0]['mime']);
        $this->assertSame('NFe-42.pdf', $anexos[0]['filename']);
        $this->assertSame('application/xml', $anexos[1]['mime']);
        $this->assertSame('NFe-42.xml', $anexos[1]['filename']);
    }

    /**
     * Regra explícita (ver docblock do método): uma falha ao renderizar o
     * PDF nunca pode derrubar o disparo do alerta/e-mail inteiro — o e-mail
     * ainda sai, só sem o PDF anexado (aqui, ainda com o XML).
     */
    public function test_montar_anexos_email_nao_lanca_quando_gerar_pdf_falha(): void
    {
        $nota = $this->notaSemPersistir(['xml_retorno' => '<NFe/>']);

        /** @var NotaFiscalDocumentoService&\Mockery\MockInterface $svc */
        $svc = Mockery::mock(NotaFiscalDocumentoService::class)->makePartial();
        $svc->shouldReceive('gerarPdf')->once()->with($nota)->andThrow(new \RuntimeException('falha de render'));

        $anexos = $svc->montarAnexosEmail($nota);

        $this->assertCount(1, $anexos);
        $this->assertSame('application/xml', $anexos[0]['mime']);
    }

    public function test_montar_anexos_email_fica_vazio_quando_nao_ha_pdf_nem_xml(): void
    {
        $nota = $this->notaSemPersistir(['xml_retorno' => null]);

        /** @var NotaFiscalDocumentoService&\Mockery\MockInterface $svc */
        $svc = Mockery::mock(NotaFiscalDocumentoService::class)->makePartial();
        $svc->shouldReceive('gerarPdf')->once()->with($nota)->andThrow(new \RuntimeException('falha de render'));

        $this->assertSame([], $svc->montarAnexosEmail($nota));
    }
}
