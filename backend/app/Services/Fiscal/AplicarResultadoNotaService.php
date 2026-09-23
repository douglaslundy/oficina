<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\NotaFiscal;
use App\Services\AlertaDispatchService;
use App\Services\Fiscal\Pdf\NotaFiscalDocumentoService;
use App\Services\PlanLimitService;

/**
 * Persiste na NotaFiscal o resultado de uma emissão/consulta de status e
 * dispara billing + alerta quando ela é autorizada em produção.
 *
 * Compartilhado por: NotaFiscalController::emitir() e ::status() (polling do
 * frontend) e o comando nfe:reconciliar-processando (varredura agendada de
 * notas presas em PROCESSANDO).
 */
class AplicarResultadoNotaService
{
    public function __construct(
        private readonly PlanLimitService $planLimit,
        private readonly AlertaDispatchService $alertas,
        private readonly NotaFiscalDocumentoService $documentos,
        private readonly FiscalProviderManager $providerManager,
    ) {}

    /**
     * @param array{status: string, chave?: ?string, protocolo?: ?string,
     *   xml_retorno?: ?string, qrcode_url?: ?string, mensagem_erro?: ?string,
     *   numero?: int|string|null} $resultado
     * @param string $ambiente Mantido no assinatura por compatibilidade com
     *   os chamadores existentes, mas NÃO é usado pra decidir se dispara os
     *   efeitos colaterais de produção (ver comentário abaixo) — quem
     *   dispatcha `EmitirNotaFiscalJob` captura esse valor no momento do
     *   `iniciar()`, que pode preceder a execução real do job (fila) por
     *   segundos ou minutos; se o ambiente for trocado nesse meio-tempo, o
     *   valor capturado fica desatualizado em relação ao ambiente que o
     *   provider realmente usou pra emitir.
     */
    public function aplicar(NotaFiscal $nota, array $resultado, string $ambiente): NotaFiscal
    {
        // Capturado ANTES do update() — este método é compartilhado pelo job
        // de emissão, pelo polling de status e pelo cron de reconciliação;
        // duas chamadas quase simultâneas observando a MESMA transição pra
        // AUTORIZADA não podem disparar o e-mail/cobrança duas vezes. Só a
        // chamada que realmente vê a nota sair de um status != AUTORIZADA
        // é que dispara os efeitos colaterais abaixo.
        $statusAnterior = $nota->status;

        $nota->update([
            'status'        => $resultado['status'],
            'chave_acesso'  => $resultado['chave'] ?? $nota->chave_acesso,
            'protocolo'     => $resultado['protocolo'] ?? $nota->protocolo,
            'xml_retorno'   => $resultado['xml_retorno'] ?? $nota->xml_retorno,
            'qrcode_url'    => $resultado['qrcode_url'] ?? null,
            'mensagem_erro' => $resultado['mensagem_erro'] ?? null,
            // Para NF-e/NFC-e o número que vale legalmente é o atribuído pela
            // Focus/SEFAZ/MotorNfe, não o contador interno gravado antes da
            // emissão. Fallback pro valor existente se o provedor não devolver
            // um número (comportamento da NFS-e).
            'numero'        => isset($resultado['numero']) ? (int) $resultado['numero'] : $nota->numero,
            // Contingência EPEC: a reconciliação agendada precisa saber desde
            // quando a nota está em contingência. Se ela sai desse estado por
            // aqui, o campo é limpo.
            'contingencia_desde' => $resultado['status'] === 'CONTINGENCIA' ? now() : null,
            'emitido_em'    => $resultado['status'] === 'AUTORIZADA' ? now() : null,
        ]);

        // Ambiente lido AGORA, não o $ambiente recebido por parâmetro — ver
        // docblock do método.
        $ambienteAtual = $this->providerManager->ambienteDaOficina();

        if ($resultado['status'] === 'AUTORIZADA' && $statusAnterior !== 'AUTORIZADA' && $ambienteAtual === 'PRODUCAO') {
            $notaFresh = $nota->fresh()->loadMissing(['cliente', 'itens']);
            $this->planLimit->registrarNotaSeExcedente($notaFresh);
            // Pedido explícito do usuário (2026-09-14): o e-mail de "NF
            // Autorizada" pro cliente deve levar o PDF e o XML da nota, não
            // só o texto. montarAnexosEmail() nunca lança — falha de render
            // vira "manda sem anexo", nunca derruba a emissão.
            $this->alertas->dispatch('NF_AUTORIZADA', [
                'nf_numero'         => $notaFresh->numero,
                'cliente'           => $notaFresh->cliente?->nome ?? '-',
                'valor'             => 'R$ ' . number_format((float) $notaFresh->valor_total, 2, ',', '.'),
                'chave_acesso'      => $notaFresh->chave_acesso ?? '-',
                '_telefone_cliente' => $notaFresh->cliente?->telefone ?? '',
                '_email_cliente'    => $notaFresh->cliente?->email ?? '',
            ], anexos: $this->documentos->montarAnexosEmail($notaFresh));
        }

        return $nota->fresh();
    }
}
