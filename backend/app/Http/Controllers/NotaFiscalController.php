<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\NotaFiscalResource;
use App\Models\NotaFiscal;
use App\Services\AlertaDispatchService;
use App\Services\Fiscal\AplicarResultadoNotaService;
use App\Services\NfeService;
use App\Services\PlanLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use ZipArchive;

class NotaFiscalController extends Controller
{
    public function __construct(
        private readonly NfeService $nfeService,
        private readonly PlanLimitService $planLimit,
        private readonly AlertaDispatchService $alertas,
        private readonly AplicarResultadoNotaService $aplicarResultado,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = NotaFiscal::with('cliente')->orderBy('criado_em', 'desc');

        if ($request->has('status')) {
            $query->whereIn('status', explode(',', (string)$request->status));
        }
        if ($request->has('cliente_id')) {
            $query->where('cliente_id', $request->cliente_id);
        }
        if ($request->has('modelo')) {
            $query->where('modelo', $request->modelo);
        }

        return NotaFiscalResource::collection($query->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cliente_id'        => ['required', 'string', 'exists:clientes,id'],
            'os_id'             => ['nullable', 'string', 'exists:ordens_servico,id'],
            'natureza_operacao' => ['required', 'string', 'in:Prestação de Serviços,Venda de Mercadoria'],
            'forma_pagamento'   => ['nullable', 'string', 'max:30'],
            'subtotal'          => ['required_if:natureza_operacao,Prestação de Serviços', 'nullable', 'numeric', 'min:0'],
            'desconto'          => ['nullable', 'numeric', 'min:0'],
            'aliquota_iss'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'observacoes'       => ['nullable', 'string'],
            'forcar_nfe'             => ['nullable', 'boolean'],
            'itens'                  => ['required_if:natureza_operacao,Venda de Mercadoria', 'array'],
            'itens.*.produto_id'     => ['required_with:itens', 'uuid', 'exists:produtos,id'],
            'itens.*.quantidade'     => ['required_with:itens', 'numeric', 'min:0.01'],
            'itens.*.valor_unitario' => ['required_with:itens', 'numeric', 'min:0'],
        ]);

        // Toda a lógica fiscal (checagem de UF/regime/pendências, seleção
        // NFC-e vs NF-e, resolução de CFOP/CST-CSOSN, série, criação) vive
        // em CriarNotaFiscalService — reaproveitada pelo
        // EmissaoOrquestradorService (OS mista → 2 notas). Bloqueio fiscal
        // vira EmissaoBloqueadaException aqui traduzida pra 422.
        try {
            $nota = app(\App\Services\Fiscal\CriarNotaFiscalService::class)->criar($validated);
        } catch (\App\Exceptions\EmissaoBloqueadaException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new NotaFiscalResource($nota->load(['cliente', 'itens'])))->response()->setStatusCode(201);
    }

    public function show(string $id): NotaFiscalResource
    {
        return new NotaFiscalResource(NotaFiscal::with('cliente')->findOrFail($id));
    }

    public function emitir(string $id): JsonResponse
    {
        $nota = NotaFiscal::with(['cliente', 'itens'])->findOrFail($id);

        if ($nota->status === 'AUTORIZADA') {
            return response()->json(['message' => 'NF já foi emitida.'], 400);
        }
        if ($nota->status === 'PROCESSANDO') {
            return response()->json(['message' => 'Emissão já em andamento — consulte GET /notas-fiscais/{id}/status.'], 409);
        }

        // Resolve provedor, aloca o número (síncrono/transacional) e
        // enfileira o EmitirNotaFiscalJob. Em teste/local (QUEUE=sync) o job
        // roda inline, então $nota->fresh() já traz o status final —
        // comportamento idêntico ao síncrono anterior pros testes.
        app(\App\Services\Fiscal\IniciarEmissaoNotaService::class)->iniciar($nota);

        return response()->json(['data' => new NotaFiscalResource($nota->fresh()->load('cliente'))]);
    }

    public function status(string $id): JsonResponse
    {
        $nota = NotaFiscal::with(['cliente', 'itens'])->findOrFail($id);

        if ($nota->status !== 'PROCESSANDO') {
            return response()->json(['data' => new NotaFiscalResource($nota)]);
        }

        $ambiente = app(\App\Services\Fiscal\FiscalProviderManager::class)->ambienteDaOficina();

        try {
            $resultado = $this->nfeService->consultarStatus($nota);
            $nota      = $this->aplicarResultadoEmissao($nota, $resultado, $ambiente);
        } catch (\Exception $e) {
            // Falha ao consultar não é erro fatal pro polling — a nota continua
            // PROCESSANDO, o frontend tenta de novo no próximo tick.
            return response()->json(['data' => new NotaFiscalResource($nota)]);
        }

        return response()->json(['data' => new NotaFiscalResource($nota->fresh()->load('cliente'))]);
    }

    /** @deprecated fino wrapper — a lógica vive em AplicarResultadoNotaService (reusada pelo comando de reconciliação). */
    private function aplicarResultadoEmissao(NotaFiscal $nota, array $resultado, string $ambiente): NotaFiscal
    {
        return $this->aplicarResultado->aplicar($nota, $resultado, $ambiente);
    }

    /**
     * Retransmissão manual de NF-e presa em CONTINGÊNCIA (EPEC) — até aqui só
     * existia a varredura agendada `nfe:reconciliar-contingencia` (roda de
     * hora em hora); usuário pediu uma forma de tentar autorizar na hora, sem
     * esperar o próximo ciclo.
     *
     * Reusa `MotorNfe::retransmitir()` (mesmo método do comando agendado —
     * reenvia o XML já salvo, nunca remonta, pra não mudar a chave de acesso
     * já impressa no DANFE de contingência entregue ao cliente).
     *
     * NÃO usa `AplicarResultadoNotaService::aplicar()` aqui de propósito:
     * aquele serviço sempre zera `contingencia_desde` quando o resultado não
     * é CONTINGENCIA — correto pro fluxo normal de emissão (onde o campo só
     * é setado ao ENTRAR em contingência), mas errado aqui: se a
     * retransmissão falhar de novo (ERRO/REJEITADA) a nota CONTINUA em
     * contingência e o relógio dos 7 dias legais do EPEC (`PrazoContingencia`)
     * não pode ser resetado a cada tentativa manual. Mesmo raciocínio já
     * aplicado em `ReconciliarContingenciaNfe` — só zera em AUTORIZADA/
     * CANCELADA (resolução final), preserva em qualquer outro caso.
     */
    public function retransmitirContingencia(string $id): JsonResponse
    {
        $nota = NotaFiscal::with(['cliente', 'itens'])->findOrFail($id);

        if ($nota->status !== 'CONTINGENCIA') {
            return response()->json(['message' => 'Só é possível retransmitir notas em contingência.'], 422);
        }

        $ambiente = app(\App\Services\Fiscal\FiscalProviderManager::class)->ambienteDaOficina();
        // NFC-e agora também pode entrar em CONTINGENCIA (offline, via
        // MotorNfce — ver docblock da classe) — não só NF-e (EPEC).
        $motor = $nota->modelo === 'NFC-e'
            ? app(\App\Services\Fiscal\NfePhp\MotorNfce::class)
            : app(\App\Services\Fiscal\NfePhp\MotorNfe::class);
        $resultado = $motor->retransmitir($nota, $ambiente);

        if ($resultado->status === 'AUTORIZADA') {
            $nota->update([
                'status'             => 'AUTORIZADA',
                'chave_acesso'       => $resultado->chave ?: $nota->chave_acesso,
                'protocolo'          => $resultado->protocolo ?: $nota->protocolo,
                'xml_retorno'        => $resultado->xml ?: $nota->xml_retorno,
                'mensagem_erro'      => null,
                'contingencia_desde' => null,
                'emitido_em'         => now(),
            ]);

            if ($ambiente === 'PRODUCAO') {
                $notaFresh = $nota->fresh()->loadMissing(['cliente', 'itens']);
                $this->planLimit->registrarNotaSeExcedente($notaFresh);
                $this->alertas->dispatch('NF_AUTORIZADA', [
                    'nf_numero'         => $notaFresh->numero,
                    'cliente'           => $notaFresh->cliente?->nome ?? '-',
                    'valor'             => 'R$ ' . number_format((float) $notaFresh->valor_total, 2, ',', '.'),
                    'chave_acesso'      => $notaFresh->chave_acesso ?? '-',
                    '_telefone_cliente' => $notaFresh->cliente?->telefone ?? '',
                    '_email_cliente'    => $notaFresh->cliente?->email ?? '',
                ], anexos: app(\App\Services\Fiscal\Pdf\NotaFiscalDocumentoService::class)->montarAnexosEmail($notaFresh));
            }
        } elseif ($resultado->status === 'CANCELADA') {
            $nota->update(['status' => 'CANCELADA', 'contingencia_desde' => null]);
        } else {
            $nota->update(['mensagem_erro' => $resultado->mensagemErro]);
        }

        return response()->json(['data' => new NotaFiscalResource($nota->fresh()->load('cliente'))]);
    }

    public function cancelar(Request $request, string $id): JsonResponse
    {
        $nota = NotaFiscal::findOrFail($id);
        $request->validate(['motivo' => ['required', 'string', 'min:10']]);

        if ($nota->provedor === 'NFEPHP' && in_array($nota->modelo, ['NF-e', 'NFC-e'], true) && $nota->status === 'AUTORIZADA') {
            if (empty($nota->chave_acesso) || empty($nota->protocolo)) {
                return response()->json(['message' => 'Nota sem chave de acesso ou protocolo — não é possível cancelar via NFePHP.'], 422);
            }

            $ambiente = $nota->ambiente ?? 'HOMOLOGACAO';
            $motor    = $nota->modelo === 'NF-e'
                ? app(\App\Services\Fiscal\NfePhp\MotorNfe::class)
                : app(\App\Services\Fiscal\NfePhp\MotorNfce::class);
            $resultado = $motor->cancelar($nota->chave_acesso, $request->motivo, $nota->protocolo, $ambiente);

            if ($resultado->status !== 'CANCELADA') {
                return response()->json(['message' => $resultado->mensagemErro ?? 'Falha ao cancelar nota.'], 422);
            }
        }

        // NFS-e do NFEPHP (ADN/SEFIN Nacional): até 2026-09-20 este caso caía
        // direto no update abaixo e cancelava SÓ no nosso banco — a nota
        // seguia válida no governo. Só marca CANCELADA local se o evento 101101
        // for registrado. Usa `chave_acesso` (o `Id` do infNFSe), não a
        // referência interna `nf-<uuid>`, que o SEFIN não conhece.
        if ($nota->provedor === 'NFEPHP' && $nota->modelo === 'NFS-e' && $nota->status === 'AUTORIZADA') {
            if (empty($nota->chave_acesso)) {
                return response()->json(['message' => 'Nota sem chave de acesso — não é possível cancelar via NFePHP.'], 422);
            }

            $resultado = app(\App\Services\Fiscal\NfePhp\MotorNfse::class)
                ->cancelar($nota->chave_acesso, $request->motivo, $nota->ambiente ?? 'HOMOLOGACAO');

            if ($resultado->status !== 'CANCELADA') {
                return response()->json(['message' => $resultado->mensagemErro ?? 'Falha ao cancelar nota.'], 422);
            }
        }

        // Spedy/Focus (qualquer modelo — NFS-e, NF-e ou NFC-e): cancelamento
        // real via API do provedor. Até esta sessão os dois providers só
        // roteavam certo pra NFS-e; NF-e/NFC-e agora também têm o
        // endpoint/campo confirmados contra a doc de cada um. Só marca
        // CANCELADA local se o provedor confirmar.
        if (in_array($nota->provedor, ['SPEDY', 'FOCUS'], true) && $nota->status === 'AUTORIZADA') {
            $modeloInterno = match ($nota->modelo) {
                'NF-e'  => 'NFE',
                'NFC-e' => 'NFCE',
                default => 'NFSE',
            };
            $ref       = $nota->referencia_externa ?: ('nf-' . $nota->id);
            $resultado = app(\App\Services\Fiscal\FiscalProviderManager::class)
                ->forTenant()
                ->cancelar($ref, $request->motivo, $modeloInterno);

            if ($resultado->status !== 'CANCELADA') {
                return response()->json(['message' => $resultado->mensagemErro ?? 'Falha ao cancelar a nota no provedor.'], 422);
            }
        }

        $nota->update(['status' => 'CANCELADA']);
        return response()->json(['message' => 'NF cancelada com sucesso.']);
    }

    /**
     * Exclui uma nota fiscal — permitido pra notas emitidas em ambiente de
     * HOMOLOGAÇÃO (pedido explícito do usuário, 2026-09-14) OU pra
     * rascunhos nunca emitidos (status RASCUNHO — nunca passaram por
     * IniciarEmissaoNotaService, nunca tocaram SEFAZ, `ambiente` fica
     * `null` pra sempre; achado na revisão final da Task 6 "Pré-visualizar
     * PDF", 2026-09-16 — sem essa exceção, todo clique em "Pré-visualizar"
     * deixava uma nota RASCUNHO órfã e indeletável em /fiscal/historico).
     * Uma nota de PRODUÇÃO já emitida é um documento fiscal real (mesmo
     * cancelada, seu registro precisa ser preservado — cancelamento já
     * existe pra isso); exclusão física só faz sentido pra lixo de
     * teste/homologação ou rascunho que nunca teve valor legal.
     * `notas_fiscais_itens` cai em cascata (FK `onDelete('cascade')`,
     * migration 2026_08_02_000001).
     */
    public function destroy(string $id): JsonResponse
    {
        $nota = NotaFiscal::findOrFail($id);

        if ($nota->ambiente !== 'HOMOLOGACAO' && $nota->status !== 'RASCUNHO') {
            return response()->json([
                'message' => 'Só é possível excluir notas fiscais emitidas em ambiente de homologação, ou rascunhos nunca emitidos.',
            ], 422);
        }

        $nota->delete();

        return response()->json(['message' => 'Nota fiscal excluída com sucesso.']);
    }

    public function pdf(string $id): \Illuminate\Http\Response
    {
        $nota    = NotaFiscal::with(['cliente', 'itens'])->findOrFail($id);
        $arquivo = app(\App\Services\Fiscal\Pdf\NotaFiscalDocumentoService::class)->gerarPdf($nota);

        return response($arquivo['conteudo'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $arquivo['filename'] . '"',
        ]);
    }

    /**
     * Pedido explícito do usuário (2026-09-14): botão de baixar o XML da nota
     * (até aqui só existia baixar o PDF). O XML já ficava salvo em
     * `xml_retorno` desde a autorização — só faltava expor.
     */
    public function xml(string $id): \Illuminate\Http\Response|JsonResponse
    {
        $nota    = NotaFiscal::findOrFail($id);
        $arquivo = app(\App\Services\Fiscal\Pdf\NotaFiscalDocumentoService::class)->xml($nota);

        if ($arquivo === null) {
            return response()->json(['message' => 'XML não disponível para esta nota.'], 404);
        }

        return response($arquivo['conteudo'], 200, [
            'Content-Type'        => 'application/xml',
            'Content-Disposition' => 'attachment; filename="' . $arquivo['filename'] . '"',
        ]);
    }

    public function downloadZip(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'string'],
        ]);

        $notas   = NotaFiscal::with(['cliente', 'itens'])->whereIn('id', $request->ids)->get();
        $empresa = \App\Models\Configuracao::first()?->toArray() ?? [];
        $doc     = app(\App\Services\Fiscal\Pdf\NotaFiscalDocumentoService::class);

        if ($notas->isEmpty()) {
            return response()->json(['message' => 'Nenhuma nota encontrada.'], 404);
        }

        $tmpDir  = storage_path('app/tmp');
        @mkdir($tmpDir, 0755, true);
        $zipPath = $tmpDir . '/nfs_' . uniqid() . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json(['message' => 'Erro ao gerar arquivo ZIP.'], 500);
        }

        foreach ($notas as $nota) {
            $arquivo = $doc->montarPdfArquivo($nota, $empresa);
            $zip->addFromString($arquivo['filename'], $arquivo['pdf']->output());

            // Exigência dos Correios: o XML de cada nota junto com o PDF.
            $xml = $doc->xml($nota);
            if ($xml !== null) {
                $zip->addFromString($xml['filename'], $xml['conteudo']);
            }
        }

        $zip->close();

        return response()->download($zipPath, 'notas_fiscais.zip')->deleteFileAfterSend(true);
    }

    /**
     * Inutiliza uma faixa de numeração de NF-e não usada (queda de processo
     * entre alocar o número e transmitir). Ação administrativa pontual — não
     * cria/atualiza uma NotaFiscal, não faz parte do fluxo normal de
     * emissão. Ver MotorNfe::inutilizar() pro cStat de sucesso e a
     * verificação contra o vendor.
     */
    public function inutilizarNumeracao(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'serie'          => ['required', 'integer', 'min:1'],
            'numero_inicial' => ['required', 'integer', 'min:1'],
            'numero_final'   => ['required', 'integer', 'gte:numero_inicial'],
            'justificativa'  => ['required', 'string', 'min:15'],
        ]);

        $ambiente = \App\Models\Configuracao::first()?->ambiente_fiscal ?? 'HOMOLOGACAO';

        $resultado = app(\App\Services\Fiscal\NfePhp\MotorNfe::class)->inutilizar(
            $validated['serie'], $validated['numero_inicial'], $validated['numero_final'],
            $validated['justificativa'], $ambiente,
        );

        if ($resultado->status !== 'CANCELADA') {
            return response()->json(['message' => $resultado->mensagemErro ?? 'Falha ao inutilizar numeração.'], 422);
        }

        return response()->json(['message' => 'Faixa de numeração inutilizada com sucesso.']);
    }
}
