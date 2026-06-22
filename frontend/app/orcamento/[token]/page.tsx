'use client'
import { useCallback, useEffect, useState } from 'react'
import { useParams } from 'next/navigation'
import axios from 'axios'

const API = (process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000') + '/api'

interface Item {
  id: string
  descricao: string
  quantidade: number
  valor_unitario: number
  valor_total: number
  aprovado?: boolean | null
}
interface Orc {
  oficina: string | null
  os_numero: number
  cliente: string | null
  veiculo: string | null
  problema: string | null
  status: string
  respondido: boolean
  servicos: Item[]
  pecas: Item[]
}

function brl(v: number) {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v || 0)
}

const wrap: React.CSSProperties = {
  minHeight: '100vh', background: 'var(--bg)', color: 'var(--text)',
  fontFamily: "'Barlow', sans-serif", padding: '24px 16px',
}
const card: React.CSSProperties = {
  maxWidth: 620, margin: '0 auto', background: 'var(--card)',
  border: '1px solid var(--border)', borderRadius: 14, overflow: 'hidden',
}

export default function OrcamentoPublicoPage() {
  const { token } = useParams<{ token: string }>()
  const [orc, setOrc] = useState<Orc | null>(null)
  const [loading, setLoading] = useState(true)
  const [erro, setErro] = useState<string | null>(null)
  const [selecionados, setSelecionados] = useState<Record<string, boolean>>({})
  const [enviando, setEnviando] = useState(false)
  const [feito, setFeito] = useState<string | null>(null)

  const carregar = useCallback(() => {
    setLoading(true)
    axios.get<{ data: Orc }>(`${API}/orcamento/${token}`)
      .then(r => {
        setOrc(r.data.data)
        const sel: Record<string, boolean> = {}
        // default marcado para serviços e peças (aprovado !== false)
        r.data.data.servicos.forEach(s => { sel[s.id] = s.aprovado !== false })
        r.data.data.pecas.forEach(p => { sel[p.id] = p.aprovado !== false })
        setSelecionados(sel)
      })
      .catch(() => setErro('Orçamento não encontrado ou expirado.'))
      .finally(() => setLoading(false))
  }, [token])

  useEffect(() => { carregar() }, [carregar])

  const totalSelecionado = orc
    ? [...orc.servicos, ...orc.pecas]
        .filter(i => selecionados[i.id])
        .reduce((acc, i) => acc + Number(i.valor_total), 0)
    : 0

  async function enviar() {
    if (!orc) return
    const servicos_aprovados = orc.servicos.filter(s => selecionados[s.id]).map(s => s.id)
    const pecas_aprovadas    = orc.pecas.filter(p => selecionados[p.id]).map(p => p.id)
    setEnviando(true)
    try {
      const r = await axios.post<{ status: string }>(`${API}/orcamento/${token}/responder`, { servicos_aprovados, pecas_aprovadas })
      setFeito(r.data.status)
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      setErro(msg ?? 'Não foi possível registrar sua resposta.')
    } finally { setEnviando(false) }
  }

  if (loading) return <div style={wrap}><div style={{ ...card, padding: 32, textAlign: 'center', color: 'var(--muted)' }}>Carregando orçamento...</div></div>

  if (erro) return (
    <div style={wrap}><div style={{ ...card, padding: 32, textAlign: 'center' }}>
      <div style={{ fontSize: 36, marginBottom: 8 }}>⚠️</div>
      <p style={{ color: 'var(--danger)', fontWeight: 700 }}>{erro}</p>
    </div></div>
  )

  if (feito || orc?.respondido) {
    const st = feito ?? orc?.status ?? ''
    const aprovado = st === 'APROVADO' || st === 'PARCIAL'
    return (
      <div style={wrap}><div style={{ ...card, padding: 36, textAlign: 'center' }}>
        <div style={{ fontSize: 44, marginBottom: 12 }}>{aprovado ? '✅' : '❌'}</div>
        <h2 className="font-display" style={{ fontSize: 22, fontWeight: 800, margin: 0 }}>
          {st === 'APROVADO' ? 'Orçamento aprovado!' : st === 'PARCIAL' ? 'Aprovação parcial registrada!' : 'Orçamento recusado'}
        </h2>
        <p style={{ color: 'var(--muted)', fontSize: 14, marginTop: 10 }}>
          Sua resposta foi registrada e a oficina foi notificada. Obrigado!
        </p>
      </div></div>
    )
  }

  if (!orc) return null

  return (
    <div style={wrap}>
      <div style={card}>
        <div style={{ background: 'var(--accent)', padding: '18px 24px', color: '#000' }}>
          <div style={{ fontWeight: 800, fontSize: 20 }} className="font-display">🔧 {orc.oficina ?? 'Oficina'}</div>
          <div style={{ fontSize: 13, fontWeight: 600 }}>Orçamento da OS #{orc.os_numero}</div>
        </div>

        <div style={{ padding: 24 }}>
          <div style={{ fontSize: 14, color: 'var(--muted)', marginBottom: 16 }}>
            {orc.cliente && <div><b style={{ color: 'var(--text)' }}>Cliente:</b> {orc.cliente}</div>}
            {orc.veiculo && <div><b style={{ color: 'var(--text)' }}>Veículo:</b> {orc.veiculo}</div>}
            {orc.problema && <div><b style={{ color: 'var(--text)' }}>Problema:</b> {orc.problema}</div>}
          </div>

          <h3 className="font-display" style={{ fontSize: 16, fontWeight: 800, margin: '4px 0 8px' }}>
            Serviços — selecione os que deseja aprovar
          </h3>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 20 }}>
            {orc.servicos.map(s => (
              <label key={s.id} style={{
                display: 'flex', alignItems: 'center', gap: 12, padding: '12px 14px', cursor: 'pointer',
                borderRadius: 9, border: `1px solid ${selecionados[s.id] ? 'var(--accent)' : 'var(--border)'}`,
                background: selecionados[s.id] ? 'rgba(245,166,35,.06)' : 'transparent',
              }}>
                <input type="checkbox" checked={!!selecionados[s.id]}
                  onChange={e => setSelecionados(p => ({ ...p, [s.id]: e.target.checked }))}
                  style={{ width: 18, height: 18, accentColor: 'var(--accent)' }} />
                <span style={{ flex: 1, fontSize: 14 }}>{s.descricao}</span>
                <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 14 }}>{brl(Number(s.valor_total))}</span>
              </label>
            ))}
            {orc.servicos.length === 0 && <p style={{ color: 'var(--muted)', fontSize: 14 }}>Nenhum serviço no orçamento.</p>}
          </div>

          {orc.pecas.length > 0 && (
            <>
              <h3 className="font-display" style={{ fontSize: 16, fontWeight: 800, margin: '4px 0 8px' }}>
                Peças — selecione as que deseja aprovar
              </h3>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 20 }}>
                {orc.pecas.map(p => (
                  <label key={p.id} style={{
                    display: 'flex', alignItems: 'center', gap: 12, padding: '12px 14px', cursor: 'pointer',
                    borderRadius: 9, border: `1px solid ${selecionados[p.id] ? 'var(--accent)' : 'var(--border)'}`,
                    background: selecionados[p.id] ? 'rgba(245,166,35,.06)' : 'transparent',
                  }}>
                    <input type="checkbox" checked={!!selecionados[p.id]}
                      onChange={e => setSelecionados(prev => ({ ...prev, [p.id]: e.target.checked }))}
                      style={{ width: 18, height: 18, accentColor: 'var(--accent)' }} />
                    <span style={{ flex: 1, fontSize: 14 }}>{p.descricao} {p.quantidade > 1 ? `(x${p.quantidade})` : ''}</span>
                    <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 14 }}>{brl(Number(p.valor_total))}</span>
                  </label>
                ))}
              </div>
            </>
          )}

          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '14px 0', borderTop: '1px solid var(--border)' }}>
            <span style={{ fontSize: 14, color: 'var(--muted)' }}>Total selecionado</span>
            <span className="font-display" style={{ fontSize: 22, fontWeight: 800, color: 'var(--accent)' }}>{brl(totalSelecionado)}</span>
          </div>

          <button onClick={enviar} disabled={enviando}
            style={{ width: '100%', marginTop: 12, padding: '13px', borderRadius: 9, border: 'none',
              background: 'var(--success)', color: '#fff', fontSize: 16, fontWeight: 800,
              cursor: enviando ? 'not-allowed' : 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
            {enviando ? '⟳ Enviando...' : 'Confirmar aprovação'}
          </button>
          <p style={{ fontSize: 11, color: 'var(--muted)', textAlign: 'center', marginTop: 10 }}>
            Desmarque os serviços e peças que não deseja aprovar. Itens não selecionados serão registrados como recusados.
          </p>
        </div>
      </div>
    </div>
  )
}
