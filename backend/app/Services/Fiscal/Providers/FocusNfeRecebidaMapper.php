<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Providers;

use App\Services\Fiscal\ClassificacaoIcms;
use App\Services\Fiscal\ValidadorCamposFiscais;

/**
 * Traduz o JSON de "NFe recebida completa" da Focus
 * (GET /v2/nfes_recebidas/{chave}.json?completa=1) pro mesmo array shape
 * que NotaEntradaXmlParser::parse() produz a partir de XML — é o contrato
 * comum que EntradaNfController já sabe consumir, seja qual for a origem.
 *
 * A Focus não expõe o campo de origem da mercadoria (0-8) nesse JSON — fica
 * sempre null aqui, diferente do caminho via XML. Não bloqueia o
 * lançamento sozinho (fiscal_pendente só olha NCM e tributacao_icms).
 */
final class FocusNfeRecebidaMapper
{
    /**
     * @return array{chave_acesso: ?string, numero_nf: ?string, serie: ?string,
     *   data_emissao: ?string, fornecedor_nome: ?string, fornecedor_cnpj: ?string,
     *   valor_total: float, itens: list<array<string, mixed>>}
     */
    public static function paraArray(array $json): array
    {
        $chave = (string) ($json['chave_nfe'] ?? '');

        $itensJson = $json['requisicao_nota_fiscal']['itens'] ?? [];
        $itens = array_map(function (array $item): array {
            $cstCsosnBruto = (string) ($item['icms_situacao_tributaria'] ?? '');
            $digitos       = preg_replace('/\D/', '', $cstCsosnBruto) ?? '';
            $ehCsosn       = strlen($digitos) === 3;

            $ean = (string) ($item['codigo_barras_comercial'] ?? '');

            return [
                'codigo_barras'   => $ean !== '' ? $ean : null,
                'descricao'       => (string) ($item['descricao'] ?? ''),
                'quantidade'      => (float) ($item['quantidade_comercial'] ?? 0),
                'valor_unitario'  => (float) ($item['valor_unitario_comercial'] ?? 0),
                'ncm'             => ValidadorCamposFiscais::ncm($item['codigo_ncm'] ?? null),
                'cfop'            => ValidadorCamposFiscais::cfop($item['cfop'] ?? null),
                'cest'            => null,
                'unidade'         => ((string) ($item['unidade_comercial'] ?? '')) ?: null,
                'origem'          => ValidadorCamposFiscais::origem(null),
                'cst_csosn'       => $digitos !== '' ? $digitos : null,
                'tributacao_icms' => $ehCsosn
                    ? ClassificacaoIcms::derivar(null, $digitos)
                    : ClassificacaoIcms::derivar($digitos, null),
            ];
        }, $itensJson);

        return [
            'chave_acesso'    => $chave ?: null,
            'numero_nf'       => self::numeroDaChave($chave),
            'serie'           => self::serieDaChave($chave),
            'data_emissao'    => isset($json['data_emissao']) ? substr((string) $json['data_emissao'], 0, 10) : null,
            'fornecedor_nome' => ((string) ($json['nome_emitente'] ?? '')) ?: null,
            'fornecedor_cnpj' => ((string) ($json['documento_emitente'] ?? '')) ?: null,
            'valor_total'     => (float) ($json['valor_total'] ?? 0),
            'itens'           => $itens,
        ];
    }

    // A chave de acesso codifica série (posições 23-25) e número (26-34) —
    // formato fixo do Manual de Orientação do Contribuinte da NF-e, vale
    // pra qualquer provedor, não só a Focus.
    private static function serieDaChave(string $chave): ?string
    {
        if (strlen($chave) !== 44) return null;
        return ltrim(substr($chave, 22, 3), '0') ?: '0';
    }

    private static function numeroDaChave(string $chave): ?string
    {
        if (strlen($chave) !== 44) return null;
        return ltrim(substr($chave, 25, 9), '0') ?: '0';
    }
}
