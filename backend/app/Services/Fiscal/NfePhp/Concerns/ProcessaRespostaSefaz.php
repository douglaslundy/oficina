<?php
declare(strict_types=1);

namespace App\Services\Fiscal\NfePhp\Concerns;

use App\Services\Fiscal\Data\EmissaoResultado;
use NFePHP\NFe\Complements;

/**
 * Parsing puro (sem I/O) das respostas da SEFAZ para NF-e (modelo 55) e
 * NFC-e (modelo 65) — MESMO schema nacional (retEnviNFe/protNFe,
 * retConsSitNFe, retEnvEvento) pros dois modelos, confirmado contra os XSDs
 * oficiais em schemes/PL_009_V4/. Extraído de `MotorNfe` (2026-09-14) pra
 * ser reusado por `MotorNfce` sem duplicar — a mesma disciplina de "nunca
 * repetir lógica fiscal em dois lugares" que motivou extrair
 * `NotaFiscalDocumentoService` mais cedo nesta sessão (evita o tipo de bug
 * de divergência já visto quando dois caminhos quase-iguais saem de
 * sincronia).
 */
trait ProcessaRespostaSefaz
{
    /**
     * @see MotorNfe::processarRespostaAutorizacao() (docblock original,
     *   com todo o histórico de correções, preservado lá antes da extração)
     */
    private function processarRespostaAutorizacao(string $respostaXml, ?string $ref, string $xmlEnviado, string $numeroReal): EmissaoResultado
    {
        $sxml = @simplexml_load_string($respostaXml);
        if ($sxml === false) {
            return EmissaoResultado::erro('Resposta da SEFAZ não pôde ser interpretada.', $ref, $numeroReal);
        }
        $sxml->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $cStatLote = (string) ($sxml->xpath('//nfe:cStat')[0] ?? '');
        $protNFe   = $sxml->xpath('//nfe:protNFe') [0] ?? null;

        if ($protNFe !== null) {
            $protNFe->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
            $cStat = (string) ($protNFe->xpath('.//nfe:cStat')[0] ?? '');
            $chNFe = (string) ($protNFe->xpath('.//nfe:chNFe')[0] ?? '');
            $nProt = (string) ($protNFe->xpath('.//nfe:nProt')[0] ?? '');

            if ($cStat === '100') {
                // Complements::toAuthorize() junta a NFe/NFCe assinada com o
                // protNFe recebido, produzindo o nfeProc completo (documento
                // oficial exigido pelo mercado — ver achado de 2026-09-14 no
                // MotorNfe original). Fallback silencioso pro XML sem
                // protocolo só se a junção falhar.
                try {
                    $xmlCompleto = Complements::toAuthorize($xmlEnviado, $respostaXml);
                } catch (\Throwable) {
                    $xmlCompleto = $xmlEnviado;
                }

                // NFC-e (modelo 65): $xmlEnviado já sai de Tools::signNFe()
                // com <infNFeSupl><qrCode>...</qrCode></infNFeSupl> embutido
                // (ver docblock de MotorNfce — signNFe() adiciona isso
                // automaticamente pra modelo 65). NF-e não tem essa tag —
                // xpath vazio, `qrCodeUrl` fica null, sem efeito nenhum
                // nela. Extraído do XML ENVIADO (não do $xmlCompleto/
                // nfeProc), já que é lá que o QRCode::putQRTag() insere a
                // tag, antes da junção com o protocolo.
                $sxmlEnviado = @simplexml_load_string($xmlEnviado);
                $qrCodeUrl = null;
                if ($sxmlEnviado !== false) {
                    $sxmlEnviado->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
                    $qrCodeUrl = ($sxmlEnviado->xpath('//nfe:qrCode')[0] ?? null) !== null
                        ? (string) $sxmlEnviado->xpath('//nfe:qrCode')[0]
                        : null;
                }

                return EmissaoResultado::autorizada(
                    chave: $chNFe,
                    protocolo: $nProt,
                    numero: $numeroReal,
                    xml: $xmlCompleto,
                    pdfUrl: null,
                    ref: $ref,
                    qrCodeUrl: $qrCodeUrl,
                );
            }

            $xMotivo = (string) ($protNFe->xpath('.//nfe:xMotivo')[0] ?? 'Rejeitada pela SEFAZ.');
            return EmissaoResultado::rejeitada("cStat={$cStat}: {$xMotivo}", $ref, $numeroReal);
        }

        return EmissaoResultado::rejeitada("Lote rejeitado (cStat={$cStatLote}).", $ref, $numeroReal);
    }

    /** @see MotorNfe::extrairCStatEvento() */
    private function extrairCStatEvento(string $respostaXml): ?string
    {
        $sxml = @simplexml_load_string($respostaXml);
        if ($sxml === false) {
            return null;
        }
        $sxml->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $retEvento = $sxml->xpath('//nfe:retEvento')[0] ?? null;
        if ($retEvento === null) {
            return '';
        }
        $retEvento->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
        return (string) ($retEvento->xpath('.//nfe:cStat')[0] ?? '');
    }

    /** @see MotorNfe::processarRespostaCancelamento() */
    private function processarRespostaCancelamento(string $respostaXml, string $chave): EmissaoResultado
    {
        $cStat = $this->extrairCStatEvento($respostaXml);
        if ($cStat === null) {
            return EmissaoResultado::erro('Resposta do cancelamento não pôde ser interpretada.', $chave);
        }

        if (in_array($cStat, ['135', '136', '155'], true)) {
            return EmissaoResultado::cancelada($chave);
        }

        return EmissaoResultado::erro("Cancelamento não confirmado (cStat={$cStat}).", $chave);
    }

    /** @see MotorNfe::processarRespostaConsulta() */
    private function processarRespostaConsulta(string $respostaXml, string $chave): EmissaoResultado
    {
        $sxml = @simplexml_load_string($respostaXml);
        if ($sxml === false) {
            return EmissaoResultado::erro('Resposta da consulta não pôde ser interpretada.', $chave);
        }
        $sxml->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $cStat = (string) ($sxml->xpath('//nfe:cStat')[0] ?? '');

        return match (true) {
            $cStat === '100' => EmissaoResultado::autorizada(
                chave: $chave,
                protocolo: (string) ($sxml->xpath('//nfe:nProt')[0] ?? ''),
                numero: null,
                xml: null,
                pdfUrl: null,
                ref: $chave,
            ),
            in_array($cStat, ['101', '151'], true) => EmissaoResultado::cancelada($chave),
            default => EmissaoResultado::erro(
                "NF-e em status não reconhecido (cStat={$cStat}); não classificamos como autorizada sem confirmação.",
                $chave,
            ),
        };
    }
}
