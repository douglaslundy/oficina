'use client'
import { useState, useEffect, useCallback } from 'react'
import { useRouter } from 'next/navigation'
import api from '@/lib/api'
import { formatarMoeda, formatarData } from '@/lib/formatters'
import { StatusPill } from '@/components/ui/StatusPill'

interface Conta {
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

type Modo = 'aberto' | 'recebidas'
type Filtro = 'todos' | 'vencidas' | 'a_vencer'

function diasParaVencimento(dataVenc: string): number {
  const [d, m, y] = dataVenc.split('/')
  const venc = new Date(Number(y), Number(m) - 1, Number(d))
  const hoje = new Date(); hoje.setHours(0, 0, 0, 0)
  return Math.ceil((venc.getTime() - hoje.getTime()) / (1000 * 60 * 60 * 24))
}

export default function ContasAReceberPage() {
  const router = useRouter()
  const [modo, setModo] = useState<Modo>('aberto')
  const [contas, setContas] = useState<Conta[]>([])
  const [loading, setLoading] = useState(true)
  const [filtro, setFiltro] = useState<Filtro>('todos')

  const fetchContas = useCallback(async () => {
    setLoading(true)
    try {
      const params =
        modo === 'aberto'
          ? { em_aberto: 1, tipo: 'OS,VENDA_BALCAO', per_page: 200 }
          : { status: 'CONCLUIDA', tipo: 'OS,VENDA_BALCAO', per_page: 200 }

      const r = await api.get<{ data: Conta[] }>('/os', { params })
      let data = r.data.data ?? []

      // no modo recebidas, só mostra quem tem valor_pago > 0
      if (modo === 'recebidas') {
        data = data.filter(c => c.valor_pago > 0)
      }

      setContas(data)
    } catch {
      setContas([])
    } finally {
      setLoading(false)
    }
  }, [modo])

  useEffect(() => { fetchContas() }, [fetchContas])
  useEffect(() => { setFiltro('todos') }, [modo])

  /* --- derivados --- */
  const contasFiltradas = contas.filter(c => {
    if (modo === 'recebidas') return true
    if (filtro === 'todos') return true
    if (!c.data_vencimento_pagamento) return filtro === 'a_vencer'
    const dias = diasParaVencimento(c.data_vencimento_pagamento)
    return filtro === 'vencidas' ? dias < 0 : dias >= 0
  })

  // Em aberto
  const totalGeral   = contas.reduce((s, c) => s + c.saldo_devedor, 0)
  const totalVencido = contas.filter(c => c.data_vencimento_pagamento && diasParaVencimento(c.data_vencimento_pagamento) < 0).reduce((s, c) => s + c.saldo_devedor, 0)
  const totalAVencer = contas.filter(c => !c.data_vencimento_pagamento || diasParaVencimento(c.data_vencimento_pagamento) >= 0).reduce((s, c) => s + c.saldo_devedor, 0)

  // Recebidas — limita ao valor_total para ignorar troco
  const totalFaturado = contas.reduce((s, c) => s + Math.min(c.valor_pago, c.valor_total), 0)
  const totalOS       = contas.filter(c => c.tipo !== 'VENDA_BALCAO').reduce((s, c) => s + Math.min(c.valor_pago, c.valor_total), 0)
  const totalVendas   = contas.filter(c => c.tipo === 'VENDA_BALCAO').reduce((s, c) => s + Math.min(c.valor_pago, c.valor_total), 0)

  const labelStyle: React.CSSProperties = {
    fontSize: 11, fontWeight: 600, color: 'var(--muted)',
    textTransform: 'uppercase', letterSpacing: '0.05em',
  }

  return (
    <div style={{ padding: '28px 32px', maxWidth: 1060, margin: '0 auto', color: 'var(--text)' }}>
      <div style={{ marginBottom: 20 }}>
        <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, margin: 0 }}>Contas a Receber</h1>
        <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>OS e vendas com saldo em aberto ou já recebidas</p>
      </div>

      {/* Toggle de modo */}
      <div style={{ display: 'flex', gap: 0, marginBottom: 20, border: '1px solid var(--border)', borderRadius: 8, overflow: 'hidden', width: 'fit-content' }}>
        {(['aberto', 'recebidas'] as Modo[]).map(m => (
          <button key={m} onClick={() => setModo(m)} style={{
            padding: '8px 22px', fontSize: 13, fontWeight: 600, cursor: 'pointer', border: 'none',
            background: modo === m ? 'var(--accent)' : 'transparent',
            color: modo === m ? '#000' : 'var(--muted)',
            transition: 'all 0.15s',
          }}>
            {m === 'aberto' ? '📋 Em Aberto' : '✅ Recebidas'}
          </button>
        ))}
      </div>

      {/* KPIs — Em Aberto */}
      {modo === 'aberto' && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 14, marginBottom: 20 }}>
          {[
            { label: 'Total em Aberto', valor: totalGeral,   cor: 'var(--text)'   },
            { label: 'Vencido',         valor: totalVencido, cor: 'var(--danger)' },
            { label: 'A Vencer',        valor: totalAVencer, cor: 'var(--accent)' },
          ].map(s => (
            <div key={s.label} style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: '14px 18px' }}>
              <p style={{ ...labelStyle, margin: '0 0 5px' }}>{s.label}</p>
              <p className="font-mono" style={{ margin: 0, fontSize: 20, fontWeight: 800, color: s.cor }}>{formatarMoeda(s.valor)}</p>
            </div>
          ))}
        </div>
      )}

      {/* KPIs — Recebidas */}
      {modo === 'recebidas' && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 14, marginBottom: 20 }}>
          <div style={{
            background: 'var(--card)', border: '2px solid var(--success)', borderRadius: 10, padding: '14px 18px',
            position: 'relative', overflow: 'hidden',
          }}>
            <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 2, background: 'var(--success)' }} />
            <p style={{ ...labelStyle, margin: '0 0 5px' }}>Total Faturado</p>
            <p className="font-mono" style={{ margin: 0, fontSize: 22, fontWeight: 800, color: 'var(--success)' }}>{formatarMoeda(totalFaturado)}</p>
            <p style={{ color: 'var(--muted)', fontSize: 11, margin: '4px 0 0' }}>{contas.length} registros</p>
          </div>
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: '14px 18px' }}>
            <p style={{ ...labelStyle, margin: '0 0 5px' }}>Receita de OS</p>
            <p className="font-mono" style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--accent)' }}>{formatarMoeda(totalOS)}</p>
          </div>
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: '14px 18px' }}>
            <p style={{ ...labelStyle, margin: '0 0 5px' }}>Receita de Vendas</p>
            <p className="font-mono" style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--info)' }}>{formatarMoeda(totalVendas)}</p>
          </div>
        </div>
      )}

      {/* Filtros (só em "aberto") */}
      {modo === 'aberto' && (
        <div style={{ display: 'flex', gap: 8, marginBottom: 14 }}>
          {(['todos', 'vencidas', 'a_vencer'] as Filtro[]).map(f => (
            <button key={f} onClick={() => setFiltro(f)} style={{
              padding: '7px 16px', borderRadius: 6, fontSize: 13, fontWeight: 600, cursor: 'pointer',
              background: filtro === f ? 'var(--accent)' : 'transparent',
              border: `1px solid ${filtro === f ? 'var(--accent)' : 'var(--border)'}`,
              color: filtro === f ? '#000' : 'var(--muted)',
            }}>
              {f === 'todos' ? 'Todos' : f === 'vencidas' ? 'Vencidas' : 'A Vencer'}
            </button>
          ))}
          <button onClick={fetchContas} style={{ marginLeft: 'auto', padding: '7px 14px', borderRadius: 6, fontSize: 13, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer' }}>
            ↻ Atualizar
          </button>
        </div>
      )}

      {modo === 'recebidas' && (
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 14 }}>
          <button onClick={fetchContas} style={{ padding: '7px 14px', borderRadius: 6, fontSize: 13, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer' }}>
            ↻ Atualizar
          </button>
        </div>
      )}

      {/* Tabela */}
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr>
                {(modo === 'aberto'
                  ? ['#', 'Tipo', 'Cliente', 'Data', 'Total', 'Pago', 'Saldo', 'Vencimento', 'Status']
                  : ['#', 'Tipo', 'Cliente', 'Data', 'Total', 'Recebido', 'Status']
                ).map(h => (
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
                    {Array.from({ length: modo === 'aberto' ? 9 : 7 }).map((_, j) => (
                      <td key={j} style={{ padding: '12px 14px' }}>
                        <div style={{ height: 12, borderRadius: 3, background: 'var(--border)', width: 60 }} />
                      </td>
                    ))}
                  </tr>
                ))
              ) : contasFiltradas.length === 0 ? (
                <tr>
                  <td colSpan={modo === 'aberto' ? 9 : 7} style={{ padding: 48, textAlign: 'center', color: 'var(--muted)', fontSize: 14 }}>
                    {modo === 'aberto' ? 'Nenhuma conta em aberto.' : 'Nenhuma conta recebida.'}
                  </td>
                </tr>
              ) : contasFiltradas.map((c, idx) => {
                const dias = c.data_vencimento_pagamento ? diasParaVencimento(c.data_vencimento_pagamento) : null
                const vencida = dias !== null && dias < 0
                const rowBg = modo === 'aberto' && vencida ? 'rgba(229,57,53,.05)' : ''
                const recebido = Math.min(c.valor_pago, c.valor_total)

                return (
                  <tr key={c.id}
                    onClick={() => router.push(c.tipo === 'VENDA_BALCAO' ? `/pdv/${c.id}` : `/os/${c.id}`)}
                    style={{
                      borderBottom: idx < contasFiltradas.length - 1 ? '1px solid var(--border)' : 'none',
                      background: rowBg, cursor: 'pointer',
                    }}
                    onMouseEnter={e => { (e.currentTarget as HTMLTableRowElement).style.background = vencida && modo === 'aberto' ? 'rgba(229,57,53,.08)' : 'rgba(255,255,255,.02)' }}
                    onMouseLeave={e => { (e.currentTarget as HTMLTableRowElement).style.background = rowBg }}
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

                    {modo === 'aberto' ? (
                      <>
                        <td style={{ padding: '11px 14px', fontFamily: 'monospace', fontSize: 13, color: 'var(--success)' }}>{formatarMoeda(c.valor_pago)}</td>
                        <td style={{ padding: '11px 14px', fontFamily: 'monospace', fontSize: 13, fontWeight: 700, color: vencida ? 'var(--danger)' : 'var(--accent)' }}>{formatarMoeda(c.saldo_devedor)}</td>
                        <td style={{ padding: '11px 14px', fontSize: 12 }}>
                          {dias === null ? <span style={{ color: 'var(--muted)' }}>—</span>
                            : vencida ? <span style={{ color: 'var(--danger)', fontWeight: 700 }}>Venceu há {Math.abs(dias)}d</span>
                            : <span style={{ color: 'var(--accent)' }}>Em {dias}d</span>}
                        </td>
                      </>
                    ) : (
                      <td style={{ padding: '11px 14px', fontFamily: 'monospace', fontSize: 13, fontWeight: 700, color: 'var(--success)' }}>{formatarMoeda(recebido)}</td>
                    )}

                    <td style={{ padding: '11px 14px' }}><StatusPill status={c.status} /></td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
