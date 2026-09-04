<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\NotaFiscal;
use App\Services\AlertaDispatchService;
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
    ) {}

    /**
     * @param array{status: string, chave?: ?string, protocolo?: ?string,
     *   xml_retorno?: ?string, qrcode_url?: ?string, mensagem_erro?: ?string,
     *   numero?: int|string|null} $resultado
     */
    public function aplicar(NotaFiscal $nota, array $resultado, string $ambiente): NotaFiscal
    {
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

        if ($resultado['status'] === 'AUTORIZADA' && $ambiente === 'PRODUCAO') {
            $notaFresh = $nota->fresh()->loadMissing('cliente');
            $this->planLimit->registrarNotaSeExcedente($notaFresh);
            $this->alertas->dispatch('NF_AUTORIZADA', [
                'nf_numero'         => $notaFresh->numero,
                'cliente'           => $notaFresh->cliente?->nome ?? '-',
                'valor'             => 'R$ ' . number_format((float) $notaFresh->valor_total, 2, ',', '.'),
                'chave_acesso'      => $notaFresh->chave_acesso ?? '-',
                '_telefone_cliente' => $notaFresh->cliente?->telefone ?? '',
                '_email_cliente'    => $notaFresh->cliente?->email ?? '',
            ]);
        }

        return $nota->fresh();
    }
}
