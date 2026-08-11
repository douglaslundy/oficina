<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\NotaFiscalResource;
use App\Models\NotaFiscal;
use App\Services\AlertaDispatchService;
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
            'natureza_operacao' => ['required', 'string', 'in:Prestação de Serviços,Venda de Mercadoria'],
            'forma_pagamento'   => ['nullable', 'string', 'max:30'],
            'subtotal'          => ['required_if:natureza_operacao,Prestação de Serviços', 'nullable', 'numeric', 'min:0'],
            'desconto'          => ['nullable', 'numeric', 'min:0'],
            'aliquota_iss'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'observacoes'       => ['nullable', 'string'],
            'itens'                  => ['required_if:natureza_operacao,Venda de Mercadoria', 'array'],
            'itens.*.produto_id'     => ['required_with:itens', 'uuid', 'exists:produtos,id'],
            'itens.*.quantidade'     => ['required_with:itens', 'numeric', 'min:0.01'],
            'itens.*.valor_unitario' => ['required_with:itens', 'numeric', 'min:0'],
        ]);

        $ehVenda = $validated['natureza_operacao'] === 'Venda de Mercadoria';
        $modelo  = $ehVenda ? 'NF-e' : 'NFS-e';

        // Dados fiscais que alimentam CfopSaidaResolver/TributacaoIcmsSaidaResolver
        // precisam estar completos ANTES de abrir a transação — nunca cair num
        // default/fallback silencioso em decisão fiscal (gera CFOP/CST-CSOSN
        // errado num documento fiscal real, sem erro visível em lugar nenhum).
        $configuracao  = null;
        $cliente       = null;
        $produtosPorId = [];

        if ($ehVenda) {
            $configuracao = \App\Models\Configuracao::first();
            if (!$configuracao || empty($configuracao->uf) || empty($configuracao->regime_tributario)) {
                return response()->json(['message' => 'Complete a UF e o regime tributário da empresa em Configurações antes de emitir NF-e.'], 422);
            }

            $cliente = \App\Models\Cliente::find($validated['cliente_id']);
            if (!$cliente || empty($cliente->uf)) {
                return response()->json(['message' => 'Complete a UF do cliente antes de emitir NF-e.'], 422);
            }

            foreach ($validated['itens'] as $item) {
                $produto = \App\Models\Produto::findOrFail($item['produto_id']);

                if ($produto->tributacao_icms === null) {
                    return response()->json(['message' => "Produto \"{$produto->nome}\" está com a tributação de ICMS pendente de revisão. Complete em Produtos › Pendências Fiscais antes de emitir NF-e."], 422);
                }

                // origem === null não pode virar (int) 0 silenciosamente: 0 é um valor
                // fiscal válido e distinto ("mercadoria nacional") — diferente de ncm,
                // que pode ficar incompleto sem bloquear a emissão (decisão deliberada),
                // origem nula precisa bloquear porque defaultar pra 0 afirma um fato
                // fiscal específico que pode ser falso.
                if ($produto->origem === null) {
                    return response()->json(['message' => "Produto \"{$produto->nome}\" está com a origem da mercadoria pendente de revisão. Complete em Produtos › Pendências Fiscais antes de emitir NF-e."], 422);
                }

                $produtosPorId[$produto->id] = $produto;
            }
        }

        $subtotal = $ehVenda
            ? collect($validated['itens'])->sum(fn ($i) => $i['quantidade'] * $i['valor_unitario'])
            : (float) $validated['subtotal'];

        $desconto   = (float) ($validated['desconto'] ?? 0);
        $aliquota   = (float) ($validated['aliquota_iss'] ?? 5.00);
        $valorIss   = $ehVenda ? 0.0 : (($subtotal - $desconto) * $aliquota) / 100;
        $valorTotal = ($subtotal - $desconto) + $valorIss;

        $nota = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $modelo, $subtotal, $desconto, $aliquota, $valorIss, $valorTotal, $ehVenda, $configuracao, $cliente, $produtosPorId) {
            $nota = NotaFiscal::create([
                'cliente_id'        => $validated['cliente_id'],
                'os_id'             => $validated['os_id'] ?? null,
                'natureza_operacao' => $validated['natureza_operacao'],
                'forma_pagamento'   => $validated['forma_pagamento'] ?? null,
                'observacoes'       => $validated['observacoes'] ?? null,
                'modelo'            => $modelo,
                'subtotal'          => $subtotal,
                'desconto'          => $desconto,
                'aliquota_iss'      => $aliquota,
                'valor_iss'         => $valorIss,
                'valor_total'       => $valorTotal,
                'status'            => 'RASCUNHO',
            ]);

            if ($ehVenda) {
                $oficinaUf = $configuracao->uf;
                $regime    = $configuracao->regime_tributario;

                foreach ($validated['itens'] as $item) {
                    $produto    = $produtosPorId[$item['produto_id']];
                    $tributacao = $produto->tributacao_icms;

                    $cfop = \App\Services\Fiscal\CfopSaidaResolver::resolver(
                        $oficinaUf,
                        $cliente->uf,
                        $tributacao === 'ST',
                    );
                    $cstCsosn = \App\Services\Fiscal\TributacaoIcmsSaidaResolver::resolver($regime, $tributacao);

                    \App\Models\NotaFiscalItem::create([
                        'nota_fiscal_id'  => $nota->id,
                        'produto_id'      => $produto->id,
                        'descricao'       => $produto->nome,
                        'ncm'             => $produto->ncm,
                        'cfop'            => $cfop,
                        'origem'          => $produto->origem,
                        'tributacao_icms' => $tributacao,
                        'cst_csosn'       => $cstCsosn,
                        'quantidade'      => $item['quantidade'],
                        'valor_unitario'  => $item['valor_unitario'],
                    ]);
                }
            }

            return $nota;
        });

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

        $provedor = app(\App\Services\Fiscal\FiscalProviderManager::class)->provedorDaOficina(\App\Tenancy\TenancyContext::get() ?? '');
        $ambiente = \App\Models\Configuracao::first()?->ambiente_fiscal ?? 'HOMOLOGACAO';
        $ref      = $nota->referencia_externa ?: ('nf-' . $nota->id);

        $nota->update([
            'status'             => 'PROCESSANDO',
            'numero'             => $this->nfeService->proximoNumeroNf(),
            'provedor'           => $provedor,
            'ambiente'           => $ambiente,
            'referencia_externa' => $ref,
        ]);

        try {
            $resultado = $this->nfeService->emitir($nota);
            $nota->update([
                'status'       => $resultado['status'],
                'chave_acesso' => $resultado['chave'],
                'protocolo'    => $resultado['protocolo'],
                'xml_retorno'  => $resultado['xml_retorno'],
                // Para NF-e o número que importa legalmente é o atribuído pela
                // Focus/SEFAZ na própria série, não o contador interno gravado antes
                // da emissão. Fallback pro valor já existente se o provedor não
                // retornar um número (mantém o comportamento atual pra NFS-e).
                'numero'       => isset($resultado['numero']) ? (int) $resultado['numero'] : $nota->numero,
                'emitido_em'   => $resultado['status'] === 'AUTORIZADA' ? now() : null,
            ]);

            // Billing e alertas só em PRODUCAO e quando AUTORIZADA.
            if ($resultado['status'] === 'AUTORIZADA' && $ambiente === 'PRODUCAO') {
                $notaFresh = $nota->fresh()->loadMissing('cliente');
                $this->planLimit->registrarNotaSeExcedente($notaFresh);
                $this->alertas->dispatch('NF_AUTORIZADA', [
                    'nf_numero'    => $notaFresh->numero,
                    'cliente'      => $notaFresh->cliente?->nome ?? '-',
                    'valor'        => 'R$ ' . number_format((float)$notaFresh->valor_total, 2, ',', '.'),
                    'chave_acesso' => $notaFresh->chave_acesso ?? '-',
                    '_telefone_cliente' => $notaFresh->cliente?->telefone ?? '',
                    '_email_cliente'    => $notaFresh->cliente?->email ?? '',
                ]);
            }

            if ($resultado['status'] === 'REJEITADA') {
                return response()->json(['message' => $resultado['mensagem_erro'] ?? 'Nota rejeitada.'], 422);
            }

            if ($resultado['status'] === 'ERRO') {
                return response()->json(['message' => $resultado['mensagem_erro'] ?? 'Falha técnica ao emitir a nota. Tente novamente ou contate o suporte.'], 500);
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

        $nota->update(['status' => 'CANCELADA']);
        return response()->json(['message' => 'NF cancelada com sucesso.']);
    }

    public function pdf(string $id): \Illuminate\Http\Response
    {
        $nota = NotaFiscal::with('cliente')->findOrFail($id);

        // NF-e emitida via NFePHP: o DANFE é montado localmente a partir do
        // XML já autorizado (DanfeRenderer), diferente da NFS-e abaixo, que
        // busca o PDF pronto direto da API oficial do ambiente nacional —
        // NF-e via NFePHP não tem um endpoint equivalente pra isso, então
        // renderizamos nós mesmos.
        if ($nota->provedor === 'NFEPHP' && in_array($nota->modelo, ['NF-e', 'NFC-e'], true) && in_array($nota->status, ['AUTORIZADA', 'CONTINGENCIA'], true)) {
            $nota->loadMissing(['cliente', 'itens']);
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
