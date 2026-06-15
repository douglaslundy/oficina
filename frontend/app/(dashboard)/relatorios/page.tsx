'use client'
import { useState, useEffect } from 'react'
import { formatarMoeda } from '@/lib/formatters'
import { toast } from '@/hooks/useToast'
import api from '@/lib/api'

interface OsReport {
  total_os: number
  total_faturado: number
  total_recebido: number
  total_devedor: number
  por_status: Record<string, number>
}

interface EstoqueReport {
  total_produtos: number
  criticos: number
  baixos: number
  normais: number
}

const I: React.CSSProperties = {
  padding: '7px 12px', borderRadius: 8, background: 'var(--card)',
  border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13, outline: 'none',
}

const STATUS_OPTIONS = ['ABERTA', 'EM_ANDAMENTO', 'AGUARDANDO_PECAS', 'CONCLUIDA', 'CANCELADA']

export default function RelatoriosPage() {
  const [osData, setOsData] = useState<OsReport | null>(null)
  const [estoqueData, setEstoqueData] = useState<EstoqueReport | null>(null)
  const [dataInicio, setDataInicio] = useState('')
  const [dataFim, setDataFim] = useState('')
  const [statusFiltro, setStatusFiltro] = useState('')

  useEffect(() => {
    const params: Record<string, string> = {}
    if (dataInicio)   params.data_inicio = dataInicio
    if (dataFim)      params.data_fim    = dataFim
    if (statusFiltro) params.status      = statusFiltro

    Promise.all([
      api.get('/relatorios/os', { params }),
      api.get('/relatorios/estoque'),
    ]).then(([os, est]) => {
      setOsData(os.data)
      setEstoqueData(est.data)
    }).catch(() => {})
  }, [dataInicio, dataFim, statusFiltro])

  async function exportar(tipo: 'os' | 'clientes' | 'estoque') {
    try {
      const token = localStorage.getItem('auth_token')
      const slug  = localStorage.getItem('oficina_slug')
      let qs = '?export=true'
      if (tipo === 'os') {
        if (dataInicio)   qs += `&data_inicio=${dataInicio}`
        if (dataFim)      qs += `&data_fim=${dataFim}`
        if (statusFiltro) qs += `&status=${statusFiltro}`
      }
      const res = await fetch(
        `${process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000'}/api/relatorios/${tipo}${qs}`,
        { headers: { Authorization: `Bearer ${token}`, 'X-Tenant': slug ?? '' } }
      )
      if (!res.ok) throw new Error()
      const blob = await res.blob()
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `${tipo}.xlsx`
      a.click()
      URL.revokeObjectURL(url)
    } catch {
      toast('Erro ao exportar.', 'danger')
    }
  }

  const card: React.CSSProperties = {
    background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24,
  }

  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>
        Relatórios
      </h1>

      {/* Filtros OS */}
      <div style={{ ...card, marginBottom: 20 }}>
        <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 14 }}>
          Filtros — Ordens de Serviço
        </h3>
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
          <select value={statusFiltro} onChange={e => setStatusFiltro(e.target.value)} style={I}>
            <option value="">Todos os status</option>
            {STATUS_OPTIONS.map(s => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
          </select>
          <input type="date" value={dataInicio} onChange={e => setDataInicio(e.target.value)} style={I} />
          <span style={{ color: 'var(--muted)', fontSize: 13 }}>até</span>
          <input type="date" value={dataFim} onChange={e => setDataFim(e.target.value)} style={I} />
          {(dataInicio || dataFim || statusFiltro) && (
            <button onClick={() => { setDataInicio(''); setDataFim(''); setStatusFiltro('') }}
              style={{ ...I, background: 'transparent', color: 'var(--muted)', cursor: 'pointer' }}>
              Limpar
            </button>
          )}
        </div>
      </div>

      {/* Resumo OS */}
      {osData && (
        <div style={{ ...card, marginBottom: 20 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
            <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: 0 }}>
              Ordens de Serviço
            </h3>
            <button onClick={() => exportar('os')}
              style={{ padding: '6px 16px', background: 'var(--success)', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 13, fontWeight: 600 }}>
              📊 Exportar XLSX
            </button>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12, marginBottom: 16 }}>
            {[
              { label: 'Total de OS',  value: String(osData.total_os),                    color: 'var(--info)' },
              { label: 'Faturado',     value: formatarMoeda(osData.total_faturado),        color: 'var(--success)' },
              { label: 'Recebido',     value: formatarMoeda(osData.total_recebido),        color: 'var(--accent)' },
              { label: 'A Receber',    value: formatarMoeda(osData.total_devedor),         color: 'var(--danger)' },
            ].map(({ label, value, color }) => (
              <div key={label} style={{ background: 'var(--bg)', borderRadius: 8, padding: '12px 16px', borderLeft: `3px solid ${color}` }}>
                <p style={{ color: 'var(--muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.06em', margin: '0 0 6px' }}>{label}</p>
                <p className="font-mono" style={{ color: 'var(--text)', fontSize: 20, fontWeight: 700, margin: 0 }}>{value}</p>
              </div>
            ))}
          </div>
          {Object.entries(osData.por_status).length > 0 && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              {Object.entries(osData.por_status).map(([s, count]) => (
                <span key={s} style={{ padding: '4px 12px', borderRadius: 20, fontSize: 12, background: 'var(--surface)', color: 'var(--muted)', border: '1px solid var(--border)' }}>
                  {s.replace(/_/g, ' ')}: <strong style={{ color: 'var(--text)' }}>{count}</strong>
                </span>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Clientes e Estoque */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20 }}>
        <div style={card}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
            <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: 0 }}>Clientes</h3>
            <button onClick={() => exportar('clientes')}
              style={{ padding: '6px 16px', background: 'var(--success)', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 13, fontWeight: 600 }}>
              📊 Exportar XLSX
            </button>
          </div>
          <p style={{ color: 'var(--muted)', fontSize: 14 }}>
            Exporta lista completa de clientes com status e dados de contato.
          </p>
        </div>

        {estoqueData && (
          <div style={card}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
              <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: 0 }}>Estoque</h3>
              <button onClick={() => exportar('estoque')}
                style={{ padding: '6px 16px', background: 'var(--success)', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 13, fontWeight: 600 }}>
                📊 Exportar XLSX
              </button>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 8 }}>
              {[
                { label: 'Críticos', value: estoqueData.criticos, color: 'var(--danger)' },
                { label: 'Baixos',   value: estoqueData.baixos,   color: 'var(--accent)' },
                { label: 'Normais',  value: estoqueData.normais,  color: 'var(--success)' },
              ].map(({ label, value, color }) => (
                <div key={label} style={{ textAlign: 'center', padding: 12, background: 'var(--bg)', borderRadius: 8 }}>
                  <p style={{ color, fontSize: 22, fontWeight: 700, margin: '0 0 4px' }}>{value}</p>
                  <p style={{ color: 'var(--muted)', fontSize: 11, margin: 0 }}>{label}</p>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
