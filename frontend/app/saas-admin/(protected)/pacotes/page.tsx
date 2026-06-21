'use client'
import { useState, useEffect, useCallback } from 'react'
import saasApi from '@/lib/saas-api'

interface Pacote {
  id: string
  nome: string
  servico: string
  quantidade: number
  valor: string
  recorrente: boolean
  periodo_dias: number | null
  ativo: boolean
}

export const SERVICO_LABEL: Record<string, string> = {
  ALERTA_WHATSAPP: 'Alertas WhatsApp',
  ALERTA_EMAIL: 'Alertas E-mail',
  ORCAMENTO: 'Orçamentos',
}

const lbl: React.CSSProperties = { display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }
const inp: React.CSSProperties = { width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }

function brl(v: number | string) {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(parseFloat(String(v)) || 0)
}

const VAZIO: Pacote = { id: '', nome: '', servico: 'ALERTA_WHATSAPP', quantidade: 200, valor: '0', recorrente: true, periodo_dias: 30, ativo: true }

function Modal({ inicial, onClose, onSaved }: { inicial: Pacote; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState<Pacote>(inicial)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  function set<K extends keyof Pacote>(k: K, v: Pacote[K]) { setF(p => ({ ...p, [k]: v })) }

  async function salvar() {
    if (!f.nome.trim()) { setErr('Informe o nome do pacote.'); return }
    setErr(null); setSaving(true)
    const payload = {
      nome: f.nome, servico: f.servico, quantidade: f.quantidade,
      valor: parseFloat(f.valor) || 0, recorrente: f.recorrente,
      periodo_dias: f.recorrente ? null : (f.periodo_dias || 30), ativo: f.ativo,
    }
    try {
      if (f.id) await saasApi.put(`/saas/pacotes/${f.id}`, payload)
      else await saasApi.post('/saas/pacotes', payload)
      onSaved(); onClose()
    } catch (e: unknown) {
      setErr((e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao salvar.')
    } finally { setSaving(false) }
  }

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.6)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, width: 480, maxWidth: '100%', padding: 28 }}>
        <h2 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: '0 0 18px' }}>{f.id ? 'Editar Pacote' : 'Novo Pacote'}</h2>
        {err && <div style={{ background: 'rgba(229,57,53,.1)', border: '1px solid var(--danger)', color: 'var(--danger)', borderRadius: 8, padding: '10px 14px', fontSize: 13, marginBottom: 16 }}>{err}</div>}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <div><label style={lbl}>Nome do pacote</label><input style={inp} value={f.nome} onChange={e => set('nome', e.target.value)} placeholder="Ex: 200 alertas WhatsApp/mês" /></div>
          <div><label style={lbl}>Serviço</label>
            <select style={inp} value={f.servico} onChange={e => set('servico', e.target.value)}>
              {Object.entries(SERVICO_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div><label style={lbl}>Qtd/mês (-1 = ilimitado)</label><input type="number" style={inp} value={f.quantidade} onChange={e => set('quantidade', parseInt(e.target.value))} /></div>
            <div><label style={lbl}>Valor adicional (R$)</label><input type="number" step="0.01" min="0" style={inp} value={f.valor} onChange={e => set('valor', e.target.value)} /></div>
          </div>
          <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', fontSize: 14, color: 'var(--text)' }}>
            <input type="checkbox" checked={f.recorrente} onChange={e => set('recorrente', e.target.checked)} style={{ width: 16, height: 16, accentColor: 'var(--accent)' }} />
            Recorrente (sem vencimento)
          </label>
          {!f.recorrente && (
            <div><label style={lbl}>Período (dias)</label><input type="number" min="1" style={inp} value={f.periodo_dias ?? 30} onChange={e => set('periodo_dias', parseInt(e.target.value) || 30)} /></div>
          )}
          <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', fontSize: 14, color: 'var(--text)' }}>
            <input type="checkbox" checked={f.ativo} onChange={e => set('ativo', e.target.checked)} style={{ width: 16, height: 16, accentColor: 'var(--accent)' }} />
            Ativo
          </label>
        </div>
        <div style={{ display: 'flex', gap: 12, justifyContent: 'flex-end', marginTop: 22 }}>
          <button onClick={onClose} style={{ padding: '9px 20px', borderRadius: 8, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>Cancelar</button>
          <button onClick={salvar} disabled={saving} style={{ padding: '9px 24px', borderRadius: 8, background: 'var(--accent)', border: 'none', color: '#000', fontWeight: 700, cursor: saving ? 'not-allowed' : 'pointer', fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif" }}>{saving ? '⟳ Salvando...' : 'Salvar'}</button>
        </div>
      </div>
    </div>
  )
}

export default function PacotesPage() {
  const [lista, setLista] = useState<Pacote[]>([])
  const [loading, setLoading] = useState(true)
  const [editando, setEditando] = useState<Pacote | null>(null)

  const carregar = useCallback(() => {
    setLoading(true)
    saasApi.get<{ data: Pacote[] }>('/saas/pacotes').then(r => setLista(r.data.data ?? [])).catch(() => setLista([])).finally(() => setLoading(false))
  }, [])
  useEffect(() => { carregar() }, [carregar])

  async function remover(id: string) {
    if (!confirm('Remover este pacote?')) return
    try { await saasApi.delete(`/saas/pacotes/${id}`); carregar() } catch { /* ignore */ }
  }

  return (
    <div style={{ padding: 32, maxWidth: 1000, margin: '0 auto', color: 'var(--text)' }}>
      {editando && <Modal inicial={editando} onClose={() => setEditando(null)} onSaved={carregar} />}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: 24 }}>
        <div>
          <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, margin: 0 }}>Pacotes de Serviço</h1>
          <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>Serviços avulsos que podem ser liberados às oficinas além do plano</p>
        </div>
        <button onClick={() => setEditando({ ...VAZIO })} style={{ background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 8, padding: '10px 22px', fontSize: 14, fontWeight: 700, cursor: 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>+ Novo Pacote</button>
      </div>
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Nome', 'Serviço', 'Qtd/mês', 'Valor', 'Vigência', 'Status', 'Ações'].map(c => (
            <th key={c} style={{ padding: '11px 16px', textAlign: 'left', fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', borderBottom: '1px solid var(--border)', background: 'var(--surface)' }}>{c}</th>
          ))}</tr></thead>
          <tbody>
            {loading ? <tr><td colSpan={7} style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Carregando...</td></tr>
              : lista.length === 0 ? <tr><td colSpan={7} style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Nenhum pacote cadastrado.</td></tr>
              : lista.map((p, i) => (
                <tr key={p.id} style={{ borderBottom: i < lista.length - 1 ? '1px solid var(--border)' : undefined }}>
                  <td style={{ padding: '12px 16px', fontWeight: 600 }}>{p.nome}</td>
                  <td style={{ padding: '12px 16px', fontSize: 13, color: 'var(--muted)' }}>{SERVICO_LABEL[p.servico] ?? p.servico}</td>
                  <td style={{ padding: '12px 16px', fontFamily: "'JetBrains Mono', monospace" }}>{p.quantidade < 0 ? 'Ilimitado' : p.quantidade}</td>
                  <td style={{ padding: '12px 16px', fontFamily: "'JetBrains Mono', monospace", color: 'var(--accent)' }}>{brl(p.valor)}</td>
                  <td style={{ padding: '12px 16px', fontSize: 13, color: 'var(--muted)' }}>{p.recorrente ? 'Recorrente' : `${p.periodo_dias} dias`}</td>
                  <td style={{ padding: '12px 16px' }}><span className={`pill ${p.ativo ? 'pill-success' : 'pill-muted'}`}>{p.ativo ? 'Ativo' : 'Inativo'}</span></td>
                  <td style={{ padding: '12px 16px' }}>
                    <div style={{ display: 'flex', gap: 8 }}>
                      <button onClick={() => setEditando(p)} style={{ padding: '5px 12px', borderRadius: 6, background: 'rgba(245,166,35,.1)', border: '1px solid rgba(245,166,35,.3)', color: 'var(--accent)', cursor: 'pointer', fontSize: 13 }}>Editar</button>
                      <button onClick={() => remover(p.id)} style={{ padding: '5px 12px', borderRadius: 6, background: 'rgba(229,57,53,.1)', border: '1px solid rgba(229,57,53,.3)', color: 'var(--danger)', cursor: 'pointer', fontSize: 13 }}>🗑</button>
                    </div>
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
