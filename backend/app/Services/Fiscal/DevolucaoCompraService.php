<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\EmissaoBloqueadaException;
use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\NotaEntrada;
use App\Models\NotaEntradaItem;
use App\Models\NotaFiscal;
use App\Models\NotaFiscalItem;
use App\Models\Produto;
use App\Services\EstoqueService;
use App\Services\NotaEntradaXmlParser;
use Illuminate\Support\Facades\DB;

/**
 * Devolução de compra: a oficina devolve ao fornecedor, total ou
 * parcialmente, mercadoria de uma NF-e de entrada já lançada.
 *
 * Fiscal: NF-e de saída (tpNF=1) com finNFe=4 (devolução), NFref/refNFe com
 * a chave da NF-e de compra e CFOP 5.202/6.202 (5.411/6.411 se ST) — ver
 * CfopDevolucaoCompraResolver. A quantidade devolvida nunca pode passar do
 * que a nota de compra trouxe (somando devoluções anteriores — rascunhos,
 * em processamento ou autorizadas; rejeitadas/canceladas liberam a
 * quantidade). O valor unitário é SEMPRE o da nota de compra: a devolução
 * reverte a operação original pelo mesmo preço (é o que sustenta o estorno
 * proporcional do crédito de ICMS); só a quantidade pode ser menor.
 *
 * Estoque: a baixa é uma ação separada (baixarEstoque), com contador próprio
 * por item, porque o usuário pode retirar do estoque sem emitir nota (e
 * vice-versa).
 */
class DevolucaoCompraService
{
    private const STATUS_QUE_RESERVAM = ['RASCUNHO', 'PROCESSANDO', 'AUTORIZADA', 'CONTINGENCIA'];

    public function __construct(
        private readonly EstoqueService $estoque,
        private readonly NotaEntradaXmlParser $parser,
    ) {}

    /**
     * Itens da nota de entrada com o saldo ainda devolvível (fiscal e estoque).
     *
     * @return list<array<string, mixed>>
     */
    public function itensDisponiveis(NotaEntrada $nota): array
    {
        $nota->load('itens');
        $reservadoPorItem = $this->reservadoFiscalPorItem($nota);

        return $nota->itens->map(function (NotaEntradaItem $item) use ($reservadoPorItem) {
            $original         = (float) $item->quantidade;
            $devolvidaNf      = (float) ($reservadoPorItem[$item->id] ?? 0);
            $devolvidaEstoque = (int) $item->qtd_devolvida_estoque;
            $produto          = Produto::find($item->produto_id);

            return [
                'id'                  => $item->id,
                'produto_id'          => $item->produto_id,
                'descricao'           => $produto?->nome ?? $item->descricao_xml,
                'unidade'             => $produto?->unidade ?? $item->unidade_xml,
                'quantidade_original' => $original,
                'valor_unitario'      => (float) $item->valor_unitario,
                'devolvida_nf'        => $devolvidaNf,
                'devolvivel_nf'       => max(0.0, round($original - $devolvidaNf, 2)),
                'devolvida_estoque'   => $devolvidaEstoque,
                'devolvivel_estoque'  => max(0, (int) $original - $devolvidaEstoque),
                'qty_atual'           => $produto?->qty_atual,
            ];
        })->all();
    }

    /** @return array<string, float> nota_entrada_item_id => quantidade já em devolução fiscal */
    private function reservadoFiscalPorItem(NotaEntrada $nota): array
    {
        return NotaFiscalItem::query()
            ->whereIn('nota_entrada_item_id', $nota->itens->pluck('id'))
            ->whereHas('notaFiscal', fn ($q) => $q->whereIn('status', self::STATUS_QUE_RESERVAM))
            ->select('nota_entrada_item_id', DB::raw('SUM(quantidade) as total'))
            ->groupBy('nota_entrada_item_id')
            ->pluck('total', 'nota_entrada_item_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Cria o RASCUNHO da NF-e de devolução. A emissão segue o fluxo normal
     * (POST notas-fiscais/{id}/emitir), idêntico nos 3 motores.
     *
     * @param list<array{item_id: string, quantidade: float|int|string}> $itens
     * @throws EmissaoBloqueadaException
     */
    public function criarRascunho(NotaEntrada $nota, array $itens, ?string $observacoes = null): NotaFiscal
    {
        $chave = (string) preg_replace('/\D/', '', (string) $nota->chave_acesso);
        if (strlen($chave) !== 44) {
            throw new EmissaoBloqueadaException('A nota de entrada não tem chave de acesso (44 dígitos). A NF-e de devolução precisa referenciar a chave da nota de compra.');
        }

        $config = Configuracao::first();
        if (! $config || empty($config->uf) || empty($config->regime_tributario)) {
            throw new EmissaoBloqueadaException('Complete a UF e o regime tributário da empresa em Configurações antes de emitir NF-e.');
        }

        $fornecedor = $this->resolverFornecedor($nota);
        if (empty($fornecedor->uf)) {
            throw new EmissaoBloqueadaException("Complete a UF do fornecedor \"{$fornecedor->nome}\" (cadastro de clientes) antes de emitir a devolução.");
        }

        if ($itens === []) {
            throw new EmissaoBloqueadaException('Selecione ao menos um item para devolver.');
        }

        return DB::transaction(function () use ($nota, $itens, $observacoes, $chave, $config, $fornecedor) {
            // Trava as linhas dos itens: duas devoluções simultâneas não podem somar mais que o original.
            $linhas = NotaEntradaItem::where('nota_entrada_id', $nota->id)->lockForUpdate()->get()->keyBy('id');
            $nota->setRelation('itens', $linhas->values());
            $reservado = $this->reservadoFiscalPorItem($nota);

            $criar    = [];
            $subtotal = 0.0;
            foreach ($itens as $pedido) {
                $item = $linhas->get($pedido['item_id'] ?? '');
                if (! $item) {
                    throw new EmissaoBloqueadaException('Item não pertence a esta nota de entrada.');
                }

                $qtd = round((float) $pedido['quantidade'], 2);
                if ($qtd <= 0) {
                    throw new EmissaoBloqueadaException('A quantidade a devolver deve ser maior que zero.');
                }

                $saldo = round((float) $item->quantidade - (float) ($reservado[$item->id] ?? 0), 2);
                if ($qtd > $saldo) {
                    throw new EmissaoBloqueadaException(sprintf(
                        'Quantidade a devolver de "%s" (%s) é maior que o saldo devolvível da nota de compra (%s).',
                        $item->descricao_xml, $this->fmt($qtd), $this->fmt($saldo),
                    ));
                }

                $produto = Produto::find($item->produto_id);
                if (! $produto) {
                    throw new EmissaoBloqueadaException("Produto do item \"{$item->descricao_xml}\" não existe mais.");
                }
                // origem nula bloqueia — 0 é valor fiscal válido (nacional), nunca "vazio".
                if ($produto->tributacao_icms === null || $produto->origem === null || empty($produto->ncm)) {
                    throw new EmissaoBloqueadaException("Produto \"{$produto->nome}\" está com NCM, origem ou tributação de ICMS pendente. Complete em Produtos › Pendências Fiscais antes de emitir.");
                }
                if ($produto->tributacao_icms === 'ST' && empty($produto->cest)) {
                    throw new EmissaoBloqueadaException("Produto \"{$produto->nome}\" está com ICMS-ST mas sem CEST cadastrado.");
                }

                $criar[]   = ['produto' => $produto, 'item' => $item, 'qtd' => $qtd];
                $subtotal += round($qtd * (float) $item->valor_unitario, 2);
            }
            $subtotal = round($subtotal, 2);

            $nf = NotaFiscal::create([
                'cliente_id'         => $fornecedor->id,
                'natureza_operacao'  => 'Devolução de compra para comercialização',
                'modelo'             => 'NF-e',
                'serie'              => $config->serie_nf ?: '001',
                'subtotal'           => $subtotal,
                'desconto'           => 0,
                'aliquota_iss'       => 0,
                'valor_iss'          => 0,
                'valor_total'        => $subtotal,
                'status'             => 'RASCUNHO',
                'finalidade'         => 'DEVOLUCAO',
                'chave_referenciada' => $chave,
                'nota_entrada_id'    => $nota->id,
                'observacoes'        => $observacoes,
            ]);

            foreach ($criar as $c) {
                /** @var Produto $produto */
                $produto = $c['produto'];
                NotaFiscalItem::create([
                    'nota_fiscal_id'       => $nf->id,
                    'produto_id'           => $produto->id,
                    'nota_entrada_item_id' => $c['item']->id,
                    'sku'                  => $produto->sku,
                    'descricao'            => $produto->nome,
                    'unidade'              => $produto->unidade,
                    'ncm'                  => $produto->ncm,
                    'cfop'                 => CfopDevolucaoCompraResolver::resolver((string) $config->uf, (string) $fornecedor->uf, $produto->tributacao_icms === 'ST'),
                    'origem'               => $produto->origem,
                    'tributacao_icms'      => $produto->tributacao_icms,
                    'cst_csosn'            => TributacaoIcmsSaidaResolver::resolver((string) $config->regime_tributario, $produto->tributacao_icms),
                    'quantidade'           => $c['qtd'],
                    'valor_unitario'       => (float) $c['item']->valor_unitario,
                ]);
            }

            return $nf;
        });
    }

    /**
     * Retira do estoque (sem emitir nota) itens de uma nota de entrada.
     * Não passa do que a nota trouxe nem do que já foi retirado antes.
     *
     * @param list<array{item_id: string, quantidade: int|string}> $itens
     * @return list<array{produto_id: string, quantidade: int}>
     */
    public function baixarEstoque(NotaEntrada $nota, array $itens, string $usuarioId): array
    {
        if ($itens === []) {
            throw new \InvalidArgumentException('Selecione ao menos um item.');
        }

        $referencia = 'NF ' . ($nota->numero_nf ?: ($nota->chave_acesso ?: $nota->id));

        return DB::transaction(function () use ($nota, $itens, $usuarioId, $referencia) {
            $linhas = NotaEntradaItem::where('nota_entrada_id', $nota->id)->lockForUpdate()->get()->keyBy('id');
            $feitos = [];

            foreach ($itens as $pedido) {
                $item = $linhas->get($pedido['item_id'] ?? '');
                if (! $item) {
                    throw new \InvalidArgumentException('Item não pertence a esta nota de entrada.');
                }

                $qtd = (int) $pedido['quantidade'];
                if ($qtd < 1) {
                    throw new \InvalidArgumentException('A quantidade a retirar deve ser de pelo menos 1.');
                }

                // O estoque entrou como (int) da quantidade da nota — mesma base aqui.
                $saldo = (int) $item->quantidade - (int) $item->qtd_devolvida_estoque;
                if ($qtd > $saldo) {
                    throw new \InvalidArgumentException(sprintf(
                        'Quantidade a retirar de "%s" (%d) é maior que o saldo da nota (%d).',
                        $item->descricao_xml, $qtd, max(0, $saldo),
                    ));
                }

                $this->estoque->registrarSaidaDevolucao($item->produto_id, $qtd, $nota->id, $usuarioId, $referencia);
                $item->increment('qtd_devolvida_estoque', $qtd);
                $feitos[] = ['produto_id' => $item->produto_id, 'quantidade' => $qtd];
            }

            return $feitos;
        });
    }

    /** Fornecedor da nota de entrada como Cliente (a emissão inteira lê o destinatário de `clientes`). */
    private function resolverFornecedor(NotaEntrada $nota): Cliente
    {
        $doc = (string) preg_replace('/\D/', '', (string) $nota->fornecedor_cnpj);
        if ($doc === '') {
            throw new EmissaoBloqueadaException('A nota de entrada não tem o CNPJ do fornecedor.');
        }

        $existente = Cliente::where('cpf_cnpj', $doc)->first();
        if ($existente) {
            return $existente;
        }

        $dados = $nota->xml_original ? $this->parser->extrairEmitente((string) $nota->xml_original) : null;
        if ($dados === null || empty($dados['nome']) || empty($dados['uf'])) {
            throw new EmissaoBloqueadaException(
                'Esta nota de entrada não guarda o XML original, então não há endereço do fornecedor. '
                . "Cadastre o fornecedor (CNPJ {$doc}) em Clientes, com UF, endereço e inscrição estadual, e tente de novo."
            );
        }

        return Cliente::create([
            'nome'               => $dados['nome'],
            'cpf_cnpj'           => $dados['cpf_cnpj'],
            'telefone'           => $dados['telefone'],
            'cep'                => $dados['cep'],
            'endereco'           => $dados['logradouro'],
            'bairro'             => $dados['bairro'],
            'cidade'             => $dados['cidade'],
            'uf'                 => $dados['uf'],
            'codigo_ibge'        => $dados['codigo_ibge'],
            'inscricao_estadual' => $dados['inscricao_estadual'],
            'status'             => 'REGULAR',
        ]);
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }
}
