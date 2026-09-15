<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Configuracao;
use App\Models\Oficina;
use App\Services\Fiscal\Contracts\ConsultaNotaTerceiroProvider;
use App\Services\Fiscal\FiscalProviderManager;
use App\Services\Fiscal\VerificarNotasTerceiroService;
use App\Tenancy\TenancyContext;
use Illuminate\Console\Command;
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
 *
 * A lógica de verificação/persistência foi extraída pra
 * `VerificarNotasTerceiroService` (2026-09-14) — a tela "Notas Recebidas"
 * (botão manual) usa a MESMA lógica, depois de um bug real achado ao vivo em
 * produção no mesmo dia do lançamento (ver docblock do service).
 */
class VerificarNotasTerceirosRecebidas extends Command
{
    protected $signature   = 'nfe:verificar-notas-recebidas';
    protected $description = 'Verifica notas fiscais novas emitidas pro CNPJ da oficina e dispara alerta';

    public function __construct(
        private readonly FiscalProviderManager $providerManager,
        private readonly VerificarNotasTerceiroService $verificarService,
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
                $cfg = Configuracao::first();
                $provider = $this->providerManager->forTenant();

                if ($cfg !== null && $provider instanceof ConsultaNotaTerceiroProvider) {
                    $totalNovas += $this->verificarService->verificarOficinaAtual($provider, $cfg);
                }
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
}
