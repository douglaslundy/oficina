'use client'
import { useState, useEffect } from 'react'
import { useRouter } from 'next/navigation'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarMoeda, formatarData } from '@/lib/formatters'
import { usePlanLimites } from '@/hooks/usePlanLimites'
import api from '@/lib/api'

interface OS {
  id: string
  numero: number
  cliente?: { nome: string; veiculo_placa?: string }
  veiculo_placa?: string
  problema_relatado?: string
  valor_total: number
  valor_pago: number
  status: string
  criado_em: string
  mecanico?: { nome: string }
}

function pagamentoPill(valorPago: number, valorTotal: number) {
  if (valorTotal <= 0) return null
  const pago = Number(valorPago ?? 0)
  const total = Number(valorTotal ?? 0)
  if (pago >= total) {
    return <span style={{ display: 'inline-block', padding: '2px 9px', borderRadius: 999, fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em', background: 'rgba(67,160,71,.15)', color: 'var(--success)' }}>PAGO</span>
  }
  if (pago > 0) {
    return <span style={{ display: 'inline-block', padding: '2px 9px', borderRadius: 999, fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em', background: 'rgba(245,166,35,.15)', color: 'var(--accent)' }}>PARCIAL</span>
  }
  return <span style={{ display: 'inline-block', padding: '2px 9px', borderRadius: 999, fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em', background: 'rgba(229,57,53,.15)', color: 'var(--danger)' }}>A PAGAR</span>
}

const I: React.CSSProperties = {
  padding: '7px 12px', borderRadius: 8, background: 'var(--card)',
  border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13, outline: 'none',
}

const STATUS_OPTIONS = ['ABERTA', 'EM_ANDAMENTO', 'AGUARDANDO_PECAS', 'CONCLUIDA', 'CANCELADA']

function PlanUsageBar({ atual, limite, label }: { atual: number; limite: number; label: string }) {
  if (limite === -1) return null
  const pct = Math.min(100, Math.round((atual / limite) * 100))
  const color = pct >= 100 ? 'var(--danger)' : pct >= 80 ? 'var(--accent)' : 'var(--success)'
  return (
    <div style={{ marginBottom: 16, padding: '12px 16px', borderRadius: 10, background: 'var(--card)', border: '1px solid var(--border)', maxWidth: 380 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
        <span style={{ color: 'var(--muted)', fontSize: 13 }}>{label}</span>
        <span className="font-mono" style={{ fontSize: 13, color, fontWeight: 600 }}>{atual} / {limite}</span>
      </div>
      <div style={{ height: 6, borderRadius: 3, background: 'var(--border)', overflow: 'hidden' }}>
        <div style={{ height: '100%', width: `${pct}%`, borderRadius: 3, background: color, transition: 'width .4s ease' }} />
      </div>
    </div>
  )
}

export default function OSPage() {
  const router = useRouter()
  const [os, setOs] = useState<OS[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const { limites } = usePlanLimites()
  const [status, setStatus] = useState('')
  const [dataInicio, setDataInicio] = useState('')
  const [dataFim, setDataFim] = useState('')

  useEffect(() => {
    const t = setTimeout(() => {
      setLoading(true)
      const params: Record<string, string> = {}
      if (search)     params.search      = search
      if (status)     params.status      = status
      if (dataInicio) params.data_inicio = dataInicio
      if (dataFim)    params.data_fim    = dataFim
      api.get('/os', { params })
        .then(r => setOs(r.data.data ?? []))
        .catch(() => setOs([]))
        .finally(() => setLoading(false))
    }, 300)
    return () => clearTimeout(t)
  }, [search, status, dataInicio, dataFim])

  const columns: Column<OS>[] = [
    { key: 'numero',      label: '#OS',      render: r => <span className="font-mono" style={{ color: 'var(--accent)' }}>#{r.numero}</span> },
    { key: 'cliente',     label: 'Cliente',  render: r => r.cliente?.nome ?? '-' },
    { key: 'veiculo',     label: 'Veículo',  render: r => r.cliente?.veiculo_placa ?? r.veiculo_placa ?? '-' },
    { key: 'mecanico',    label: 'Mecânico', render: r => r.mecanico?.nome ?? '-' },
    { key: 'problema',    label: 'Serviço',  render: r => <span style={{ color: 'var(--text)', fontSize: 13 }}>{r.problema_relatado?.slice(0, 40) ?? '-'}</span> },
    { key: 'valor_total', label: 'Total',     render: r => <span className="font-mono">{formatarMoeda(r.valor_total)}</span> },
    { key: 'valor_pago',  label: 'Pago',     render: r => <span className="font-mono" style={{ color: 'var(--muted)' }}>{formatarMoeda(r.valor_pago ?? 0)}</span> },
    { key: 'pagamento',   label: 'Pagamento', render: r => pagamentoPill(r.valor_pago ?? 0, r.valor_total) },
    { key: 'status',      label: 'Status',   render: r => <StatusPill status={r.status} /> },
    { key: 'criado_em',   label: 'Data',     render: r => formatarData(r.criado_em) },
  ]

  const temFiltro = !!(search || status || dataInicio || dataFim)

  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 16 }}>
        Ordens de Serviço
      </h1>

      {limites?.os_mes && (
        <PlanUsageBar atual={limites.os_mes.atual} limite={limites.os_mes.limite} label="OS abertas este mês" />
      )}

      {/* Filtros */}
      <div style={{ display: 'flex', gap: 10, marginBottom: 20, flexWrap: 'wrap', alignItems: 'center' }}>
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="Buscar por cliente ou #OS..."
          style={{ ...I, width: 240 }}
        />
        <select value={status} onChange={e => setStatus(e.target.value)} style={I}>
          <option value="">Todos os status</option>
          {STATUS_OPTIONS.map(s => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
        </select>
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <span style={{ color: 'var(--muted)', fontSize: 13 }}>De</span>
          <input type="date" value={dataInicio} onChange={e => setDataInicio(e.target.value)} style={I} />
          <span style={{ color: 'var(--muted)', fontSize: 13 }}>até</span>
          <input type="date" value={dataFim} onChange={e => setDataFim(e.target.value)} style={I} />
        </div>
        {temFiltro && (
          <button
            onClick={() => { setSearch(''); setStatus(''); setDataInicio(''); setDataFim('') }}
            style={{ ...I, background: 'transparent', color: 'var(--muted)', cursor: 'pointer' }}
          >
            Limpar
          </button>
        )}
      </div>

      <DataTable
        columns={columns}
        data={os}
        loading={loading}
        onRowClick={r => router.push(`/os/${r.id}`)}
        emptyMessage="Nenhuma OS encontrada."
      />
    </div>
  )
}
