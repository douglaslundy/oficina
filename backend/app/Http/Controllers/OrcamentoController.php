<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Oficina;
use App\Models\Orcamento;
use App\Models\OrdemServico;
use App\Models\OsItem;
use App\Services\AlertaDispatchService;
use App\Services\EmailService;
use App\Services\WhatsAppService;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrcamentoController extends Controller
{
    public function __construct(
        private readonly WhatsAppService $whatsApp,
        private readonly EmailService $email,
        private readonly AlertaDispatchService $alertas,
    ) {}

    // ─── Autenticado: enviar orçamento ao cliente ───────────────────────────────

    public function enviar(Request $request, string $os): JsonResponse
    {
        $oficina   = Oficina::with('plano')->find(TenancyContext::get());
        $ent       = app(\App\Services\EntitlementService::class);
        $oficinaId = (string) TenancyContext::get();

        if (!$ent->disponivel($oficinaId, 'ORCAMENTO')) {
            return response()->json([
                'message' => 'Funcionalidade de orçamento não faz parte do seu plano. Contate o administrador do seu plano e contrate.',
            ], 403);
        }
        if (!$ent->permiteEnvio($oficinaId, 'ORCAMENTO')) {
            return response()->json([
                'message' => 'Cota de orçamentos do mês atingida. Aguarde o próximo mês ou contrate mais.',
            ], 422);
        }

        $ordem = OrdemServico::with(['cliente', 'itens'])->findOrFail($os);
        $cliente = $ordem->cliente;

        if (!$cliente) {
            return response()->json(['message' => 'OS sem cliente vinculado.'], 422);
        }

        $temServico = $ordem->itens->firstWhere('tipo', 'SERVICO');
        if (!$temServico) {
            return response()->json(['message' => 'A OS não possui serviços para orçar.'], 422);
        }

        return DB::transaction(function () use ($ordem, $cliente, $oficina) {
            // Reinicia a rodada de aprovação de serviços E peças (ambos são aprováveis).
            OsItem::where('os_id', $ordem->id)->update(['aprovado' => null]);

            $orcamento = Orcamento::updateOrCreate(
                ['os_id' => $ordem->id],
                [
                    'oficina_id'    => $oficina->id,
                    'token'         => Orcamento::gerarToken(),
                    'status'        => 'PENDENTE',
                    'enviado_em'    => now(),
                    'respondido_em' => null,
                ],
            );

            $ordem->update(['status' => 'ORCAMENTO_ENVIADO']);

            // Link aponta para o frontend público (não o backend). URL crua com
            // https em produção → o WhatsApp transforma em link clicável.
            $link = rtrim((string) config('app.frontend_url'), '/') . '/orcamento/' . $orcamento->token;
            $msg  = "Olá {$cliente->nome}! A {$oficina->nome} preparou um orçamento para o seu veículo (OS #{$ordem->numero}).\n\n"
                  . "Veja os serviços e aprove pelo link:\n{$link}";

            $canais = [];

            if ($cliente->telefone && $this->whatsApp->estaAtivo()
                && $this->whatsApp->enviarMensagem($cliente->telefone, $msg, 'ORCAMENTO', 'CLIENTE')) {
                $canais[] = 'WHATSAPP';
            }

            if ($cliente->email && $this->email->configurado()) {
                $r = $this->email->enviar([$cliente->email], "Orçamento · OS #{$ordem->numero}", $msg);
                if ($r['ok']) $canais[] = 'EMAIL';
            }

            $orcamento->update(['canal_envio' => implode(',', $canais) ?: null]);

            return response()->json([
                'message' => empty($canais)
                    ? 'Orçamento gerado, mas não foi possível enviar (verifique WhatsApp/SMTP e os contatos do cliente).'
                    : 'Orçamento enviado ao cliente via ' . implode(' e ', $canais) . '.',
                'link'    => $link,
                'canais'  => $canais,
            ]);
        });
    }

    // ─── Público: visualizar orçamento (resolve tenant pelo token) ──────────────

    public function showPublico(string $token): JsonResponse
    {
        $orcamento = Orcamento::withoutGlobalScopes()->where('token', $token)->first();
        if (!$orcamento) {
            return response()->json(['message' => 'Orçamento não encontrado.'], 404);
        }

        TenancyContext::set($orcamento->oficina_id);
        try {
            $ordem = OrdemServico::with(['cliente', 'itens'])->find($orcamento->os_id);
            if (!$ordem) {
                return response()->json(['message' => 'Orçamento não encontrado.'], 404);
            }

            $oficina = Oficina::find($orcamento->oficina_id);

            $servicos = $ordem->itens->where('tipo', 'SERVICO')->values()->map(fn (OsItem $i) => [
                'id'             => $i->id,
                'descricao'      => $i->descricao,
                'quantidade'     => $i->quantidade,
                'valor_unitario' => $i->valor_unitario,
                'valor_total'    => $i->valor_total,
                'aprovado'       => $i->aprovado,
            ]);

            $pecas = $ordem->itens->where('tipo', 'PECA')->values()->map(fn (OsItem $i) => [
                'id'             => $i->id,
                'descricao'      => $i->descricao,
                'quantidade'     => $i->quantidade,
                'valor_unitario' => $i->valor_unitario,
                'valor_total'    => $i->valor_total,
                'aprovado'       => $i->aprovado,
            ]);

            return response()->json([
                'data' => [
                    'oficina'       => $oficina?->nome,
                    'os_numero'     => $ordem->numero,
                    'cliente'       => $ordem->cliente?->nome,
                    'veiculo'       => $ordem->veiculo_descricao ?? $ordem->veiculo_placa,
                    'problema'      => $ordem->problema_relatado,
                    'status'        => $orcamento->status,
                    'respondido'    => $orcamento->respondido_em !== null,
                    'servicos'      => $servicos,
                    'pecas'         => $pecas,
                ],
            ]);
        } finally {
            TenancyContext::clear();
        }
    }

    // ─── Público: cliente responde (aprova serviços) ────────────────────────────

    public function responder(Request $request, string $token): JsonResponse
    {
        $orcamento = Orcamento::withoutGlobalScopes()->where('token', $token)->first();
        if (!$orcamento) {
            return response()->json(['message' => 'Orçamento não encontrado.'], 404);
        }
        if ($orcamento->respondido_em !== null) {
            return response()->json(['message' => 'Este orçamento já foi respondido.'], 422);
        }

        $validated = $request->validate([
            'servicos_aprovados'   => ['present', 'array'],
            'servicos_aprovados.*' => ['string'],
            'pecas_aprovadas'      => ['sometimes', 'array'],
            'pecas_aprovadas.*'    => ['string'],
        ]);
        // Serviços e peças aprovados pelo cliente.
        $aprovados = array_merge(
            $validated['servicos_aprovados'],
            $validated['pecas_aprovadas'] ?? [],
        );

        TenancyContext::set($orcamento->oficina_id);
        try {
            return DB::transaction(function () use ($orcamento, $aprovados) {
                $ordem = OrdemServico::with(['cliente', 'mecanico', 'itens'])->findOrFail($orcamento->os_id);

                // Serviços e peças são aprováveis; total e status consideram todos os itens.
                $itens         = $ordem->itens;
                $totalItens    = $itens->count();
                $qtdAprovados  = 0;
                $nomesAprovados = [];

                foreach ($itens as $item) {
                    $ok = in_array($item->id, $aprovados, true);
                    OsItem::where('id', $item->id)->update(['aprovado' => $ok]);
                    // Recusa de peça NÃO devolve estoque (oficina ajusta depois).
                    if ($ok) {
                        $qtdAprovados++;
                        if ($item->tipo === 'SERVICO') $nomesAprovados[] = $item->descricao;
                    }
                }

                // valor_total = soma dos itens aprovados (serviços + peças)
                $valorTotal = round((float) $itens
                    ->whereIn('id', $aprovados)
                    ->sum('valor_total'), 2);

                // Status conforme a proporção de itens (serviços + peças) aprovados
                if ($qtdAprovados === 0) {
                    $statusOrc = 'RECUSADO';
                    $statusOs  = 'ORCAMENTO_RECUSADO';
                } elseif ($qtdAprovados === $totalItens) {
                    $statusOrc = 'APROVADO';
                    $statusOs  = 'ORCAMENTO_APROVADO';
                } else {
                    $statusOrc = 'PARCIAL';
                    $statusOs  = 'ORCAMENTO_PARCIAL';
                }

                $ordem->update(['status' => $statusOs, 'valor_total' => $valorTotal]);
                $orcamento->update(['status' => $statusOrc, 'respondido_em' => now()]);

                // Alerta para a oficina
                $tipoAlerta = $qtdAprovados > 0 ? 'ORCAMENTO_APROVADO' : 'ORCAMENTO_RECUSADO';
                $this->alertas->dispatch($tipoAlerta, [
                    'cliente'             => $ordem->cliente?->nome ?? '-',
                    'os_numero'           => $ordem->numero,
                    'valor'               => 'R$ ' . number_format($valorTotal, 2, ',', '.'),
                    'servicos_aprovados'  => $nomesAprovados ? implode(', ', $nomesAprovados) : 'nenhum',
                    '_telefone_cliente'   => $ordem->cliente?->telefone ?? '',
                    '_email_cliente'      => $ordem->cliente?->email ?? '',
                    '_telefone_mecanico'  => $ordem->mecanico?->telefone ?? '',
                    '_email_mecanico'     => $ordem->mecanico?->email ?? '',
                ]);

                return response()->json([
                    'message' => 'Resposta registrada com sucesso!',
                    'status'  => $statusOrc,
                ]);
            });
        } finally {
            TenancyContext::clear();
        }
    }
}
