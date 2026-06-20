<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\NotaFiscalResource;
use App\Models\NotaFiscal;
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

        return NotaFiscalResource::collection($query->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cliente_id'        => ['required', 'string', 'exists:clientes,id'],
            'os_id'             => ['nullable', 'string', 'exists:ordens_servico,id'],
            'natureza_operacao' => ['required', 'string', 'max:50'],
            'forma_pagamento'   => ['nullable', 'string', 'max:30'],
            'subtotal'          => ['required', 'numeric', 'min:0'],
            'desconto'          => ['nullable', 'numeric', 'min:0'],
            'aliquota_iss'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'observacoes'       => ['nullable', 'string'],
        ]);

        $subtotal   = (float)$validated['subtotal'];
        $desconto   = (float)($validated['desconto'] ?? 0);
        $aliquota   = (float)($validated['aliquota_iss'] ?? 5.00);
        $valorIss   = (($subtotal - $desconto) * $aliquota) / 100;
        $valorTotal = ($subtotal - $desconto) + $valorIss;

        $nota = NotaFiscal::create([
            ...$validated,
            'valor_iss'   => $valorIss,
            'valor_total' => $valorTotal,
            'status'      => 'RASCUNHO',
        ]);

        return (new NotaFiscalResource($nota->load('cliente')))->response()->setStatusCode(201);
    }

    public function show(string $id): NotaFiscalResource
    {
        return new NotaFiscalResource(NotaFiscal::with('cliente')->findOrFail($id));
    }

    public function emitir(string $id): JsonResponse
    {
        $nota = NotaFiscal::with('cliente')->findOrFail($id);

        if ($nota->status === 'AUTORIZADA') {
            return response()->json(['message' => 'NF já foi emitida.'], 400);
        }

        $nota->update(['status' => 'PROCESSANDO', 'numero' => $this->nfeService->proximoNumeroNf()]);

        try {
            $resultado = $this->nfeService->emitir($nota);
            $nota->update([
                'status'       => $resultado['status'],
                'chave_acesso' => $resultado['chave'],
                'protocolo'    => $resultado['protocolo'],
                'xml_retorno'  => $resultado['xml_retorno'],
                'emitido_em'   => now(),
            ]);

            if ($resultado['status'] === 'AUTORIZADA') {
                $this->planLimit->registrarNotaSeExcedente($nota->fresh());
            }
        } catch (\Exception $e) {
            $nota->update(['status' => 'REJEITADA']);
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new NotaFiscalResource($nota->fresh()->load('cliente'))]);
    }

    public function cancelar(Request $request, string $id): JsonResponse
    {
        $nota = NotaFiscal::findOrFail($id);
        $request->validate(['motivo' => ['required', 'string', 'min:10']]);
        $nota->update(['status' => 'CANCELADA']);
        return response()->json(['message' => 'NF cancelada com sucesso.']);
    }

    public function pdf(string $id): \Illuminate\Http\Response
    {
        $nota = NotaFiscal::with('cliente')->findOrFail($id);
        $empresa = \App\Models\Configuracao::first()?->toArray() ?? [];

        $pdf = Pdf::loadView('pdf.nota_fiscal', compact('nota', 'empresa'))
            ->setPaper('a4', 'portrait');

        return $pdf->download('NF-' . ($nota->numero ?? $nota->id) . '.pdf');
    }

    public function downloadZip(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'string'],
        ]);

        $notas   = NotaFiscal::with('cliente')->whereIn('id', $request->ids)->get();
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
            $pdf      = Pdf::loadView('pdf.nota_fiscal', compact('nota', 'empresa'))->setPaper('a4', 'portrait');
            $filename = 'NF-' . ($nota->numero ?? $nota->id) . '.pdf';
            $zip->addFromString($filename, $pdf->output());
        }

        $zip->close();

        return response()->download($zipPath, 'notas_fiscais.zip')->deleteFileAfterSend(true);
    }
}
