<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pdf;

use App\Models\Configuracao;
use App\Models\NotaFiscal;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Único lugar que sabe montar o PDF/XML de uma nota fiscal — extraído de
 * `NotaFiscalController` (2026-09-14) pra ser reusado também pelo envio
 * automático de e-mail com anexos ao cliente. Antes desta extração já
 * tínhamos visto bug real nascer de lógica de PDF duplicada em dois lugares
 * (a NFS-e via NFePHP tentava buscar PDF pronto num endpoint descontinuado
 * em vez de cair no mesmo template local usado por Spedy/Focus) — um único
 * ponto de verdade evita repetir esse tipo de divergência.
 */
class NotaFiscalDocumentoService
{
    /** @return array{conteudo: string, filename: string} */
    public function gerarPdf(NotaFiscal $nota): array
    {
        // NF-e emitida via NFePHP: o DANFE é montado localmente a partir do
        // XML já autorizado (DanfeRenderer) — não tem PDF pronto vindo de
        // nenhum provedor pra esse caso. NFC-e (qualquer provedor,
        // incluindo NFePHP/MotorNfce) NÃO entra aqui de propósito — o
        // documento correto pra NFC-e é o cupom 80mm (`montarPdfArquivo()`
        // abaixo, mesmo template já usado por Spedy/Focus), nunca o DANFE
        // A4 de NF-e. Achado ao implementar MotorNfce (2026-09-14): antes
        // dessa correção esta condição já incluía 'NFC-e', mas nunca era
        // alcançável (NFEPHP não tinha motor de NFC-e) — agora que tem,
        // teria produzido o layout errado (DANFE cheio em vez de cupom).
        if ($nota->provedor === 'NFEPHP' && $nota->modelo === 'NF-e' && in_array($nota->status, ['AUTORIZADA', 'CONTINGENCIA'], true)) {
            $dados = app(DanfeRenderer::class)->dadosParaTemplate($nota);
            $dados['barcodeDataUri'] = $this->gerarBarcodeChaveDataUri($nota->chave_acesso);
            $pdf = Pdf::loadView('pdf.danfe', $dados)->setPaper('a4', 'portrait');

            return ['conteudo' => $pdf->output(), 'filename' => 'NFe-' . ($nota->numero ?? $nota->id) . '.pdf'];
        }

        $empresa = Configuracao::first()?->toArray() ?? [];
        $arquivo = $this->montarPdfArquivo($nota, $empresa);

        return ['conteudo' => $arquivo['pdf']->output(), 'filename' => $arquivo['filename']];
    }

    /** @return array{conteudo: string, filename: string}|null null quando a nota não tem XML salvo ainda. */
    public function xml(NotaFiscal $nota): ?array
    {
        if (empty($nota->xml_retorno)) {
            return null;
        }

        $prefixo = $nota->modelo === 'NFS-e' ? 'NFSe-' : ($nota->modelo === 'NFC-e' ? 'NFCe-' : 'NFe-');

        return ['conteudo' => $nota->xml_retorno, 'filename' => $prefixo . ($nota->numero ?? $nota->id) . '.xml'];
    }

    /**
     * Monta os anexos (PDF + XML, quando disponíveis) no formato que
     * `AlertaDispatchService::dispatch()` espera, pro e-mail automático de
     * "NF Autorizada" mandar os documentos de verdade pro cliente. Nunca
     * lança — uma falha de render de PDF não pode derrubar a emissão nem o
     * disparo do alerta de texto, só significa "manda sem PDF anexado".
     *
     * @return array<int, array{conteudo: string, filename: string, mime: string}>
     */
    public function montarAnexosEmail(NotaFiscal $nota): array
    {
        $anexos = [];

        try {
            $pdf = $this->gerarPdf($nota);
            $anexos[] = ['conteudo' => $pdf['conteudo'], 'filename' => $pdf['filename'], 'mime' => 'application/pdf'];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('NotaFiscalDocumentoService: falha ao gerar PDF pra anexar no e-mail.', [
                'nota_id' => $nota->id, 'erro' => $e->getMessage(),
            ]);
        }

        $xml = $this->xml($nota);
        if ($xml !== null) {
            $anexos[] = ['conteudo' => $xml['conteudo'], 'filename' => $xml['filename'], 'mime' => 'application/xml'];
        }

        return $anexos;
    }

    /**
     * Escolhe o template certo — cupom 80mm pra NFC-e, DANFE-style A4 pra
     * NF-e (produto), layout de NFS-e municipal pra NFS-e (serviço).
     *
     * @return array{pdf: \Barryvdh\DomPDF\PDF, filename: string}
     */
    public function montarPdfArquivo(NotaFiscal $nota, array $empresa): array
    {
        if ($nota->modelo === 'NFC-e') {
            $qrCodeDataUri = $this->gerarQrCodeDataUri($nota);
            $pdf = Pdf::loadView('pdf.nota_fiscal_nfce', compact('nota', 'empresa', 'qrCodeDataUri'))
                ->setPaper([0, 0, 226.77, $this->alturaCupomNfce($nota)], 'portrait');

            return ['pdf' => $pdf, 'filename' => 'NFCe-' . ($nota->numero ?? $nota->id) . '.pdf'];
        }

        if ($nota->modelo === 'NF-e') {
            $barcodeDataUri = $this->gerarBarcodeChaveDataUri($nota->chave_acesso);
            $pdf = Pdf::loadView('pdf.nota_fiscal_nfe', compact('nota', 'empresa', 'barcodeDataUri'))
                ->setPaper('a4', 'portrait');

            return ['pdf' => $pdf, 'filename' => 'NFe-' . ($nota->numero ?? $nota->id) . '.pdf'];
        }

        // NFS-e: DANFSe v2.0, clone do PDF oficial do Emissor Nacional (ver DanfseRenderer).
        $dados = app(DanfseRenderer::class)->dadosParaTemplate(
            $nota,
            $empresa,
            $this->gerarQrCodeNfseDataUri($nota),
            $this->logoDanfseDataUri(),
        );
        $pdf = Pdf::loadView('pdf.danfse', $dados)->setPaper([0, 0, 595, 842], 'portrait'); // 595x842pt, igual ao original

        return ['pdf' => $pdf, 'filename' => 'NFSe-' . ($nota->numero ?? $nota->id) . '.pdf'];
    }

    // ~260pt de cabeçalho/rodapé/totais fixos + ~14pt por item + ~110pt pro QR
    // code quando presente. Altura dinâmica porque o cupom térmico não tem
    // página de tamanho fixo como o A4.
    private function alturaCupomNfce(NotaFiscal $nota): float
    {
        return 260.0 + ($nota->itens->count() * 14) + ($nota->qrcode_url ? 110.0 : 0.0);
    }

    /**
     * Pedido explícito do usuário (2026-09-14, análise visual comparando com
     * os modelos oficiais em doc_documentos_fiscais/modelo_nota): o DANFE
     * real tem um código de barras Code-128C da chave de acesso, acima do
     * texto "CHAVE DE ACESSO" — o nosso não tinha (já registrado como
     * limitação conhecida no docblock de DanfeRenderer desde a Rodada da
     * refatoração de PDF). Code-128C é o subtipo correto pra uma cadeia
     * numérica de 44 dígitos com comprimento par (confirmado no Manual de
     * Orientação do Contribuinte — não é um chute: é o mesmo subtipo que o
     * modelo de referência da Spedy usa). `picqer/php-barcode-generator`
     * (adicionado nesta sessão) gera o PNG via GD, sem depender de asset
     * externo — mesmo padrão já usado pro QR Code da NFC-e (endroid/qr-code).
     */
    private function gerarBarcodeChaveDataUri(?string $chaveAcesso): ?string
    {
        if (empty($chaveAcesso) || strlen($chaveAcesso) !== 44 || !ctype_digit($chaveAcesso)) {
            return null;
        }

        $gerador = new \Picqer\Barcode\BarcodeGeneratorPNG();
        $png = $gerador->getBarcode($chaveAcesso, \Picqer\Barcode\BarcodeGeneratorPNG::TYPE_CODE_128_C, 1, 40);

        return 'data:image/png;base64,' . base64_encode($png);
    }

    /**
     * QR do DANFSe: mesma URL do original (decodificada do PDF oficial):
     * https://www.nfse.gov.br/ConsultaPublica?tpc=1&chave=<50 dígitos>.
     */
    private function gerarQrCodeNfseDataUri(NotaFiscal $nota): ?string
    {
        $chave = (string) preg_replace('/^NFS/', '', (string) ($nota->chave_acesso ?? ''));
        if (preg_match('/^\d{50}$/', $chave) !== 1) {
            return null;
        }

        return (new \Endroid\QrCode\Builder\Builder(
            writer: new \Endroid\QrCode\Writer\PngWriter(),
            data: 'https://www.nfse.gov.br/ConsultaPublica?tpc=1&chave=' . $chave,
            size: 450,
            margin: 22, // o QR do original traz zona de silêncio dentro da imagem de 45pt
        ))->build()->getDataUri();
    }

    private function logoDanfseDataUri(): string
    {
        return 'data:image/png;base64,' . base64_encode((string) file_get_contents(resource_path('images/danfse-logo.png')));
    }

    private function gerarQrCodeDataUri(NotaFiscal $nota): ?string
    {
        if (empty($nota->qrcode_url)) {
            return null;
        }

        // endroid/qr-code 6.x: a API antiga fluente (Builder::create()->
        // writer()->data()->size()->margin()->build()) foi substituída por
        // argumentos nomeados no construtor.
        $qrCode = (new \Endroid\QrCode\Builder\Builder(
            writer: new \Endroid\QrCode\Writer\PngWriter(),
            data: $nota->qrcode_url,
            size: 200,
            margin: 0,
        ))->build();

        return $qrCode->getDataUri();
    }
}
