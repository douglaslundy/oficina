<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AlertaLog;
use App\Models\Oficina;
use App\Models\OficinaServico;
use App\Models\Orcamento;

class EntitlementService
{
    public const SERVICOS = ['ALERTA_WHATSAPP', 'ALERTA_EMAIL', 'ORCAMENTO'];

    /** O serviço está disponível p/ a oficina (incluso no plano OU grant avulso ativo)? */
    public function disponivel(string $oficinaId, string $servico): bool
    {
        return $this->planoCobre($oficinaId, $servico) || $this->grantAtivo($oficinaId, $servico) !== null;
    }

    /** Pode enviar agora? (disponível E, se via grant, cota do mês não excedida) */
    public function permiteEnvio(string $oficinaId, string $servico): bool
    {
        if ($this->planoCobre($oficinaId, $servico)) {
            return true; // incluso no plano = ilimitado
        }
        $grant = $this->grantAtivo($oficinaId, $servico);
        if (!$grant) return false;
        if ($grant->quantidade < 0) return true; // ilimitado
        return $this->usoMes($oficinaId, $servico) < $grant->quantidade;
    }

    /** Canais de alerta disponíveis (WHATSAPP/EMAIL) por plano ou grant. */
    public function canaisDisponiveis(string $oficinaId): array
    {
        $canais = [];
        if ($this->disponivel($oficinaId, 'ALERTA_WHATSAPP')) $canais[] = 'WHATSAPP';
        if ($this->disponivel($oficinaId, 'ALERTA_EMAIL'))    $canais[] = 'EMAIL';
        return $canais;
    }

    public function planoCobre(string $oficinaId, string $servico): bool
    {
        $plano = Oficina::with('plano')->find($oficinaId)?->plano;
        if (!$plano) return false;

        return match ($servico) {
            'ALERTA_WHATSAPP' => (bool) $plano->alerta_whatsapp,
            'ALERTA_EMAIL'    => (bool) $plano->alerta_email,
            'ORCAMENTO'       => (bool) $plano->orcamento,
            default           => false,
        };
    }

    /** Soma do valor adicional mensal dos grants avulsos ativos da oficina. */
    public function valorAdicionalMensal(string $oficinaId): float
    {
        $hoje = now()->toDateString();

        return (float) OficinaServico::where('oficina_id', $oficinaId)
            ->where('ativo', true)
            ->where('data_inicio', '<=', $hoje)
            ->where(fn ($q) => $q->where('recorrente', true)
                ->orWhereNull('data_fim')
                ->orWhere('data_fim', '>=', $hoje))
            ->sum('valor_adicional');
    }

    public function grantAtivo(string $oficinaId, string $servico): ?OficinaServico
    {
        $hoje = now()->toDateString();

        return OficinaServico::where('oficina_id', $oficinaId)
            ->where('servico', $servico)
            ->where('ativo', true)
            ->where('data_inicio', '<=', $hoje)
            ->where(fn ($q) => $q->where('recorrente', true)
                ->orWhereNull('data_fim')
                ->orWhere('data_fim', '>=', $hoje))
            ->orderByDesc('quantidade') // prefere o de maior cota (ilimitado = -1 fica por último)
            ->first();
    }

    private function usoMes(string $oficinaId, string $servico): int
    {
        $ano = now()->year;
        $mes = now()->month;

        if ($servico === 'ALERTA_WHATSAPP' || $servico === 'ALERTA_EMAIL') {
            $canal = $servico === 'ALERTA_WHATSAPP' ? 'WHATSAPP' : 'EMAIL';
            return AlertaLog::withoutGlobalScopes()
                ->where('oficina_id', $oficinaId)
                ->where('canal', $canal)
                ->where('sucesso', true)
                ->whereYear('enviado_em', $ano)
                ->whereMonth('enviado_em', $mes)
                ->count();
        }

        if ($servico === 'ORCAMENTO') {
            return Orcamento::withoutGlobalScopes()
                ->where('oficina_id', $oficinaId)
                ->whereYear('enviado_em', $ano)
                ->whereMonth('enviado_em', $mes)
                ->count();
        }

        return 0;
    }
}
