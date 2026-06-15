'use client'
import { useState, useEffect } from 'react'
import { useRouter } from 'next/navigation'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarMoeda, formatarData } from '@/lib/formatters'
import api from '@/lib/api'

interface OS {
  id: string
  numero: number
  cliente?: { nome: string; veiculo_placa?: string }
  veiculo_placa?: string
  problema_relatado?: string
  valor_total: number
  status: string
  criado_em: string
  mecanico?: { nome: string }
}

const I: React.CSSProperties = {
  padding: '7px 12px', borderRadius: 8, background: 'var(--card)',
  border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13, outline: 'none',
}

const STATUS_OPTIONS = ['ABERTA', 'EM_ANDAMENTO', 'AGUARDANDO_PECAS', 'CONCLUIDA', 'CANCELADA']

export default function OSPage() {
  const router = useRouter()
  const [os, setOs] = useState<OS[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
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
    { key: 'valor_total', label: 'Valor',    render: r => <span className="font-mono">{formatarMoeda(r.valor_total)}</span> },
    { key: 'status',      label: 'Status',   render: r => <StatusPill status={r.status} /> },
    { key: 'criado_em',   label: 'Data',     render: r => formatarData(r.criado_em) },
  ]

  const temFiltro = !!(search || status || dataInicio || dataFim)

  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 16 }}>
        Ordens de Serviço
      </h1>

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
