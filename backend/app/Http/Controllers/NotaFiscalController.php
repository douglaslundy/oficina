<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\NotaFiscalResource;
use App\Models\NotaFiscal;
use App\Services\AlertaDispatchService;
use App\Services\Fiscal\AplicarResultadoNotaService;
use App\Services\NfeService;
use App\Services\PlanLimitService;
use Barryvdh\DomPDF\Facade\Pdf;
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

    public function cancelar(Request $request, string $id): JsonResponse
    {
        $nota = NotaFiscal::findOrFail($id);
        $request->validate(['motivo' => ['required', 'string', 'min:10']]);

        if ($nota->provedor === 'NFEPHP' && $nota->modelo === 'NF-e' && $nota->status === 'AUTORIZADA') {
            if (empty($nota->chave_acesso) || empty($nota->protocolo)) {
                return response()->json(['message' => 'NF-e sem chave de acesso ou protocolo — não é possível cancelar via NFePHP.'], 422);
            }

            $ambiente  = $nota->ambiente ?? 'HOMOLOGACAO';
            $resultado = app(\App\Services\Fiscal\NfePhp\MotorNfe::class)
                ->cancelar($nota->chave_acesso, $request->motivo, $nota->protocolo, $ambiente);

            if ($resultado->status !== 'CANCELADA') {
                return response()->json(['message' => $resultado->mensagemErro ?? 'Falha ao cancelar NF-e.'], 422);
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

    public function pdf(string $id): \Illuminate\Http\Response
    {
        $nota = NotaFiscal::with(['cliente', 'itens'])->findOrFail($id);

        // NF-e emitida via NFePHP: o DANFE é montado localmente a partir do
        // XML já autorizado (DanfeRenderer), diferente da NFS-e abaixo, que
        // busca o PDF pronto direto da API oficial do ambiente nacional —
        // NF-e via NFePHP não tem um endpoint equivalente pra isso, então
        // renderizamos nós mesmos.
        if ($nota->provedor === 'NFEPHP' && in_array($nota->modelo, ['NF-e', 'NFC-e'], true) && in_array($nota->status, ['AUTORIZADA', 'CONTINGENCIA'], true)) {
            $dados = app(\App\Services\Fiscal\Pdf\DanfeRenderer::class)->dadosParaTemplate($nota);
            $pdf = Pdf::loadView('pdf.danfe', $dados)->setPaper('a4', 'portrait');

            return $pdf->download('DANFE-' . ($nota->numero ?? $nota->id) . '.pdf');
        }

        // NFS-e emitida via NFePHP: o PDF (DANFSe) é obtido pronto direto da
        // API oficial do ambiente nacional (Motor::baixarDanfse()), em vez de
        // renderizar o template local pdf.nota_fiscal — que só reflete os
        // dados salvos localmente, não o layout oficial assinado. Guard
        // inclui 'modelo' === 'NFS-e' porque o branch de NF-e acima já
        // intercepta antes o caso NF-e/NFC-e via NFePHP, então este só
        // é alcançado para NFS-e — mas o check aqui permanece
        // defensivo/explícito em vez de depender só da ordem dos branches.
        if ($nota->provedor === 'NFEPHP' && $nota->modelo === 'NFS-e' && $nota->status === 'AUTORIZADA' && $nota->chave_acesso) {
            try {
                $pdfBinario = app(\App\Services\Fiscal\NfePhp\MotorNfse::class)
                    ->baixarDanfse($nota->chave_acesso, $nota->ambiente ?? 'HOMOLOGACAO');

                return response($pdfBinario, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="NFSe-' . ($nota->numero ?? $nota->id) . '.pdf"',
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Falha ao baixar DANFSe da biblioteca NFePHP, caindo para erro explícito.', ['erro' => $e->getMessage(), 'nota_id' => $nota->id]);
                abort(502, 'Não foi possível obter o PDF da NFS-e no momento. Tente novamente em instantes.');
            }
        }

        $empresa = \App\Models\Configuracao::first()?->toArray() ?? [];

        $arquivo = $this->montarPdfArquivo($nota, $empresa);

        return $arquivo['pdf']->download($arquivo['filename']);
    }

    /**
     * Escolhe o template certo (cupom 80mm pra NFC-e, A4 pra NF-e/NFS-e) e monta o
     * PDF — compartilhado entre pdf() e downloadZip() (achado da revisão final de
     * branch: downloadZip() usava sempre o template A4, mesmo pra NFC-e).
     *
     * @return array{pdf: \Barryvdh\DomPDF\PDF, filename: string}
     */
    private function montarPdfArquivo(NotaFiscal $nota, array $empresa): array
    {
        if ($nota->modelo === 'NFC-e') {
            $qrCodeDataUri = $this->gerarQrCodeDataUri($nota);
            $pdf = Pdf::loadView('pdf.nota_fiscal_nfce', compact('nota', 'empresa', 'qrCodeDataUri'))
                ->setPaper([0, 0, 226.77, $this->alturaCupomNfce($nota)], 'portrait');

            return ['pdf' => $pdf, 'filename' => 'NFCe-' . ($nota->numero ?? $nota->id) . '.pdf'];
        }

        $pdf = Pdf::loadView('pdf.nota_fiscal', compact('nota', 'empresa'))
            ->setPaper('a4', 'portrait');

        return ['pdf' => $pdf, 'filename' => 'NF-' . ($nota->numero ?? $nota->id) . '.pdf'];
    }

    // ~260pt de cabeçalho/rodapé/totais fixos + ~14pt por item + ~110pt pro QR code
    // quando presente. Altura dinâmica porque o cupom térmico não tem página de
    // tamanho fixo como o A4.
    private function alturaCupomNfce(NotaFiscal $nota): float
    {
        return 260.0 + ($nota->itens->count() * 14) + ($nota->qrcode_url ? 110.0 : 0.0);
    }

    private function gerarQrCodeDataUri(NotaFiscal $nota): ?string
    {
        if (empty($nota->qrcode_url)) {
            return null;
        }

        // endroid/qr-code 6.x: a API antiga fluente (Builder::create()->writer()->
        // data()->size()->margin()->build()) foi substituída por argumentos
        // nomeados no construtor — Builder é uma classe readonly sem factory
        // estática nem setters encadeáveis nesta major version.
        $qrCode = (new \Endroid\QrCode\Builder\Builder(
            writer: new \Endroid\QrCode\Writer\PngWriter(),
            data: $nota->qrcode_url,
            size: 200,
            margin: 0,
        ))->build();

        return $qrCode->getDataUri();
    }

    public function downloadZip(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'string'],
        ]);

        $notas   = NotaFiscal::with(['cliente', 'itens'])->whereIn('id', $request->ids)->get();
        $empresa = \App\Models\Configuracao::first()?->toArray() ?? [];

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
            $arquivo = $this->montarPdfArquivo($nota, $empresa);
            $zip->addFromString($arquivo['filename'], $arquivo['pdf']->output());
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
