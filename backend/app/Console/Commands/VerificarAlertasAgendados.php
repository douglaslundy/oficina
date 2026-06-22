<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Agendamento;
use App\Models\Oficina;
use App\Models\OrdemServico;
use App\Services\AlertaDispatchService;
use App\Tenancy\TenancyContext;
use Illuminate\Console\Command;

class VerificarAlertasAgendados extends Command
{
    protected $signature   = 'alertas:verificar';
    protected $description = 'Verifica e dispara alertas agendados: OS vencidas e lembretes de agendamento';

    public function __construct(private readonly AlertaDispatchService $alertaDispatch) {
        parent::__construct();
    }

    public function handle(): int
    {
        $oficinas = Oficina::whereIn('status', ['ATIVA', 'TRIAL'])->get();

        foreach ($oficinas as $oficina) {
            TenancyContext::set($oficina->id, $oficina->slug);
            $this->verificarOsVencidas($oficina->id);
            $this->verificarLembretesAgendamento($oficina->id);
            TenancyContext::clear();
        }

        $this->info('Alertas verificados para ' . $oficinas->count() . ' oficinas.');
        return self::SUCCESS;
    }

    private function verificarOsVencidas(string $oficinaId): void
    {
        $osVencidas = OrdemServico::withoutGlobalScopes()
            ->with('cliente')
            ->where('oficina_id', $oficinaId)
            ->whereNotIn('status', ['CONCLUIDA', 'CANCELADA'])
            ->whereNotNull('prazo_entrega')
            ->whereDate('prazo_entrega', '<', now()->toDateString())
            ->get();

        foreach ($osVencidas as $os) {
            $this->alertaDispatch->dispatch('OS_VENCIDA', [
                'os_numero'  => $os->numero,
                'cliente'    => $os->cliente?->nome ?? '-',
                'veiculo'    => $os->veiculo_descricao ?? $os->veiculo_placa ?? '-',
                'vencimento' => $os->prazo_entrega?->format('d/m/Y') ?? '-',
                '_telefone_cliente' => $os->cliente?->telefone ?? '',
                '_email_cliente'    => $os->cliente?->email ?? '',
                '_telefone_mecanico' => $os->mecanico?->telefone ?? '',
                '_email_mecanico'    => $os->mecanico?->email ?? '',
            ]);
        }
    }

    private function verificarLembretesAgendamento(string $oficinaId): void
    {
        $amanha = now()->addDay()->toDateString();

        $agendamentos = Agendamento::withoutGlobalScopes()
            ->with('cliente', 'mecanico')
            ->where('oficina_id', $oficinaId)
            ->whereIn('status', ['AGENDADO', 'CONFIRMADO'])
            ->whereDate('data_hora_inicio', $amanha)
            ->get();

        foreach ($agendamentos as $ag) {
            $this->alertaDispatch->dispatch('AGENDAMENTO_LEMBRETE', [
                'cliente'   => $ag->cliente?->nome ?? '-',
                'data'      => now()->addDay()->format('d/m/Y'),
                'hora'      => $ag->data_hora_inicio?->format('H:i') ?? '-',
                'servico'   => $ag->tipo_servico,
                '_telefone_cliente' => $ag->cliente?->telefone ?? '',
                '_telefone_mecanico' => $ag->mecanico?->telefone ?? '',
                '_email_cliente'    => $ag->cliente?->email ?? '',
                '_email_mecanico'   => $ag->mecanico?->email ?? '',
            ]);
        }
    }
}
