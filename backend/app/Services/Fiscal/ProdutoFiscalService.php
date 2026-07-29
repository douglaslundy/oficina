<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\CategoriaPadraoFiscal;
use App\Models\Produto;
use App\Models\ProdutoFiscalDivergencia;
use App\Tenancy\TenancyContext;

class ProdutoFiscalService
{
    /** Campos do produto que a importação de XML pode preencher. CFOP não entra: é da operação. */
    public const CAMPOS = ['ncm', 'cest', 'origem', 'tributacao_icms'];

    /** Mapeia campo do produto → chave correspondente no item do parser. */
    private const ORIGEM_XML = [
        'ncm'             => 'ncm',
        'cest'            => 'cest',
        'origem'          => 'origem',
        'tributacao_icms' => 'tributacao_icms',
    ];

    /**
     * Aplica os dados fiscais do XML ao produto.
     *
     * Nunca lança: a importação existe para dar entrada em estoque e não
     * pode ser derrubada por dado fiscal ausente ou estranho.
     *
     * @param array<string, mixed> $fiscalXml item devolvido por NotaEntradaXmlParser
     */
    public function aplicarDoXml(Produto $produto, array $fiscalXml, ?string $notaEntradaId): void
    {
        $paraGravar = [];

        foreach (self::CAMPOS as $campo) {
            $doXml = $this->sanitizar($campo, $fiscalXml[self::ORIGEM_XML[$campo]] ?? null);
            $atual = $produto->{$campo};

            switch (PoliticaConflitoFiscal::decidir($atual, $doXml)) {
                case PoliticaConflitoFiscal::PREENCHER:
                    $paraGravar[$campo] = $doXml;
                    break;

                case PoliticaConflitoFiscal::DIVERGENCIA:
                    $this->registrarDivergencia($produto, $campo, $atual, $doXml, $notaEntradaId);
                    break;

                case PoliticaConflitoFiscal::NADA:
                    break;
            }
        }

        if ($paraGravar !== []) {
            $paraGravar['fiscal_fonte'] = 'XML';
            $produto->update($paraGravar);
        }
    }

    /**
     * Preenche os campos ainda vazios do produto com o padrão da categoria.
     * Marca a fonte como PADRAO — é um chute assistido, não um dado
     * conferido, e a tela de pendências precisa saber a diferença.
     */
    public function aplicarPadraoCategoria(Produto $produto): void
    {
        $padrao = CategoriaPadraoFiscal::where('categoria', $produto->categoria)->first();
        if (!$padrao) {
            return;
        }

        $paraGravar = [];
        foreach (['ncm', 'origem', 'tributacao_icms'] as $campo) {
            if ($produto->{$campo} === null && $padrao->{$campo} !== null) {
                $paraGravar[$campo] = $padrao->{$campo};
            }
        }

        if ($paraGravar !== []) {
            $paraGravar['fiscal_fonte'] = 'PADRAO';
            $produto->update($paraGravar);
        }
    }

    /**
     * Sanitiza um conjunto de valores fiscais brutos (ex.: item de XML de
     * fornecedor reenviado — possivelmente editado — pelo frontend) antes
     * de gravar num produto. Garante a mesma invariante de aplicarDoXml
     * para escritas que não passam por ali, como a criação direta de
     * produto novo em EntradaNfController::store().
     *
     * @param array<string, mixed> $dados chaves entre self::CAMPOS
     * @return array<string, mixed> mesmas chaves, valor normalizado ou null
     */
    public function sanitizarCampos(array $dados): array
    {
        $sanitizado = [];
        foreach (self::CAMPOS as $campo) {
            $sanitizado[$campo] = $this->sanitizar($campo, $dados[$campo] ?? null);
        }
        return $sanitizado;
    }

    /**
     * Nunca grava valor malformado: devolve o valor normalizado ou null.
     * Um campo vazio aparece na tela de pendências; lixo passaria por
     * preenchido e nunca mais seria revisado.
     */
    private function sanitizar(string $campo, mixed $valor): mixed
    {
        return match ($campo) {
            'ncm'    => ValidadorCamposFiscais::ncm($this->paraString($valor)),
            'cest'   => ValidadorCamposFiscais::cest($this->paraString($valor)),
            'origem' => ValidadorCamposFiscais::origem($valor),
            // tributacao_icms não tem normalizador em ValidadorCamposFiscais
            // (não é um código numérico) — mantém a mesma ideia: valor fora
            // do domínio conhecido vira null, nunca lixo gravado.
            'tributacao_icms' => in_array($valor, ['NORMAL', 'ST'], true) ? $valor : null,
            default => null,
        };
    }

    private function paraString(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        return is_string($valor) ? $valor : (string) $valor;
    }

    private function registrarDivergencia(
        Produto $produto,
        string $campo,
        mixed $atual,
        mixed $doXml,
        ?string $notaEntradaId,
    ): void {
        $jaAberta = ProdutoFiscalDivergencia::where('produto_id', $produto->id)
            ->where('campo', $campo)
            ->whereNull('resolvido_em')
            ->where('valor_xml', (string) $doXml)
            ->exists();

        if ($jaAberta) {
            return;
        }

        ProdutoFiscalDivergencia::create([
            'oficina_id'      => TenancyContext::get(),
            'produto_id'      => $produto->id,
            'nota_entrada_id' => $notaEntradaId,
            'campo'           => $campo,
            'valor_atual'     => $atual === null ? null : (string) $atual,
            'valor_xml'       => (string) $doXml,
        ]);
    }
}
