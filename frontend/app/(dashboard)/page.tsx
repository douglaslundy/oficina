'use client'
import { useState, useEffect } from 'react'
import { useRouter } from 'next/navigation'
import { StatCard } from '@/components/ui/StatCard'
import { FaturamentoChart } from '@/components/dashboard/FaturamentoChart'
import { EstoqueAlerts } from '@/components/dashboard/EstoqueAlerts'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarMoeda, formatarData } from '@/lib/formatters'
import api from '@/lib/api'

interface DashData {
  stats: {
    clientes_ativos: number
    dividas_abertas: number
    faturamento_mes: number
    nf_emitidas_mes: number
  }
  faturamento_mensal: Array<{ mes: string; total: number }>
  produtos_criticos: Array<{ id: string; nome: string; qty_atual: number; qty_minima: number }>
  ultimas_os: Array<{
    id: string; numero: number; cliente?: string; status: string; valor_total: number; criado_em: string
  }>
}

export default function DashboardPage() {
  const router = useRouter()
  const [data, setData] = useState<DashData | null>(null)

  useEffect(() => {
    api.get('/dashboard').then(r => setData(r.data)).catch(() => {})
  }, [])

  const osColumns: Column<DashData['ultimas_os'][number]>[] = [
    { key: 'numero', label: '#OS', render: r => <span className="font-mono" style={{ color: 'var(--accent)' }}>#{r.numero}</span> },
    { key: 'cliente', label: 'Cliente', render: r => r.cliente ?? '-' },
    { key: 'status', label: 'Status', render: r => <StatusPill status={r.status} /> },
    { key: 'valor_total', label: 'Valor', render: r => <span className="font-mono">{formatarMoeda(r.valor_total)}</span> },
    { key: 'criado_em', label: 'Data', render: r => formatarData(r.criado_em) },
  ]

  if (!data) {
    return (
      <div>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Dashboard</h1>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 16, marginBottom: 24 }}>
          {[1, 2, 3, 4].map(i => (
            <div key={i} style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', height: 110, animation: 'pulse 1.5s ease-in-out infinite' }} />
          ))}
        </div>
      </div>
    )
  }

  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Dashboard</h1>

      {/* Stat cards */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 16, marginBottom: 24 }}>
        <StatCard
          title="Clientes Ativos"
          value={data.stats.clientes_ativos}
          icon="👥"
          color="var(--info)"
        />
        <StatCard
          title="Dívidas em Aberto"
          value={formatarMoeda(data.stats.dividas_abertas)}
          icon="⚠"
          color="var(--danger)"
          subtitle="Total em débito"
        />
        <StatCard
          title="Faturamento do Mês"
          value={formatarMoeda(data.stats.faturamento_mes)}
          icon="💰"
          color="var(--success)"
        />
        <StatCard
          title="NF Emitidas"
          value={data.stats.nf_emitidas_mes}
          icon="🧾"
          color="var(--info)"
          subtitle="Este mês"
        />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 24, marginBottom: 24 }}>
        <FaturamentoChart data={data.faturamento_mensal} />

        <EstoqueAlerts produtos={data.produtos_criticos} />
      </div>

      {/* Últimas OS */}
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
        <h3 className="font-display" style={{ fontSize: 18, fontWeight: 800, color: 'var(--text)', marginBottom: 16 }}>
          Últimas Ordens de Serviço
        </h3>
        <DataTable
          columns={osColumns}
          data={data.ultimas_os}
          onRowClick={r => router.push(`/os/${r.id}`)}
          emptyMessage="Nenhuma OS encontrada."
        />
      </div>
    </div>
  )
}
