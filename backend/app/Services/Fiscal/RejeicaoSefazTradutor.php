<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * Traduz o `cStat` de uma mensagem de erro/rejeição da SEFAZ (formato
 * "cStat={n}: {xMotivo}", já usado em todo o sistema — ver
 * ProcessaRespostaSefaz::processarRespostaAutorizacao()) pra uma explicação
 * em português claro, com o que fazer.
 *
 * Pedido explícito do usuário (2026-09-14): "veja o retorno de rejeição da
 * SEFAZ... como tornar isso amigável?" — a mensagem técnica continua
 * disponível (nunca escondida, só complementada), esta classe só ADICIONA
 * uma explicação em cima quando reconhece o código.
 *
 * Mapa deliberadamente PEQUENO e extensível — só códigos com significado
 * confirmado contra fonte oficial/múltiplas fontes independentes (nunca
 * "chutado"). Mesma disciplina já aplicada a cTribNac/CFOP/etc. nesta
 * sessão: um código não mapeado aqui simplesmente não ganha explicação
 * extra (a mensagem técnica original continua sendo mostrada sozinha) —
 * cresce organicamente conforme rejeições reais forem encontradas em
 * produção, do mesmo jeito que cStat 217/558/632 foram descobertos e
 * tratados um de cada vez nesta sessão.
 */
class RejeicaoSefazTradutor
{
    private const EXPLICACOES = [
        // Confirmado contra múltiplas fontes independentes (Oobj, Bling,
        // TecnoSpeed, Gálago) — SEFAZ exige GTIN/EAN preenchido desde
        // 12/09/2022, com o literal "SEM GTIN" quando o produto não tem
        // código de barras. Causa raiz já corrigida nesta sessão
        // (MotorNfe/MotorNfce agora sempre mandam esse campo) — esta
        // explicação cobre o caso residual de já ter uma nota rejeitada
        // por isso antes do fix, ou de vir a acontecer via outro provedor.
        '883' => 'Falta o código de barras (GTIN) de um produto desta nota — a SEFAZ exige esse campo desde 2022, mesmo que seja só pra dizer "sem código de barras". Verifique o cadastro do produto: se ele tem código de barras, cadastre-o; se não tem, isso já é resolvido automaticamente pelo sistema.',
        // Confirmado: SEFAZ recusa uma segunda transmissão da mesma chave já
        // autorizada — típico de falha de comunicação (a autorização
        // aconteceu, mas a confirmação não voltou pro sistema).
        '204' => 'Esta nota já foi autorizada pela SEFAZ antes — provavelmente a confirmação se perdeu numa falha de comunicação, mas a nota já existe de verdade. Consulte o status pelo botão de status/reconciliação em vez de tentar emitir de novo.',
        // Confirmado: erro de estrutura do XML (campo faltando, mal
        // formatado). É sempre um problema técnico do sistema, não um dado
        // que o usuário preencheu errado.
        '215' => 'O documento enviado tem um erro de formatação técnica — não é um dado que você preencheu errado, é um problema no sistema. Anote o número desta nota e avise o suporte.',
        '225' => 'O documento enviado tem um erro de formatação técnica — não é um dado que você preencheu errado, é um problema no sistema. Anote o número desta nota e avise o suporte.',
        // Confirmado: retorno genérico de instabilidade momentânea da
        // própria SEFAZ, não um problema com os dados da nota.
        '999' => 'Erro genérico e temporário da SEFAZ — não é um problema com os dados da sua nota. Costuma se resolver sozinho em alguns minutos; tente emitir de novo daqui a pouco.',
        // Confirmados EMPIRICAMENTE nesta própria sessão (não só por
        // documentação externa) — ver PROGRESSO.md, achados da NF-e em
        // contingência.
        '217' => 'A SEFAZ ainda não tem registro desta nota. Se ela foi emitida em contingência recentemente, isso é esperado até a retransmissão ser concluída.',
        '558' => 'Falha técnica na hora de registrar a contingência desta nota (problema já corrigido no sistema para novas emissões). Esta nota específica pode precisar de correção manual — avise o suporte.',
        '632' => 'Esta nota é antiga demais para ser consultada de novo — a SEFAZ só mantém o documento completo disponível por cerca de 90 dias. Isso não significa que a nota é inválida, só que não é mais possível baixar o XML original por essa via.',
    ];

    /**
     * @return string|null a explicação amigável, ou null se a mensagem não
     *   tiver um cStat reconhecido (chamador deve manter a mensagem técnica
     *   original nesse caso, nunca escondê-la).
     */
    public static function traduzir(?string $mensagemErro): ?string
    {
        if (empty($mensagemErro)) {
            return null;
        }

        if (!preg_match('/cStat=(\d+)/', $mensagemErro, $m)) {
            return null;
        }

        return self::EXPLICACOES[$m[1]] ?? null;
    }
}
