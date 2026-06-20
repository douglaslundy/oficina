'use client'
import { useState, useEffect, useCallback } from 'react'
import { useRouter } from 'next/navigation'
import api from '@/lib/api'
import { formatarMoeda, formatarData } from '@/lib/formatters'
import { StatusPill } from '@/components/ui/StatusPill'

interface ContaReceber {
  id: string
  numero: number
  tipo: string
  status: string
  cliente?: { id: string; nome: string } | null
  valor_total: number
  valor_pago: number
  saldo_devedor: number
  venda_a_prazo: boolean
  prazo_pagamento_dias?: number
  data_vencimento_pagamento?: string
  criado_em: string
}

function diasParaVencimento(dataVenc: string): number {
  const [d, m, y] = dataVenc.split('/')
  const venc = new Date(Number(y), Number(m) - 1, Number(d))
  const hoje = new Date()
  hoje.setHours(0, 0, 0, 0)
  return Math.ceil((venc.getTime() - hoje.getTime()) / (1000 * 60 * 60 * 24))
}

export default function ContasAReceberPage() {
  const router = useRouter()
  const [contas, setContas]   = useState<ContaReceber[]>([])
  const [loading, setLoading] = useState(true)
  const [filtro, setFiltro]   = useState<'todos' | 'vencidas' | 'a_vencer'>('todos')

  const fetchContas = useCallback(async () => {
    setLoading(true)
    try {
      const r = await api.get<{ data: ContaReceber[] }>('/os', {
        params: { em_aberto: 1, tipo: 'OS,VENDA_BALCAO', per_page: 200 },
      })
      setContas(r.data.data ?? [])
    } catch {
      setContas([])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { fetchContas() }, [fetchContas])

  const contasFiltradas = contas.filter(c => {
    if (filtro === 'todos') return true
    if (!c.data_vencimento_pagamento) return filtro === 'a_vencer'
    const dias = diasParaVencimento(c.data_vencimento_pagamento)
    if (filtro === 'vencidas') return dias < 0
    if (filtro === 'a_vencer') return dias >= 0
    return true
  })

  const totalGeral   = contas.reduce((s, c) => s + c.saldo_devedor, 0)
  const totalVencido = contas.filter(c => c.data_vencimento_pagamento && diasParaVencimento(c.data_vencimento_pagamento) < 0).reduce((s, c) => s + c.saldo_devedor, 0)
  const totalAVencer = contas.filter(c => !c.data_vencimento_pagamento || diasParaVencimento(c.data_vencimento_pagamento) >= 0).reduce((s, c) => s + c.saldo_devedor, 0)

  const labelStyle: React.CSSProperties = {
    fontSize: 11, fontWeight: 600, color: 'var(--muted)',
    textTransform: 'uppercase', letterSpacing: '0.05em',
  }

  return (
    <div style={{ padding: '28px 32px', maxWidth: 1000, margin: '0 auto', color: 'var(--text)' }}>
      <div style={{ marginBottom: 24 }}>
        <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, margin: 0 }}>Contas a Receber</h1>
        <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>OS e vendas com saldo em aberto</p>
      </div>

      {/* Stat cards */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16, marginBottom: 24 }}>
        {[
          { label: 'Total em Aberto', valor: totalGeral,   cor: 'var(--text)'    },
          { label: 'Vencido',         valor: totalVencido, cor: 'var(--danger)'  },
          { label: 'A Vencer',        valor: totalAVencer, cor: 'var(--accent)'  },
        ].map(s => (
          <div key={s.label} style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: '16px 20px' }}>
            <p style={{ ...labelStyle, margin: '0 0 6px' }}>{s.label}</p>
            <p className="font-mono" style={{ margin: 0, fontSize: 20, fontWeight: 800, color: s.cor }}>
              {formatarMoeda(s.valor)}
            </p>
          </div>
        ))}
      </div>

      {/* Filtros */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
        {(['todos', 'vencidas', 'a_vencer'] as const).map(f => (
          <button key={f} onClick={() => setFiltro(f)}
            style={{
              padding: '7px 16px', borderRadius: 6, fontSize: 13, fontWeight: 600, cursor: 'pointer',
              background: filtro === f ? 'var(--accent)' : 'transparent',
              border: `1px solid ${filtro === f ? 'var(--accent)' : 'var(--border)'}`,
              color: filtro === f ? '#000' : 'var(--muted)',
            }}>
            {f === 'todos' ? 'Todos' : f === 'vencidas' ? 'Vencidas' : 'A Vencer'}
          </button>
        ))}
        <button onClick={fetchContas}
          style={{ marginLeft: 'auto', padding: '7px 14px', borderRadius: 6, fontSize: 13, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer' }}>
          ↻ Atualizar
        </button>
      </div>

      {/* Tabela */}
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr>
                {['#', 'Tipo', 'Cliente', 'Data', 'Total', 'Pago', 'Saldo', 'Vencimento', 'Status'].map(h => (
                  <th key={h} style={{
                    padding: '10px 14px', textAlign: 'left', fontSize: 11, fontWeight: 600,
                    color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em',
                    borderBottom: '1px solid var(--border)', background: 'var(--surface)', whiteSpace: 'nowrap',
                  }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                Array.from({ length: 5 }).map((_, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                    {Array.from({ length: 9 }).map((_, j) => (
                      <td key={j} style={{ padding: '12px 14px' }}>
                        <div style={{ height: 12, borderRadius: 3, background: 'var(--border)', width: 60 }} />
                      </td>
                    ))}
                  </tr>
                ))
              ) : contasFiltradas.length === 0 ? (
                <tr>
                  <td colSpan={9} style={{ padding: 48, textAlign: 'center', color: 'var(--muted)', fontSize: 14 }}>
                    Nenhuma conta em aberto.
                  </td>
                </tr>
              ) : (
                contasFiltradas.map((c, idx) => {
                  const dias = c.data_vencimento_pagamento ? diasParaVencimento(c.data_vencimento_pagamento) : null
                  const vencida = dias !== null && dias < 0
                  const corSaldo = vencida ? 'var(--danger)' : 'var(--accent)'
                  const rowBg = vencida ? 'rgba(229,57,53,.05)' : ''

                  return (
                    <tr key={c.id}
                      onClick={() => c.tipo !== 'VENDA_BALCAO' && router.push(`/os/${c.id}`)}
                      style={{
                        borderBottom: idx < contasFiltradas.length - 1 ? '1px solid var(--border)' : 'none',
                        background: rowBg,
                        cursor: c.tipo !== 'VENDA_BALCAO' ? 'pointer' : 'default',
                      }}
                      onMouseEnter={e => {
                        (e.currentTarget as HTMLTableRowElement).style.background =
                          vencida ? 'rgba(229,57,53,.08)' : 'rgba(255,255,255,.02)'
                      }}
                      onMouseLeave={e => {
                        (e.currentTarget as HTMLTableRowElement).style.background = rowBg
                      }}
                    >
                      <td style={{ padding: '11px 14px', fontSize: 13, fontWeight: 700 }}>#{c.numero}</td>
                      <td style={{ padding: '11px 14px' }}>
                        <span style={{
                          fontSize: 11, padding: '2px 8px', borderRadius: 4, fontWeight: 700,
                          background: c.tipo === 'VENDA_BALCAO' ? 'rgba(30,136,229,.15)' : 'rgba(245,166,35,.15)',
                          color: c.tipo === 'VENDA_BALCAO' ? 'var(--info)' : 'var(--accent)',
                        }}>
                          {c.tipo === 'VENDA_BALCAO' ? 'Balcão' : 'OS'}
                        </span>
                      </td>
                      <td style={{ padding: '11px 14px', fontSize: 13 }}>{c.cliente?.nome ?? '—'}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12, color: 'var(--muted)' }}>{formatarData(c.criado_em)}</td>
                      <td style={{ padding: '11px 14px', fontFamily: 'monospace', fontSize: 13 }}>{formatarMoeda(c.valor_total)}</td>
                      <td style={{ padding: '11px 14px', fontFamily: 'monospace', fontSize: 13, color: 'var(--success)' }}>{formatarMoeda(c.valor_pago)}</td>
                      <td style={{ padding: '11px 14px', fontFamily: 'monospace', fontSize: 13, fontWeight: 700, color: corSaldo }}>{formatarMoeda(c.saldo_devedor)}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12 }}>
                        {dias === null ? (
                          <span style={{ color: 'var(--muted)' }}>—</span>
                        ) : vencida ? (
                          <span style={{ color: 'var(--danger)', fontWeight: 700 }}>Venceu há {Math.abs(dias)}d</span>
                        ) : (
                          <span style={{ color: 'var(--accent)' }}>Em {dias}d</span>
                        )}
                      </td>
                      <td style={{ padding: '11px 14px' }}><StatusPill status={c.status} /></td>
                    </tr>
                  )
                })
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
