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
    os_mes: number
    os_mes_valor: number
    vendas_mes: number
    vendas_mes_valor: number
  }
  faturamento_mensal: Array<{ mes: string; total: number }>
  produtos_criticos: Array<{ id: string; nome: string; qty_atual: number; qty_minima: number }>
  ultimas_os: Array<{
    id: string; numero: number; tipo: string; cliente?: string; status: string; valor_total: number; criado_em: string
  }>
}

export default function DashboardPage() {
  const router = useRouter()
  const [data, setData] = useState<DashData | null>(null)

  useEffect(() => {
    api.get('/dashboard').then(r => setData(r.data)).catch(() => {})
  }, [])

  const osColumns: Column<DashData['ultimas_os'][number]>[] = [
    {
      key: 'numero', label: '#', render: r => (
        <span className="font-mono" style={{ color: 'var(--accent)' }}>
          {r.tipo === 'VENDA_BALCAO' ? 'V' : 'OS'}#{r.numero}
        </span>
      ),
    },
    {
      key: 'tipo', label: 'Tipo', render: r => (
        <span style={{
          fontSize: 11, padding: '2px 8px', borderRadius: 4, fontWeight: 700,
          background: r.tipo === 'VENDA_BALCAO' ? 'rgba(30,136,229,.15)' : 'rgba(245,166,35,.15)',
          color: r.tipo === 'VENDA_BALCAO' ? 'var(--info)' : 'var(--accent)',
        }}>
          {r.tipo === 'VENDA_BALCAO' ? 'Balcão' : 'OS'}
        </span>
      ),
    },
    { key: 'cliente', label: 'Cliente', render: r => r.cliente ?? '—' },
    { key: 'status', label: 'Status', render: r => <StatusPill status={r.status} /> },
    { key: 'valor_total', label: 'Valor', render: r => <span className="font-mono">{formatarMoeda(r.valor_total)}</span> },
    { key: 'criado_em', label: 'Data', render: r => formatarData(r.criado_em) },
  ]

  if (!data) {
    return (
      <div>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Dashboard</h1>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16, marginBottom: 16 }}>
          {[1, 2, 3].map(i => (
            <div key={i} style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', height: 110, animation: 'pulse 1.5s ease-in-out infinite' }} />
          ))}
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16, marginBottom: 24 }}>
          {[4, 5, 6].map(i => (
            <div key={i} style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', height: 110, animation: 'pulse 1.5s ease-in-out infinite' }} />
          ))}
        </div>
      </div>
    )
  }

  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Dashboard</h1>

      {/* Stat cards — linha 1: financeiro */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16, marginBottom: 16 }}>
        <StatCard
          title="Faturamento do Mês"
          value={formatarMoeda(data.stats.faturamento_mes)}
          icon="💰"
          color="var(--success)"
          subtitle="OS + Vendas concluídas"
          href="/contas-a-receber"
        />
        <StatCard
          title="Dívidas em Aberto"
          value={formatarMoeda(data.stats.dividas_abertas)}
          icon="⚠"
          color="var(--danger)"
          subtitle="Total em débito"
          href="/clientes?status=DEVEDOR,DIVIDA_VENCIDA"
        />
        <StatCard
          title="NF Emitidas"
          value={data.stats.nf_emitidas_mes}
          icon="🧾"
          color="var(--info)"
          subtitle="Este mês"
          href="/fiscal/historico"
        />
      </div>

      {/* Stat cards — linha 2: operacional */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16, marginBottom: 24 }}>
        <StatCard
          title="Clientes Ativos"
          value={data.stats.clientes_ativos}
          icon="👥"
          color="var(--info)"
          href="/clientes"
        />
        <StatCard
          title="OS do Mês"
          value={data.stats.os_mes}
          icon="🔧"
          color="var(--accent)"
          subtitle={formatarMoeda(data.stats.os_mes_valor) + ' em OS'}
          href="/os"
        />
        <StatCard
          title="Vendas Balcão"
          value={data.stats.vendas_mes}
          icon="🛒"
          color="var(--info)"
          subtitle={formatarMoeda(data.stats.vendas_mes_valor) + ' em vendas'}
          href="/pdv"
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
          onRowClick={r => router.push(r.tipo === 'VENDA_BALCAO' ? `/pdv/${r.id}` : `/os/${r.id}`)}
          emptyMessage="Nenhuma OS encontrada."
        />
      </div>
    </div>
  )
}
