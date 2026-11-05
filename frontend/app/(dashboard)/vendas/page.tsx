'use client'
import { useState, useEffect, useCallback } from 'react'
import { useRouter } from 'next/navigation'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarMoeda } from '@/lib/formatters'
import { AcoesVenda, type NotaResumo } from '@/components/ui/AcoesVenda'
import api from '@/lib/api'

interface Venda {
  id: string
  numero: number
  cliente?: { nome: string } | null
  valor_total: number
  valor_pago: number
  status: string
  criado_em: string
  notas_fiscais?: NotaResumo[]
}

const I: React.CSSProperties = {
  padding: '7px 12px', borderRadius: 8, background: 'var(--card)',
  border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13, outline: 'none',
}

export default function VendasPage() {
  const router = useRouter()
  const [vendas, setVendas] = useState<Venda[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [dataInicio, setDataInicio] = useState('')
  const [dataFim, setDataFim] = useState('')
  const [mostrarCanceladas, setMostrarCanceladas] = useState(false)

  const carregar = useCallback(() => {
    setLoading(true)
    const params: Record<string, string> = { tipo: 'VENDA_BALCAO', per_page: '100' }
    if (search)     params.search      = search
    if (dataInicio) params.data_inicio = dataInicio
    if (dataFim)    params.data_fim    = dataFim
    if (mostrarCanceladas) params.incluir_canceladas = '1'
    api.get('/os', { params })
      .then(r => setVendas(r.data.data ?? []))
      .catch(() => setVendas([]))
      .finally(() => setLoading(false))
  }, [search, dataInicio, dataFim, mostrarCanceladas])

  useEffect(() => {
    const t = setTimeout(carregar, 300)
    return () => clearTimeout(t)
  }, [carregar])

  const columns: Column<Venda>[] = [
    { key: 'numero',      label: '#Venda',  render: r => <span className="font-mono" style={{ color: 'var(--accent)' }}>#{r.numero}</span> },
    { key: 'cliente',     label: 'Cliente', render: r => r.cliente?.nome ?? 'Consumidor' },
    { key: 'valor_total', label: 'Total',   render: r => <span className="font-mono">{formatarMoeda(r.valor_total)}</span> },
    { key: 'valor_pago',  label: 'Pago',    render: r => <span className="font-mono" style={{ color: Number(r.valor_pago) < Number(r.valor_total) ? 'var(--danger)' : 'var(--muted)' }}>{formatarMoeda(r.valor_pago ?? 0)}</span> },
    { key: 'status',      label: 'Status',  render: r => <StatusPill status={r.status} /> },
    { key: 'criado_em',   label: 'Data',    render: r => r.criado_em },
    { key: 'acoes',       label: '',        render: r => <AcoesVenda id={r.id} numero={r.numero} status={r.status} notas={r.notas_fiscais} onChange={carregar} /> },
  ]

  const temFiltro = !!(search || dataInicio || dataFim || mostrarCanceladas)

  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 16 }}>Vendas Realizadas</h1>
      <div style={{ display: 'flex', gap: 10, marginBottom: 20, flexWrap: 'wrap', alignItems: 'center' }}>
        <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Buscar por cliente ou #venda..." style={{ ...I, width: 240 }} />
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <span style={{ color: 'var(--muted)', fontSize: 13 }}>De</span>
          <input type="date" value={dataInicio} onChange={e => setDataInicio(e.target.value)} style={I} />
          <span style={{ color: 'var(--muted)', fontSize: 13 }}>até</span>
          <input type="date" value={dataFim} onChange={e => setDataFim(e.target.value)} style={I} />
        </div>
        <label style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--muted)', fontSize: 13, cursor: 'pointer' }}>
          <input type="checkbox" checked={mostrarCanceladas} onChange={e => setMostrarCanceladas(e.target.checked)} style={{ width: 16, height: 16, accentColor: 'var(--accent)' }} />
          Mostrar canceladas
        </label>
        {temFiltro && (
          <button onClick={() => { setSearch(''); setDataInicio(''); setDataFim(''); setMostrarCanceladas(false) }}
            style={{ ...I, background: 'transparent', color: 'var(--muted)', cursor: 'pointer' }}>Limpar</button>
        )}
        <button onClick={() => router.push('/pdv')} style={{ ...I, marginLeft: 'auto', background: 'var(--accent)', color: '#000', fontWeight: 700, border: 'none', cursor: 'pointer' }}>+ Nova venda</button>
      </div>
      <DataTable columns={columns} data={vendas} loading={loading} onRowClick={r => router.push(`/pdv/${r.id}`)} emptyMessage="Nenhuma venda encontrada." />
    </div>
  )
}
