<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotaEntrada;
use App\Models\Produto;
use App\Services\Fiscal\Contracts\ConsultaNotaTerceiroProvider;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;
use App\Services\Fiscal\FiscalProviderManager;
use App\Services\Fiscal\ProdutoFiscalService;
use App\Tenancy\TenancyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reconsulta uma NotaEntrada já importada no provedor fiscal e atualiza
 * SÓ os campos fiscais dos produtos já vinculados (ProdutoFiscalService::
 * aplicarDoXml() nunca mexe em estoque nem cria produto). Marca a nota
 * como fiscal_conferida_em quando todos os itens ficam completos.
 */
class ConciliarFiscalNotaEntradaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 1;

    public function __construct(
        private readonly string $notaEntradaId,
        private readonly string $oficinaId,
        private readonly string $oficinaSlug,
    ) {}

    public function handle(FiscalProviderManager $providerManager, ProdutoFiscalService $fiscalService): void
    {
        TenancyContext::set($this->oficinaId, $this->oficinaSlug);

        try {
            $nota = NotaEntrada::with('itens')->find($this->notaEntradaId);
            if (!$nota) {
                return;
            }

            if (empty($nota->chave_acesso)) {
                $nota->update([
                    'fiscal_ultima_consulta_em' => now(),
                    'fiscal_erro_consulta'      => 'Nota sem chave de acesso — não é possível conciliar automaticamente.',
                ]);
                return;
            }

            $provider = $providerManager->forTenant();
            if (!$provider instanceof ConsultaNotaTerceiroProvider) {
                $nota->update([
                    'fiscal_ultima_consulta_em' => now(),
                    'fiscal_erro_consulta'      => 'O motor fiscal desta oficina ainda não suporta essa consulta.',
                ]);
                return;
            }

            $resultado = $provider->consultarNotaRecebida($nota->chave_acesso);
            $this->aplicarResultado($nota, $resultado, $fiscalService);
        } finally {
            TenancyContext::clear();
        }
    }

    private function aplicarResultado(NotaEntrada $nota, ConsultaNotaTerceiroResultado $resultado, ProdutoFiscalService $fiscalService): void
    {
        if ($resultado->status !== 'COMPLETA') {
            $nota->update([
                'fiscal_ultima_consulta_em' => now(),
                'fiscal_erro_consulta'      => $resultado->mensagemErro
                    ?? match ($resultado->status) {
                        'AGUARDANDO_MANIFESTACAO' => 'Ciência da operação enviada — tente novamente em instantes.',
                        'NAO_ENCONTRADA'          => 'Nota não encontrada no provedor ainda.',
                        default                    => 'Falha ao consultar a nota.',
                    },
            ]);
            return;
        }

        $itensFrescos = $resultado->dados['itens'] ?? [];
        $todosCompletos = true;

        foreach ($nota->itens as $item) {
            $produto = Produto::find($item->produto_id);
            if (!$produto) {
                continue;
            }

            $itemFresco = $this->casarItem($item, $itensFrescos);
            if ($itemFresco === null) {
                $todosCompletos = $todosCompletos && ($produto->ncm !== null && $produto->tributacao_icms !== null);
                continue;
            }

            $fiscalService->aplicarDoXml($produto, $itemFresco, $nota->id);
            $produto->refresh();

            if ($produto->ncm === null || $produto->tributacao_icms === null) {
                $todosCompletos = false;
            }
        }

        $nota->update([
            'fiscal_ultima_consulta_em' => now(),
            'fiscal_erro_consulta'      => null,
            'fiscal_conferida_em'       => $todosCompletos ? now() : null,
        ]);
    }

    /**
     * Casa um NotaEntradaItem já salvo com o item fresco correspondente na
     * resposta do provedor. Por código de barras primeiro (chave exata);
     * se o item salvo não tinha código de barras, cai pra descrição igual.
     * Sem match seguro, devolve null — o item não é atualizado (não
     * quebra a nota inteira, só fica sem conciliar esse item específico).
     */
    private function casarItem($itemSalvo, array $itensFrescos): ?array
    {
        if ($itemSalvo->codigo_barras_xml !== null) {
            foreach ($itensFrescos as $fresco) {
                if (($fresco['codigo_barras'] ?? null) === $itemSalvo->codigo_barras_xml) {
                    return $fresco;
                }
            }
            return null;
        }

        foreach ($itensFrescos as $fresco) {
            if (($fresco['descricao'] ?? null) === $itemSalvo->descricao_xml) {
                return $fresco;
            }
        }
        return null;
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ConciliarFiscalNotaEntradaJob falhou: ' . $e->getMessage(), ['nota_entrada_id' => $this->notaEntradaId]);
    }
}
