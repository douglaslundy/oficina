<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Configuracao;
use App\Models\NotaEntrada;
use App\Models\NotaTerceiroNotificada;
use App\Services\AlertaDispatchService;
use App\Services\Fiscal\Contracts\ConsultaNotaTerceiroProvider;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo;
use Illuminate\Support\Carbon;

/**
 * Extraído de `VerificarNotasTerceirosRecebidas` (2026-09-14) — precisa ser
 * chamável tanto pelo comando agendado QUANTO pela tela "Notas Recebidas"
 * (botão manual de consultar), depois de um bug real achado ao vivo em
 * produção no mesmo dia do lançamento: o botão manual fazia sua PRÓPRIA
 * consulta ao vivo direto no provedor, mas pro NFePHP isso avança o
 * checkpoint de NSU compartilhado (`Configuracao::dist_dfe_ultimo_nsu`) — se
 * o comando agendado já tinha rodado, a consulta manual não achava mais
 * nada NOVO, escondendo notas já detectadas (e alertadas) mas ainda não
 * importadas. Agora os dois consomem esta MESMA lógica, e `notas_terceiro_
 * notificadas` é a fonte de verdade pra "o que está pendente" — nunca só o
 * resultado de uma consulta ao vivo isolada.
 */
class VerificarNotasTerceiroService
{
    public function __construct(
        private readonly AlertaDispatchService $alertaDispatch,
    ) {}

    /**
     * Consulta o provedor fiscal da oficina ATUAL (via TenancyContext) e
     * espelha o resultado em `notas_terceiro_notificadas` — TODA nota que o
     * provedor reportar é gravada ali (mesmo uma já lançada: a tela
     * "Notas Recebidas" precisa continuar mostrando essa nota com o flag
     * `ja_lancada`, comportamento já existente antes desta feature). O
     * ALERTA, por outro lado, só dispara pra nota genuinamente nova E ainda
     * não lançada — alertar de "nota nova" pra algo que já foi importado por
     * outro caminho não faria sentido pro usuário.
     *
     * @return int quantidade de notas novas E ainda não lançadas (as que geraram alerta)
     * @throws \RuntimeException quando o provedor falha (nunca vira 0 silencioso)
     */
    public function verificarOficinaAtual(ConsultaNotaTerceiroProvider $provider, Configuracao $cfg): int
    {
        $cnpj = (string) ($cfg->cnpj ?? '');
        if ($cnpj === '') {
            return 0;
        }

        $desde = $cfg->notas_terceiro_ultima_verificacao
            ? Carbon::parse($cfg->notas_terceiro_ultima_verificacao)
            : null;

        $resumos = $provider->listarNotasRecebidas($cnpj, $desde);

        $jaLancadas    = array_flip(NotaEntrada::whereNotNull('chave_acesso')->pluck('chave_acesso')->all());
        $jaConhecidas  = array_flip(NotaTerceiroNotificada::pluck('chave_acesso')->all());

        $alertadas = 0;
        foreach ($resumos as $resumo) {
            /** @var ConsultaNotaTerceiroResumo $resumo */
            if ($resumo->chaveAcesso === '' || isset($jaConhecidas[$resumo->chaveAcesso])) {
                continue;
            }

            // Marca como conhecida IMEDIATAMENTE (não só no fim do loop): a
            // mesma chave pode aparecer 2x dentro do MESMO lote de resumos
            // — achado ao vivo em produção (2026-09-14) — quando a
            // Distribuição DFe manda um resNFe (resumo) e depois, em outra
            // página de NSU, o procNFe (completo) da mesma nota.
            $jaConhecidas[$resumo->chaveAcesso] = true;

            NotaTerceiroNotificada::create([
                'chave_acesso'    => $resumo->chaveAcesso,
                'fornecedor_nome' => $resumo->fornecedorNome,
                'fornecedor_cnpj' => $resumo->fornecedorCnpj,
                'valor_total'     => $resumo->valorTotal,
                'data_emissao'    => $resumo->dataEmissao,
                'completa'        => $resumo->completa,
            ]);

            if (isset($jaLancadas[$resumo->chaveAcesso])) {
                continue;
            }

            $this->alertaDispatch->dispatch('NOTA_TERCEIRO_RECEBIDA', [
                'fornecedor'   => $resumo->fornecedorNome ?? 'Fornecedor',
                'valor'        => 'R$ ' . number_format($resumo->valorTotal, 2, ',', '.'),
                'data_emissao' => $resumo->dataEmissao ?? '',
            ]);

            $alertadas++;
        }

        $cfg->update(['notas_terceiro_ultima_verificacao' => now()]);

        return $alertadas;
    }

    /**
     * Lista TODAS as notas já detectadas da oficina ATUAL (lançadas ou não —
     * o campo `ja_lancada` é só um flag pra tela desabilitar o botão
     * "Importar", nunca um filtro), sempre a partir de
     * `notas_terceiro_notificadas`, nunca diretamente do resultado de uma
     * consulta ao vivo isolada (ver docblock da classe). Chamar
     * `verificarOficinaAtual()` antes pra garantir que a tabela está
     * atualizada com o que há de mais recente.
     *
     * @return list<array{chave_acesso: string, fornecedor_nome: ?string, fornecedor_cnpj: ?string, data_emissao: ?string, valor_total: float, completa: bool, ja_lancada: bool}>
     */
    public function listarNotas(): array
    {
        $jaLancadas = NotaEntrada::whereNotNull('chave_acesso')->pluck('chave_acesso')->all();

        return NotaTerceiroNotificada::orderByDesc('criado_em')->get()->map(fn ($n) => [
            'chave_acesso'    => $n->chave_acesso,
            'fornecedor_nome' => $n->fornecedor_nome,
            'fornecedor_cnpj' => $n->fornecedor_cnpj,
            'data_emissao'    => $n->data_emissao?->format('Y-m-d'),
            'valor_total'     => $n->valor_total,
            'completa'        => $n->completa,
            'ja_lancada'      => in_array($n->chave_acesso, $jaLancadas, true),
        ])->all();
    }
}
