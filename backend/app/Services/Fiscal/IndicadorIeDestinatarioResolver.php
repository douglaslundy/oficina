<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\EmissaoBloqueadaException;

/**
 * `indIEDest` — indicador da situação da Inscrição Estadual do destinatário
 * numa NF-e (modelo 55). Valores válidos: 1 = Contribuinte ICMS (IE
 * obrigatória), 2 = Contribuinte isento de IE, 9 = Não Contribuinte.
 *
 * Bug real corrigido em 2026-09-23 (NF-e #13, `cStat=232` — "IE do
 * destinatário não informada"): tanto `MotorNfe::montarNfe()` quanto
 * `FocusNfeProvider::montarPayloadNfe()` tratavam TODO destinatário de NF-e
 * como não contribuinte (9) — ou hardcoded, ou por omissão do campo — sem
 * nunca checar se o cliente é uma pessoa jurídica com IE real. A SEFAZ
 * cruza o CNPJ do destinatário contra o cadastro estadual de contribuintes;
 * se detecta que aquele CNPJ é um contribuinte real e a nota diz "não
 * contribuinte" sem IE, rejeita. `Cliente` não tinha nem coluna pra guardar
 * a IE do cliente — o sistema não tinha como acertar isso mesmo se
 * quisesse.
 *
 * Pessoa física (CPF) nunca tem IE — sempre 9, sem exceção, e isso não
 * precisa de cadastro prévio. Pessoa jurídica (CNPJ) precisa de uma decisão
 * explícita: IE real cadastrada (1), ou marcada como isenta (2). Faltando
 * as duas coisas, bloqueia — "assumir não contribuinte" é exatamente a
 * afirmação fiscal específica e possivelmente falsa que já causou a
 * rejeição real, não um default seguro.
 */
final class IndicadorIeDestinatarioResolver
{
    /**
     * @return array{indicador: int, inscricao_estadual: ?string}
     * @throws EmissaoBloqueadaException quando o cliente é PJ e não tem IE
     *         cadastrada nem está marcado como isento — dado insuficiente
     *         pra decidir, não adivinha.
     */
    public static function resolver(
        string $cpfCnpj,
        ?string $inscricaoEstadual,
        bool $ieIsento,
        string $nomeCliente,
    ): array {
        $documento = preg_replace('/\D/', '', $cpfCnpj) ?? '';

        if (strlen($documento) <= 11) {
            // Pessoa física — CPF não tem Inscrição Estadual, ponto final.
            return ['indicador' => 9, 'inscricao_estadual' => null];
        }

        $ieLimpa = $inscricaoEstadual !== null ? preg_replace('/\D/', '', $inscricaoEstadual) : null;

        if ($ieLimpa !== null && $ieLimpa !== '') {
            return ['indicador' => 1, 'inscricao_estadual' => $ieLimpa];
        }

        if ($ieIsento) {
            return ['indicador' => 2, 'inscricao_estadual' => null];
        }

        throw new EmissaoBloqueadaException(
            "Cliente \"{$nomeCliente}\" é pessoa jurídica sem Inscrição Estadual "
            . 'cadastrada. Complete a IE no cadastro do cliente, ou marque-o como '
            . '"isento de Inscrição Estadual" se for o caso, antes de emitir a NF-e.'
        );
    }
}
