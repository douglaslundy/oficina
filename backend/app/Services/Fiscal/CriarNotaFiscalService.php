<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\EmissaoBloqueadaException;
use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Models\NotaFiscalItem;
use App\Models\Produto;
use Illuminate\Support\Facades\DB;

/**
 * Cria uma NotaFiscal (RASCUNHO) a partir de dados já validados — com toda
 * a checagem fiscal (UF da empresa/cliente, regime, NCM/origem/tributação
 * de ICMS por item), a seleção automática NFC-e vs NF-e, a resolução de
 * CFOP/CST-CSOSN e a numeração de série.
 *
 * Extraído de NotaFiscalController::store() (2026-09-05) pra ser
 * reaproveitado pelo EmissaoOrquestradorService (OS mista → 2 notas).
 * Lógica idêntica à do controller; a única diferença é que os bloqueios
 * fiscais viram EmissaoBloqueadaException em vez de `response()->json(422)`.
 */
class CriarNotaFiscalService
{
    /**
     * @param array{
     *   cliente_id: string,
     *   os_id?: ?string,
     *   natureza_operacao: string,      // 'Prestação de Serviços' | 'Venda de Mercadoria'
     *   forma_pagamento?: ?string,
     *   subtotal?: ?float,              // obrigatório pra 'Prestação de Serviços'
     *   desconto?: ?float,
     *   aliquota_iss?: ?float,
     *   observacoes?: ?string,
     *   forcar_nfe?: ?bool,
     *   itens?: list<array{produto_id: string, quantidade: float|int, valor_unitario: float|int}>,
     * } $dados
     *
     * @throws EmissaoBloqueadaException
     */
    public function criar(array $dados): NotaFiscal
    {
        $ehVenda = $dados['natureza_operacao'] === 'Venda de Mercadoria';
        $modelo  = 'NFS-e';

        // Lido sempre (não só quando $ehVenda) — bug real reportado pelo
        // usuário (2026-09-17): o fallback de aliquota_iss abaixo usava um
        // valor hardcoded em vez de ler a Configuracao da oficina, então
        // qualquer chamador que não mandasse aliquota_iss explicitamente
        // (o EmissaoOrquestradorService nunca mandava) ignorava
        // silenciosamente a alíquota configurada em Empresa.
        $configuracao  = Configuracao::first();
        $cliente       = null;
        $produtosPorId = [];

        if ($ehVenda) {
            if (! $configuracao || empty($configuracao->uf) || empty($configuracao->regime_tributario)) {
                throw new EmissaoBloqueadaException('Complete a UF e o regime tributário da empresa em Configurações antes de emitir NF-e.');
            }

            $cliente = Cliente::find($dados['cliente_id']);
            if (! $cliente || empty($cliente->uf)) {
                throw new EmissaoBloqueadaException('Complete a UF do cliente antes de emitir NF-e.');
            }

            // Pedido explícito do usuário (2026-09-14): switch em
            // Configuracao.modelo_venda_padrao decide NF-e/NFC-e pra venda
            // de produtos, com NF-e como padrão. Restrito a cliente PESSOA
            // FÍSICA — pessoa jurídica continua SEMPRE NF-e (regra
            // preexistente, não é o switch: PJ normalmente é contribuinte
            // de ICMS e precisa da nota "cheia" pra aproveitar crédito, o
            // que NFC-e não suporta direito). `mesmoEstado` continua um
            // bloqueio DE VERDADE (não uma preferência): NFC-e é sempre
            // idDest=1, operação interna — SEFAZ rejeita NFC-e
            // interestadual, então fora do estado é sempre NF-e,
            // independente do switch. `forcar_nfe` (override pontual já
            // existente) continua tendo prioridade sobre o switch.
            $cpfCnpjLimpo   = preg_replace('/\D/', '', (string) $cliente->cpf_cnpj);
            $ehPessoaFisica = strlen((string) $cpfCnpjLimpo) === 11;
            $forcarNfe      = (bool) ($dados['forcar_nfe'] ?? false);
            $mesmoEstado    = strtoupper((string) $cliente->uf) === strtoupper((string) $configuracao->uf);
            $modelo         = ($ehPessoaFisica && ! $forcarNfe && $mesmoEstado && $configuracao->modelo_venda_padrao === 'NFC-e')
                ? 'NFC-e'
                : 'NF-e';

            foreach ($dados['itens'] ?? [] as $item) {
                $produto = Produto::findOrFail($item['produto_id']);

                if ($produto->tributacao_icms === null) {
                    throw new EmissaoBloqueadaException("Produto \"{$produto->nome}\" está com a tributação de ICMS pendente de revisão. Complete em Produtos › Pendências Fiscais antes de emitir NF-e.");
                }

                // origem nula bloqueia — 0 é um valor fiscal válido e distinto
                // (mercadoria nacional), defaultar pra 0 afirmaria um fato falso.
                if ($produto->origem === null) {
                    throw new EmissaoBloqueadaException("Produto \"{$produto->nome}\" está com a origem da mercadoria pendente de revisão. Complete em Produtos › Pendências Fiscais antes de emitir NF-e.");
                }

                // Bug real reportado pelo usuário (2026-09-15): SEFAZ rejeitou
                // com cStat=806 "Operação com ICMS-ST sem informação do CEST"
                // — a checagem de tributação/origem acima já existia, mas
                // faltava esta. CEST é obrigatório quando a tributação indica
                // Substituição Tributária (mesma exigência já tratada, do lado
                // do payload, em NfeService::montarNotaData()/SpedyProvider/
                // MotorNfe/MotorNfce — só faltava bloquear ANTES de tentar
                // emitir, em vez de deixar a SEFAZ rejeitar por dado faltante).
                if ($produto->tributacao_icms === 'ST' && empty($produto->cest)) {
                    throw new EmissaoBloqueadaException("Produto \"{$produto->nome}\" está com ICMS-ST mas sem CEST cadastrado. Complete em Produtos › Pendências Fiscais antes de emitir NF-e.");
                }

                $produtosPorId[$produto->id] = $produto;
            }
        }

        // Achado de auditoria (2026-09-14): soma/subtração de float sem
        // round() explícito pode gerar ruído de subcentavo — mesma classe
        // de bug já corrigida em OrdemServicoController::store() (onde
        // causava cliente marcado DEVEDOR por engano). Arredondado aqui
        // também, mesmo que a coluna NUMERIC do Postgres já corrija no
        // INSERT — evita valores inconsistentes em qualquer comparação/
        // validação feita em PHP antes de persistir.
        $subtotal = $ehVenda
            ? round(collect($dados['itens'] ?? [])->sum(fn ($i) => $i['quantidade'] * $i['valor_unitario']), 2)
            : round((float) ($dados['subtotal'] ?? 0), 2);

        $desconto   = round((float) ($dados['desconto'] ?? 0), 2);
        // Ordem: valor explícito no payload > alíquota configurada em
        // Empresa > 5% (teto legal) só se nem isso existir (oficina sem
        // Configuracao cadastrada — não deveria acontecer em produção).
        $aliquota   = (float) ($dados['aliquota_iss'] ?? $configuracao?->aliquota_iss ?? 5.00);
        $valorIss   = $ehVenda ? 0.0 : round((($subtotal - $desconto) * $aliquota) / 100, 2);
        $valorTotal = round(($subtotal - $desconto) + $valorIss, 2);

        $serie = '001';
        if ($ehVenda) {
            $serie = $modelo === 'NFC-e' ? ($configuracao->serie_nfce ?: '001') : ($configuracao->serie_nf ?: '001');
        }

        return DB::transaction(function () use ($dados, $modelo, $serie, $subtotal, $desconto, $aliquota, $valorIss, $valorTotal, $ehVenda, $configuracao, $cliente, $produtosPorId) {
            $nota = NotaFiscal::create([
                'cliente_id'        => $dados['cliente_id'],
                'os_id'             => $dados['os_id'] ?? null,
                'natureza_operacao' => $dados['natureza_operacao'],
                'forma_pagamento'   => $dados['forma_pagamento'] ?? null,
                'observacoes'       => $dados['observacoes'] ?? null,
                'modelo'            => $modelo,
                'serie'             => $serie,
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

                foreach ($dados['itens'] ?? [] as $item) {
                    $produto    = $produtosPorId[$item['produto_id']];
                    $tributacao = $produto->tributacao_icms;

                    $cfop = $modelo === 'NFC-e'
                        ? CfopConsumidorResolver::resolver($oficinaUf, $cliente->uf)
                        : CfopSaidaResolver::resolver($oficinaUf, $cliente->uf, $tributacao === 'ST');
                    $cstCsosn = TributacaoIcmsSaidaResolver::resolver($regime, $tributacao);

                    NotaFiscalItem::create([
                        'nota_fiscal_id'  => $nota->id,
                        'produto_id'      => $produto->id,
                        'sku'             => $produto->sku,
                        'descricao'       => $produto->nome,
                        'unidade'         => $produto->unidade,
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
    }
}
