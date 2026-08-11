<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class EmissaoResultado
{
    public function __construct(
        public readonly string $status,            // AUTORIZADA | PROCESSANDO | REJEITADA | CANCELADA
        public readonly ?string $chave = null,
        public readonly ?string $protocolo = null,
        public readonly ?string $numero = null,
        public readonly ?string $xml = null,
        public readonly ?string $pdfUrl = null,
        public readonly ?string $mensagemErro = null,
        public readonly ?string $referenciaExterna = null,
        public readonly ?string $qrCodeUrl = null,
    ) {}

    public static function autorizada(?string $chave, ?string $protocolo, ?string $numero, ?string $xml, ?string $pdfUrl, ?string $ref = null, ?string $qrCodeUrl = null): self
    {
        return new self('AUTORIZADA', $chave, $protocolo, $numero, $xml, $pdfUrl, null, $ref, $qrCodeUrl);
    }

    public static function processando(?string $ref = null): self
    {
        return new self('PROCESSANDO', null, null, null, null, null, null, $ref);
    }

    // $numero — Finding 4 do fix wave pós-revisão da Etapa C2 (2026-08-11):
    // uma nota rejeitada/com erro técnico já pode ter queimado um número real
    // de MotorNfe::proximoNumeroNfe() antes de falhar — sem isso, o número
    // fica perdido pra sempre em vez de ser reaproveitado na retentativa
    // (spec Seção B, "nota rejeitada não queima o número"). Parâmetro NOVO
    // acrescentado no FINAL da assinatura (não reordenado antes de $ref) de
    // propósito: `rejeitada($msg, $ref)`/`erro($msg, $ref)` já têm várias
    // dezenas de chamadas posicionais em FocusNfeProvider/SpedyProvider/
    // MotorNfse/testes que dependem de `$ref` ser o 2º argumento — reordenar
    // quebraria todas elas silenciosamente (nenhum erro de tipo, já que
    // ambos são `?string`). Opcional com default null: nenhuma chamada
    // existente precisa mudar.
    public static function rejeitada(string $mensagemErro, ?string $ref = null, ?string $numero = null): self
    {
        return new self('REJEITADA', null, null, $numero, null, null, $mensagemErro, $ref);
    }

    public static function cancelada(?string $ref = null): self
    {
        return new self('CANCELADA', null, null, null, null, null, null, $ref);
    }

    public static function erro(string $mensagemErro, ?string $ref = null, ?string $numero = null): self
    {
        return new self('ERRO', null, null, $numero, null, null, $mensagemErro, $ref);
    }

    // $chave/$numero — Finding 2 do fix wave: antes só carregava $xml.
    // Contingência EPEC produz uma NF-e VÁLIDA e assinada (o evento
    // registrado na SEFAZ nacional é o que a torna legal, não uma
    // autorização síncrona) — sem a chave de acesso aqui, ela nunca chega a
    // ser persistida em NotaFiscal::chave_acesso, e duas coisas quebram: (1)
    // MotorNfe::retransmitir() recusa reenviar por causa do guard
    // `empty($nota->chave_acesso)`, deixando a reconciliação agendada
    // (Task 8) sempre falhar pra notas em contingência; (2) o DANFE de
    // contingência — documento fiscal válido entregue ao cliente
    // precisamente porque carrega essa chave — imprime sem ela
    // (`@if($nota->chave_acesso)` em resources/views/pdf/danfe.blade.php
    // nunca é true). $numero segue o mesmo raciocínio do Finding 3/4: é o
    // número real alocado por MotorNfe::emitir() antes de tentar a
    // transmissão, não `null`. Único chamador de produção é
    // MotorNfe::tentarEpec() — reordenar a assinatura aqui (ao contrário de
    // rejeitada()/erro() acima) é seguro, já que não há chamadas legadas
    // fora deste arquivo e do teste correspondente, ambos atualizados juntos
    // nesta mesma leva de correções.
    public static function contingencia(string $chave, ?string $numero, string $xml, ?string $ref = null): self
    {
        return new self('CONTINGENCIA', $chave, null, $numero, $xml, null, null, $ref);
    }
}
