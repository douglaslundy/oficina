'use client'
import { useState, useEffect, useCallback } from 'react'
import saasApi from '@/lib/saas-api'

const SERVICO_LABEL: Record<string, string> = {
  ALERTA_WHATSAPP: 'Alertas WhatsApp',
  ALERTA_EMAIL: 'Alertas E-mail',
  ORCAMENTO: 'Orçamentos',
}

interface Grant {
  id: string
  servico: string
  quantidade: number
  valor_adicional: string
  recorrente: boolean
  data_inicio: string
  data_fim: string | null
  ativo: boolean
}
interface Pacote {
  id: string
  nome: string
  servico: string
  quantidade: number
  valor: string
  recorrente: boolean
  periodo_dias: number | null
}

const inp: React.CSSProperties = { padding: '8px 10px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg)', color: 'var(--text)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }

function brl(v: number | string) {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(parseFloat(String(v)) || 0)
}

export function ServicosAvulsosSection({ oficinaId }: { oficinaId: string }) {
  const [grants, setGrants] = useState<Grant[]>([])
  const [pacotes, setPacotes] = useState<Pacote[]>([])
  const [modo, setModo] = useState<'PACOTE' | 'PERSONALIZADO'>('PACOTE')
  const [pacoteId, setPacoteId] = useState('')
  const [cservico, setCservico] = useState('ALERTA_WHATSAPP')
  const [cqtd, setCqtd] = useState('200')
  const [cvalor, setCvalor] = useState('0')
  const [crecorrente, setCrecorrente] = useState(true)
  const [cperiodo, setCperiodo] = useState('30')
  const [saving, setSaving] = useState(false)
  const [msg, setMsg] = useState<string | null>(null)

  const carregar = useCallback(() => {
    saasApi.get<{ data: Grant[] }>(`/saas/oficinas/${oficinaId}/servicos`).then(r => setGrants(r.data.data ?? [])).catch(() => setGrants([]))
  }, [oficinaId])

  useEffect(() => {
    carregar()
    saasApi.get<{ data: Pacote[] }>('/saas/pacotes').then(r => {
      const ativos = (r.data.data ?? [])
      setPacotes(ativos)
      if (ativos.length > 0) setPacoteId(ativos[0].id)
    }).catch(() => {})
  }, [carregar])

  async function liberar() {
    setMsg(null); setSaving(true)
    const payload = modo === 'PACOTE'
      ? { pacote_id: pacoteId }
      : { servico: cservico, quantidade: parseInt(cqtd), valor_adicional: parseFloat(cvalor) || 0, recorrente: crecorrente, periodo_dias: crecorrente ? null : parseInt(cperiodo) }
    try {
      await saasApi.post(`/saas/oficinas/${oficinaId}/servicos`, payload)
      carregar()
    } catch (e: unknown) {
      setMsg((e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao liberar serviço.')
    } finally { setSaving(false) }
  }

  async function remover(id: string) {
    if (!confirm('Remover este serviço liberado?')) return
    try { await saasApi.delete(`/saas/oficinas/${oficinaId}/servicos/${id}`); carregar() } catch { /* ignore */ }
  }

  return (
    <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: 24, marginTop: 24 }}>
      <h3 className="font-display" style={{ fontSize: 17, fontWeight: 800, color: 'var(--text)', margin: '0 0 4px' }}>Serviços avulsos liberados</h3>
      <p style={{ color: 'var(--muted)', fontSize: 13, margin: '0 0 18px' }}>Libere serviços além do plano. O valor adicional entra na mensalidade.</p>

      {msg && <div style={{ background: 'rgba(229,57,53,.1)', border: '1px solid var(--danger)', color: 'var(--danger)', borderRadius: 8, padding: '8px 12px', fontSize: 13, marginBottom: 14 }}>{msg}</div>}

      {/* Form de liberação */}
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, alignItems: 'flex-end', marginBottom: 18, paddingBottom: 18, borderBottom: '1px solid var(--border)' }}>
        <div>
          <label style={{ display: 'block', fontSize: 11, color: 'var(--muted)', marginBottom: 4 }}>Origem</label>
          <select style={inp} value={modo} onChange={e => setModo(e.target.value as 'PACOTE' | 'PERSONALIZADO')}>
            <option value="PACOTE">Pacote cadastrado</option>
            <option value="PERSONALIZADO">Personalizado</option>
          </select>
        </div>

        {modo === 'PACOTE' ? (
          <div style={{ flex: 1, minWidth: 200 }}>
            <label style={{ display: 'block', fontSize: 11, color: 'var(--muted)', marginBottom: 4 }}>Pacote</label>
            <select style={{ ...inp, width: '100%' }} value={pacoteId} onChange={e => setPacoteId(e.target.value)}>
              {pacotes.length === 0 && <option value="">Nenhum pacote — cadastre em Pacotes</option>}
              {pacotes.map(p => (
                <option key={p.id} value={p.id}>
                  {p.nome} · {SERVICO_LABEL[p.servico]} · {p.quantidade < 0 ? 'ilimitado' : p.quantidade + '/mês'} · {brl(p.valor)} {p.recorrente ? '(recorrente)' : `(${p.periodo_dias}d)`}
                </option>
              ))}
            </select>
          </div>
        ) : (
          <>
            <div>
              <label style={{ display: 'block', fontSize: 11, color: 'var(--muted)', marginBottom: 4 }}>Serviço</label>
              <select style={inp} value={cservico} onChange={e => setCservico(e.target.value)}>
                {Object.entries(SERVICO_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </div>
            <div><label style={{ display: 'block', fontSize: 11, color: 'var(--muted)', marginBottom: 4 }}>Qtd/mês (-1=ilim.)</label><input style={{ ...inp, width: 110 }} type="number" value={cqtd} onChange={e => setCqtd(e.target.value)} /></div>
            <div><label style={{ display: 'block', fontSize: 11, color: 'var(--muted)', marginBottom: 4 }}>Valor (R$)</label><input style={{ ...inp, width: 100 }} type="number" step="0.01" value={cvalor} onChange={e => setCvalor(e.target.value)} /></div>
            <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, color: 'var(--text)', cursor: 'pointer' }}>
              <input type="checkbox" checked={crecorrente} onChange={e => setCrecorrente(e.target.checked)} style={{ accentColor: 'var(--accent)' }} /> Recorrente
            </label>
            {!crecorrente && <div><label style={{ display: 'block', fontSize: 11, color: 'var(--muted)', marginBottom: 4 }}>Dias</label><input style={{ ...inp, width: 80 }} type="number" value={cperiodo} onChange={e => setCperiodo(e.target.value)} /></div>}
          </>
        )}

        <button onClick={liberar} disabled={saving || (modo === 'PACOTE' && !pacoteId)}
          style={{ padding: '9px 18px', borderRadius: 7, background: 'var(--accent)', border: 'none', color: '#000', fontWeight: 700, fontSize: 13, cursor: saving ? 'not-allowed' : 'pointer' }}>
          {saving ? '⟳...' : '+ Liberar'}
        </button>
      </div>

      {/* Lista de grants */}
      {grants.length === 0 ? (
        <p style={{ color: 'var(--muted)', fontSize: 13 }}>Nenhum serviço avulso liberado.</p>
      ) : (
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <tbody>
            {grants.map(g => (
              <tr key={g.id} style={{ borderBottom: '1px solid var(--border)' }}>
                <td style={{ padding: '8px 4px', fontWeight: 600, fontSize: 14 }}>{SERVICO_LABEL[g.servico] ?? g.servico}</td>
                <td style={{ padding: '8px 4px', fontSize: 13, color: 'var(--muted)' }}>{g.quantidade < 0 ? 'Ilimitado' : `${g.quantidade}/mês`}</td>
                <td style={{ padding: '8px 4px', fontSize: 13, color: 'var(--accent)', fontFamily: "'JetBrains Mono', monospace" }}>{brl(g.valor_adicional)}</td>
                <td style={{ padding: '8px 4px', fontSize: 13, color: 'var(--muted)' }}>{g.recorrente ? 'Recorrente' : `até ${g.data_fim}`}</td>
                <td style={{ padding: '8px 4px', textAlign: 'right' }}>
                  <button onClick={() => remover(g.id)} style={{ background: 'none', border: '1px solid var(--danger)', color: 'var(--danger)', borderRadius: 6, padding: '3px 10px', fontSize: 12, cursor: 'pointer' }}>Remover</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
