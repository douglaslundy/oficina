<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Configuracao;
use App\Models\NotaEntrada;
use App\Models\NotaTerceiroNotificada;
use App\Models\Oficina;
use App\Services\AlertaDispatchService;
use App\Services\Fiscal\Contracts\ConsultaNotaTerceiroProvider;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo;
use App\Services\Fiscal\FiscalProviderManager;
use App\Tenancy\TenancyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pedido explícito do usuário (2026-09-14): "quando for emitida uma nota
 * para o cnpj da empresa, eu receba um aviso dentro do sistema... como data
 * de pesquisa, seja sempre considerado a data da última nota importada pra
 * frente, de modo a evitar ficar pesquisando sempre as mesmas notas."
 *
 * Filtro por DATA não é tecnicamente possível pra todo provedor: a
 * Distribuição DFe da SEFAZ (NFePHP) só pagina por NSU incremental, nunca
 * por data de emissão (ver MotorNfe::listarNotasRecebidas(), que já
 * persiste esse checkpoint sozinho em `Configuracao::dist_dfe_ultimo_nsu`).
 * Focus também ignora `$desde` (só pagina por "versão" — não implementado
 * aqui por falta de credencial de Focus em produção até agora). Só a Spedy
 * usa `$desde` de verdade (`initialDate`). Por isso o alerta NÃO confia só
 * no filtro de data: toda nota é conferida contra `notas_terceiro_notificadas`
 * (idempotência) e contra `notas_entrada` (já importada) antes de alertar —
 * funciona igual pros 3 provedores, mesmo que só a Spedy reduza a busca de
 * verdade no servidor.
 */
class VerificarNotasTerceirosRecebidas extends Command
{
    protected $signature   = 'nfe:verificar-notas-recebidas';
    protected $description = 'Verifica notas fiscais novas emitidas pro CNPJ da oficina e dispara alerta';

    public function __construct(
        private readonly FiscalProviderManager $providerManager,
        private readonly AlertaDispatchService $alertaDispatch,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $totalNovas = 0;
        $totalFalhas = 0;

        foreach (Oficina::whereIn('status', ['ATIVA', 'TRIAL'])->get() as $oficina) {
            TenancyContext::set($oficina->id, $oficina->slug);

            try {
                $totalNovas += $this->processarOficina();
            } catch (\Throwable $e) {
                $totalFalhas++;
                Log::warning('nfe:verificar-notas-recebidas: falha ao processar oficina.', [
                    'oficina_id' => $oficina->id,
                    'erro'       => $e->getMessage(),
                ]);
            }

            TenancyContext::clear();
        }

        $msg = "Notas de terceiro verificadas: {$totalNovas} nova(s) alertada(s); {$totalFalhas} oficina(s) com falha de consulta.";
        Log::info('nfe:verificar-notas-recebidas — ' . $msg);
        $this->info($msg);

        return self::SUCCESS;
    }

    private function processarOficina(): int
    {
        $cfg = Configuracao::first();
        $cnpj = (string) ($cfg?->cnpj ?? '');
        if ($cfg === null || $cnpj === '') {
            return 0;
        }

        $provider = $this->providerManager->forTenant();
        if (!$provider instanceof ConsultaNotaTerceiroProvider) {
            return 0;
        }

        $desde = $cfg->notas_terceiro_ultima_verificacao
            ? Carbon::parse($cfg->notas_terceiro_ultima_verificacao)
            : null;

        $resumos = $provider->listarNotasRecebidas($cnpj, $desde);

        $jaLancadas    = NotaEntrada::whereNotNull('chave_acesso')->pluck('chave_acesso')->all();
        $jaNotificadas = NotaTerceiroNotificada::pluck('chave_acesso')->all();
        $vistas        = array_flip(array_merge($jaLancadas, $jaNotificadas));

        $novas = 0;
        foreach ($resumos as $resumo) {
            /** @var ConsultaNotaTerceiroResumo $resumo */
            if ($resumo->chaveAcesso === '' || isset($vistas[$resumo->chaveAcesso])) {
                continue;
            }

            // Marca como vista IMEDIATAMENTE (não só no fim do loop): a
            // mesma chave pode aparecer 2x dentro do MESMO lote de
            // resumos — achado ao vivo em produção (2026-09-14) — quando a
            // Distribuição DFe manda um resNFe (resumo) e depois, em outra
            // página de NSU, o procNFe (completo) da mesma nota. Sem isso
            // a 2ª ocorrência violava a unique(oficina_id, chave_acesso).
            $vistas[$resumo->chaveAcesso] = true;

            NotaTerceiroNotificada::create([
                'chave_acesso'    => $resumo->chaveAcesso,
                'fornecedor_nome' => $resumo->fornecedorNome,
                'valor_total'     => $resumo->valorTotal,
                'data_emissao'    => $resumo->dataEmissao,
            ]);

            $this->alertaDispatch->dispatch('NOTA_TERCEIRO_RECEBIDA', [
                'fornecedor'    => $resumo->fornecedorNome ?? 'Fornecedor',
                'valor'         => 'R$ ' . number_format($resumo->valorTotal, 2, ',', '.'),
                'data_emissao'  => $resumo->dataEmissao ?? '',
            ]);

            $novas++;
        }

        $cfg->update(['notas_terceiro_ultima_verificacao' => now()]);

        return $novas;
    }
}
