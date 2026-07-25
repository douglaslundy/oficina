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
            $doXml = $fiscalXml[self::ORIGEM_XML[$campo]] ?? null;
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
