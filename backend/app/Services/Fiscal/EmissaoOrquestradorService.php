<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\EmissaoBloqueadaException;
use App\Models\OrdemServico;

/**
 * OS mista (peças + serviços) → gera e emite as 2 notas fiscais de uma vez:
 * uma NF-e (ou NFC-e) com as peças e uma NFS-e com os serviços.
 *
 * - Cada nota é criada via CriarNotaFiscalService (mesma validação fiscal
 *   do fluxo manual) e emitida via IniciarEmissaoNotaService (fila).
 * - São INDEPENDENTES: se a NF-e for bloqueada por um produto sem NCM, a
 *   NFS-e ainda sai. O retorno diz o que saiu e o que não.
 * - Peça sem produto vinculado não entra na NF-e (não tem NCM) — vira aviso.
 * - OS só com peças → só NF-e. Só com serviços → só NFS-e.
 */
class EmissaoOrquestradorService
{
    public function __construct(
        private readonly CriarNotaFiscalService $criarNota,
        private readonly IniciarEmissaoNotaService $iniciarEmissao,
    ) {}

    /**
     * @return array{nfe_id: ?string, nfse_id: ?string, avisos: list<string>}
     * @throws EmissaoBloqueadaException  se NENHUMA nota pôde ser gerada
     */
    public function orquestrar(OrdemServico $os): array
    {
        $os->loadMissing('itens');

        $pecas           = $os->itens->filter(fn ($i) => $i->tipo === 'PECA' && $i->produto_id !== null)->values();
        $servicos        = $os->itens->filter(fn ($i) => $i->tipo === 'SERVICO')->values();
        $pecasSemProduto = $os->itens->filter(fn ($i) => $i->tipo === 'PECA' && $i->produto_id === null)->values();

        $avisos = $pecasSemProduto
            ->map(fn ($p) => "Item \"{$p->descricao}\" é uma peça sem produto vinculado — ficou de fora da NF-e (sem NCM).")
            ->all();

        $nfeId  = null;
        $nfseId = null;

        if ($pecas->isNotEmpty()) {
            try {
                $notaNfe = $this->criarNota->criar([
                    'cliente_id'        => $os->cliente_id,
                    'os_id'             => $os->id,
                    'natureza_operacao' => 'Venda de Mercadoria',
                    'forma_pagamento'   => $os->forma_pagamento,
                    'itens'             => $pecas->map(fn ($i) => [
                        'produto_id'     => $i->produto_id,
                        'quantidade'     => (float) $i->quantidade,
                        'valor_unitario' => (float) $i->valor_unitario,
                    ])->all(),
                ]);
                $this->iniciarEmissao->iniciar($notaNfe);
                $nfeId = $notaNfe->id;
            } catch (EmissaoBloqueadaException $e) {
                $avisos[] = 'NF-e (peças) não pôde ser gerada: ' . $e->getMessage();
            }
        }

        if ($servicos->isNotEmpty()) {
            try {
                $notaNfse = $this->criarNota->criar([
                    'cliente_id'        => $os->cliente_id,
                    'os_id'             => $os->id,
                    'natureza_operacao' => 'Prestação de Serviços',
                    'forma_pagamento'   => $os->forma_pagamento,
                    'subtotal'          => (float) $servicos->sum(fn ($i) => (float) $i->quantidade * (float) $i->valor_unitario),
                    'observacoes'       => $servicos->map(fn ($i) => $i->descricao)->join('; '),
                ]);
                $this->iniciarEmissao->iniciar($notaNfse);
                $nfseId = $notaNfse->id;
            } catch (EmissaoBloqueadaException $e) {
                $avisos[] = 'NFS-e (serviços) não pôde ser gerada: ' . $e->getMessage();
            }
        }

        if ($nfeId === null && $nfseId === null) {
            throw new EmissaoBloqueadaException(
                $avisos !== []
                    ? implode(' ', $avisos)
                    : 'A OS não tem peças nem serviços para gerar nota fiscal.',
            );
        }

        return ['nfe_id' => $nfeId, 'nfse_id' => $nfseId, 'avisos' => array_values($avisos)];
    }
}
