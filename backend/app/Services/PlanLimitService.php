<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\NotaFiscal;
use App\Models\Oficina;
use App\Models\OrdemServico;
use App\Models\Produto;
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

    public function verificarLimiteProdutos(): void
    {
        $oficina = $this->oficina();
        if (!$oficina || !$oficina->plano) return;

        $limite = $oficina->plano->limite_produtos;
        if ($limite === -1) return; // ilimitado

        $atual = Produto::withoutGlobalScopes()
            ->where('oficina_id', $oficina->id)
            ->count();

        if ($atual >= $limite) {
            throw ValidationException::withMessages([
                'plano' => "Limite de produtos do plano atingido ({$atual}/{$limite}). Faça upgrade para cadastrar mais produtos.",
            ]);
        }
    }

    public function verificarLimiteClientes(): void
    {
        $oficina = $this->oficina();
        if (!$oficina || !$oficina->plano) return;

        $limite = $oficina->plano->limite_clientes;
        if ($limite === -1) return; // ilimitado

        $atual = Cliente::withoutGlobalScopes()
            ->where('oficina_id', $oficina->id)
            ->count();

        if ($atual >= $limite) {
            throw ValidationException::withMessages([
                'plano' => "Limite de clientes do plano atingido ({$atual}/{$limite}). Faça upgrade para cadastrar mais clientes.",
            ]);
        }
    }

    /**
     * Notas fiscais NÃO bloqueiam ao atingir o limite — ao invés disso,
     * cada nota emitida acima do limite mensal gera uma cobrança de
     * excedente para a oficina, conforme o preço configurado no plano.
     * Deve ser chamado após a nota ser autorizada.
     */
    public function registrarNotaSeExcedente(NotaFiscal $nota): void
    {
        $oficina = $this->oficina();
        if (!$oficina || !$oficina->plano) return;

        $limite = $oficina->plano->limite_notas_mes;
        if ($limite === -1) return; // ilimitado, sem cobrança

        $totalMes = $this->totalNotasMes($oficina->id);

        // Esta nota só é excedente se o total do mês já passou do limite.
        if ($totalMes <= $limite) return;

        $preco = (float) $oficina->plano->preco_nota_excedente;
        if ($preco <= 0) return; // plano não cobra por excedente

        Cobranca::create([
            'oficina_id'     => $oficina->id,
            'mes_referencia' => now()->startOfMonth()->toDateString(),
            'valor'          => $preco,
            'status'         => 'PENDENTE',
            'tipo'           => 'NOTA_EXCEDENTE',
            'descricao'      => "Nota fiscal excedente (#{$nota->numero}) — acima do limite de {$limite}/mês do plano {$oficina->plano->nome}",
            'vencimento'     => now()->endOfMonth()->toDateString(),
        ]);
    }

    private function totalNotasMes(string $oficinaId): int
    {
        return NotaFiscal::withoutGlobalScopes()
            ->where('oficina_id', $oficinaId)
            ->where('status', 'AUTORIZADA')
            ->whereYear('emitido_em', now()->year)
            ->whereMonth('emitido_em', now()->month)
            ->count();
    }

    private function itemUso(int $atual, int $limite): array
    {
        return [
            'atual'   => $atual,
            'limite'  => $limite,
            'percent' => $limite > 0 ? min(100, (int) round($atual / $limite * 100)) : 0,
        ];
    }

    public function uso(): array
    {
        $oficina = $this->oficina();
        if (!$oficina || !$oficina->plano) {
            return [
                'plano' => null, 'usuarios' => null, 'os_mes' => null,
                'produtos' => null, 'clientes' => null, 'notas_mes' => null,
            ];
        }

        $plano = $oficina->plano;
        $ent   = app(EntitlementService::class);

        $totalUsuarios = Usuario::withoutGlobalScopes()
            ->where('oficina_id', $oficina->id)
            ->where('status', 'ATIVO')
            ->count();

        $totalOsMes = OrdemServico::withoutGlobalScopes()
            ->where('oficina_id', $oficina->id)
            ->whereYear('criado_em', now()->year)
            ->whereMonth('criado_em', now()->month)
            ->count();

        $totalProdutos = Produto::withoutGlobalScopes()
            ->where('oficina_id', $oficina->id)->count();

        $totalClientes = Cliente::withoutGlobalScopes()
            ->where('oficina_id', $oficina->id)->count();

        return [
            'plano' => [
                'id'              => $plano->id,
                'nome'            => $plano->nome,
                // Disponibilidade efetiva: incluso no plano OU liberado por grant avulso.
                'alerta_whatsapp' => $ent->disponivel($oficina->id, 'ALERTA_WHATSAPP'),
                'alerta_email'    => $ent->disponivel($oficina->id, 'ALERTA_EMAIL'),
                'orcamento'       => $ent->disponivel($oficina->id, 'ORCAMENTO'),
            ],
            'usuarios'  => $this->itemUso($totalUsuarios, $plano->limite_usuarios),
            'os_mes'    => $this->itemUso($totalOsMes, $plano->limite_os_mes),
            'produtos'  => $this->itemUso($totalProdutos, $plano->limite_produtos),
            'clientes'  => $this->itemUso($totalClientes, $plano->limite_clientes),
            'notas_mes' => array_merge(
                $this->itemUso($this->totalNotasMes($oficina->id), $plano->limite_notas_mes),
                ['preco_excedente' => (float) $plano->preco_nota_excedente],
            ),
        ];
    }
}
