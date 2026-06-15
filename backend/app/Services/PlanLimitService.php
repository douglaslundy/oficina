<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Oficina;
use App\Models\OrdemServico;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Validation\ValidationException;

class PlanLimitService
{
    private function oficina(): ?Oficina
    {
        $id = TenancyContext::get();
        if (!$id) return null;

        return Oficina::with('plano')->find($id);
    }

    public function verificarLimiteUsuarios(): void
    {
        $oficina = $this->oficina();
        if (!$oficina || !$oficina->plano) return;

        $limite = $oficina->plano->limite_usuarios;
        if ($limite === -1) return; // ilimitado

        $atual = Usuario::withoutGlobalScopes()
            ->where('oficina_id', $oficina->id)
            ->where('status', 'ATIVO')
            ->count();

        if ($atual >= $limite) {
            throw ValidationException::withMessages([
                'plano' => "Limite de usuários do plano atingido ({$atual}/{$limite}). Faça upgrade para adicionar mais usuários.",
            ]);
        }
    }

    public function verificarLimiteOsMensal(): void
    {
        $oficina = $this->oficina();
        if (!$oficina || !$oficina->plano) return;

        $limite = $oficina->plano->limite_os_mes;
        if ($limite === -1) return; // ilimitado

        $atual = OrdemServico::withoutGlobalScopes()
            ->where('oficina_id', $oficina->id)
            ->whereYear('criado_em', now()->year)
            ->whereMonth('criado_em', now()->month)
            ->count();

        if ($atual >= $limite) {
            throw ValidationException::withMessages([
                'plano' => "Limite de Ordens de Serviço do plano atingido neste mês ({$atual}/{$limite}). Faça upgrade para continuar.",
            ]);
        }
    }

    public function uso(): array
    {
        $oficina = $this->oficina();
        if (!$oficina || !$oficina->plano) {
            return ['plano' => null, 'usuarios' => null, 'os_mes' => null];
        }

        $plano = $oficina->plano;

        $totalUsuarios = Usuario::withoutGlobalScopes()
            ->where('oficina_id', $oficina->id)
            ->where('status', 'ATIVO')
            ->count();

        $totalOsMes = OrdemServico::withoutGlobalScopes()
            ->where('oficina_id', $oficina->id)
            ->whereYear('criado_em', now()->year)
            ->whereMonth('criado_em', now()->month)
            ->count();

        return [
            'plano' => [
                'id'   => $plano->id,
                'nome' => $plano->nome,
            ],
            'usuarios' => [
                'atual'   => $totalUsuarios,
                'limite'  => $plano->limite_usuarios,
                'percent' => $plano->limite_usuarios > 0
                    ? min(100, (int) round($totalUsuarios / $plano->limite_usuarios * 100))
                    : 0,
            ],
            'os_mes' => [
                'atual'   => $totalOsMes,
                'limite'  => $plano->limite_os_mes,
                'percent' => $plano->limite_os_mes > 0
                    ? min(100, (int) round($totalOsMes / $plano->limite_os_mes * 100))
                    : 0,
            ],
        ];
    }
}
