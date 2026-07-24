'use client'
import { useState, useEffect, useCallback, Fragment } from 'react'
import saasApi from '@/lib/saas-api'
import { NotificacaoCard } from '@/components/NotificacaoCard'
import { NotificacaoLogInline } from '@/components/saas/NotificacaoLogInline'
import { NotificacaoCobrancaTable } from '@/components/saas/NotificacaoCobrancaTable'

interface Notif {
  id: string
  titulo: string
  subtitulo: string | null
  texto: string
  imagem: string | null
  alvo_tipo: 'TODOS' | 'PLANO' | 'OFICINAS'
  plano_id: string | null
  oficina_ids: string[]
  vezes_dia: number
  intervalo_minutos: number
  data_inicio: string | null
  data_fim: string | null
  ativo: boolean
  total_visualizacoes?: number
  oficinas_distintas?: number
}
interface Opt { id: string; nome: string }

const lbl: React.CSSProperties = { display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }
const inp: React.CSSProperties = { width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }

const VAZIO: Notif = {
  id: '', titulo: '', subtitulo: '', texto: '', imagem: null,
  alvo_tipo: 'TODOS', plano_id: null, oficina_ids: [],
  vezes_dia: 1, intervalo_minutos: 1440, data_inicio: null, data_fim: null, ativo: true,
}

function Modal({ inicial, planos, oficinas, onClose, onSaved }: {
  inicial: Notif; planos: Opt[]; oficinas: Opt[]; onClose: () => void; onSaved: () => void
}) {
  const [f, setF] = useState<Notif>(inicial)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState<string | null>(null)

  function set<K extends keyof Notif>(k: K, v: Notif[K]) { setF(p => ({ ...p, [k]: v })) }

  function onImg(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (!file) return
    if (file.size > 1_800_000) { setErr('Imagem muito grande (máx ~1.8MB).'); return }
    const reader = new FileReader()
    reader.onload = () => set('imagem', reader.result as string)
    reader.readAsDataURL(file)
  }

  async function salvar() {
    if (!f.titulo.trim() || !f.texto.trim()) { setErr('Título e texto são obrigatórios.'); return }
    setErr(null); setSaving(true)
    const payload = {
      titulo: f.titulo, subtitulo: f.subtitulo || null, texto: f.texto, imagem: f.imagem,
      alvo_tipo: f.alvo_tipo, plano_id: f.alvo_tipo === 'PLANO' ? f.plano_id : null,
      oficina_ids: f.alvo_tipo === 'OFICINAS' ? f.oficina_ids : [],
      vezes_dia: f.vezes_dia, intervalo_minutos: f.intervalo_minutos,
      data_inicio: f.data_inicio || null, data_fim: f.data_fim || null,
    }
    try {
      if (f.id) await saasApi.put(`/saas/notificacoes/${f.id}`, payload)
      else await saasApi.post('/saas/notificacoes', payload)
      onSaved(); onClose()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      setErr(msg ?? 'Erro ao salvar.')
    } finally { setSaving(false) }
  }

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.6)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, width: 560, maxWidth: '100%', maxHeight: '92vh', overflowY: 'auto', padding: 28 }}>
        <h2 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: '0 0 18px' }}>
          {f.id ? 'Editar Notificação' : 'Nova Notificação'}
        </h2>
        {err && <div style={{ background: 'rgba(229,57,53,.1)', border: '1px solid var(--danger)', color: 'var(--danger)', borderRadius: 8, padding: '10px 14px', fontSize: 13, marginBottom: 16 }}>{err}</div>}

        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <div><label style={lbl}>Título *</label><input style={inp} value={f.titulo} onChange={e => set('titulo', e.target.value)} /></div>
          <div><label style={lbl}>Subtítulo</label><input style={inp} value={f.subtitulo ?? ''} onChange={e => set('subtitulo', e.target.value)} /></div>
          <div><label style={lbl}>Texto *</label><textarea style={{ ...inp, resize: 'vertical' }} rows={4} value={f.texto} onChange={e => set('texto', e.target.value)} /></div>

          <div>
            <label style={lbl}>Imagem (opcional)</label>
            <input type="file" accept="image/*" onChange={onImg} style={{ fontSize: 13, color: 'var(--muted)' }} />
            {f.imagem && (
              <div style={{ marginTop: 8, position: 'relative', display: 'inline-block' }}>
                <img src={f.imagem} alt="" style={{ maxHeight: 90, borderRadius: 8, border: '1px solid var(--border)' }} />
                <button type="button" onClick={() => set('imagem', null)} style={{ marginLeft: 10, background: 'none', border: '1px solid var(--danger)', color: 'var(--danger)', borderRadius: 6, padding: '2px 8px', cursor: 'pointer', fontSize: 12 }}>remover</button>
              </div>
            )}
          </div>

          <div>
            <label style={lbl}>Direcionar para</label>
            <select style={inp} value={f.alvo_tipo} onChange={e => set('alvo_tipo', e.target.value as Notif['alvo_tipo'])}>
              <option value="TODOS">Todas as oficinas</option>
              <option value="PLANO">Oficinas de um plano</option>
              <option value="OFICINAS">Oficinas específicas</option>
            </select>
          </div>

          {f.alvo_tipo === 'PLANO' && (
            <div>
              <label style={lbl}>Plano</label>
              <select style={inp} value={f.plano_id ?? ''} onChange={e => set('plano_id', e.target.value || null)}>
                <option value="">Selecione...</option>
                {planos.map(p => <option key={p.id} value={p.id}>{p.nome}</option>)}
              </select>
            </div>
          )}

          {f.alvo_tipo === 'OFICINAS' && (
            <div>
              <label style={lbl}>Oficinas</label>
              <div style={{ maxHeight: 140, overflowY: 'auto', border: '1px solid var(--border)', borderRadius: 7, padding: 8 }}>
                {oficinas.map(o => (
                  <label key={o.id} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '4px 2px', cursor: 'pointer', fontSize: 14, color: 'var(--text)' }}>
                    <input type="checkbox" checked={f.oficina_ids.includes(o.id)}
                      onChange={e => set('oficina_ids', e.target.checked ? [...f.oficina_ids, o.id] : f.oficina_ids.filter(x => x !== o.id))}
                      style={{ accentColor: 'var(--accent)' }} />
                    {o.nome}
                  </label>
                ))}
                {oficinas.length === 0 && <span style={{ color: 'var(--muted)', fontSize: 13 }}>Nenhuma oficina.</span>}
              </div>
            </div>
          )}

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div><label style={lbl}>Vezes por dia</label><input type="number" min={1} style={inp} value={f.vezes_dia} onChange={e => set('vezes_dia', parseInt(e.target.value) || 1)} /></div>
            <div><label style={lbl}>Intervalo (min)</label><input type="number" min={1} style={inp} value={f.intervalo_minutos} onChange={e => set('intervalo_minutos', parseInt(e.target.value) || 1)} /></div>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div><label style={lbl}>Início (opcional)</label><input type="date" style={inp} value={f.data_inicio ?? ''} onChange={e => set('data_inicio', e.target.value || null)} /></div>
            <div><label style={lbl}>Fim (opcional)</label><input type="date" style={inp} value={f.data_fim ?? ''} onChange={e => set('data_fim', e.target.value || null)} /></div>
          </div>
        </div>

        <div style={{ display: 'flex', gap: 12, justifyContent: 'flex-end', marginTop: 22 }}>
          <button onClick={onClose} style={{ padding: '9px 20px', borderRadius: 8, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>Cancelar</button>
          <button onClick={salvar} disabled={saving} style={{ padding: '9px 24px', borderRadius: 8, background: 'var(--accent)', border: 'none', color: '#000', fontWeight: 700, cursor: saving ? 'not-allowed' : 'pointer', fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif" }}>
            {saving ? '⟳ Salvando...' : 'Salvar'}
          </button>
        </div>
      </div>
    </div>
  )
}

export default function NotificacoesPage() {
  const [lista, setLista] = useState<Notif[]>([])
  const [planos, setPlanos] = useState<Opt[]>([])
  const [oficinas, setOficinas] = useState<Opt[]>([])
  const [editando, setEditando] = useState<Notif | null>(null)
  const [loading, setLoading] = useState(true)
  const [previewing, setPreviewing] = useState<Notif | null>(null)
  const [togglingId, setTogglingId] = useState<string | null>(null)
  const [aba, setAba] = useState<'manuais' | 'cobranca'>('manuais')
  const [expandido, setExpandido] = useState<string | null>(null)

  const carregar = useCallback(() => {
    setLoading(true)
    saasApi.get<{ data: Notif[] }>('/saas/notificacoes')
      .then(r => setLista(r.data.data ?? []))
      .catch(() => setLista([]))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    carregar()
    saasApi.get<{ data: Opt[] }>('/saas/planos').then(r => setPlanos((r.data.data ?? []).map(p => ({ id: p.id, nome: p.nome })))).catch(() => {})
    saasApi.get<{ data: Opt[] }>('/saas/oficinas').then(r => setOficinas((r.data.data ?? []).map(o => ({ id: o.id, nome: o.nome })))).catch(() => {})
  }, [carregar])

  async function remover(id: string) {
    if (!confirm('Remover esta notificação?')) return
    try { await saasApi.delete(`/saas/notificacoes/${id}`); carregar() } catch { /* ignore */ }
  }

  async function alternarAtivo(n: Notif) {
    setTogglingId(n.id)
    try {
      await saasApi.patch(`/saas/notificacoes/${n.id}/ativo`, { ativo: !n.ativo })
      carregar()
    } catch { /* ignore */ }
    finally { setTogglingId(null) }
  }

  const ALVO: Record<string, string> = { TODOS: 'Todas', PLANO: 'Por plano', OFICINAS: 'Específicas' }

  return (
    <div style={{ padding: '32px', maxWidth: 1100, margin: '0 auto', color: 'var(--text)' }}>
      {editando && <Modal inicial={editando} planos={planos} oficinas={oficinas} onClose={() => setEditando(null)} onSaved={carregar} />}

      {previewing && (
        <NotificacaoCard
          notificacao={{ titulo: previewing.titulo, subtitulo: previewing.subtitulo, texto: previewing.texto, imagem: previewing.imagem }}
          onFechar={() => setPreviewing(null)}
        />
      )}

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: 24 }}>
        <div>
          <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, margin: 0 }}>Notificações</h1>
          <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>Avisos exibidos às oficinas ao acessar o sistema</p>
        </div>
        {aba === 'manuais' && (
          <button onClick={() => setEditando({ ...VAZIO })} style={{ background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 8, padding: '10px 22px', fontSize: 14, fontWeight: 700, cursor: 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
            + Nova Notificação
          </button>
        )}
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 20 }}>
        {(['manuais', 'cobranca'] as const).map(a => (
          <button key={a} onClick={() => setAba(a)}
            style={{
              padding: '8px 18px', borderRadius: 8, cursor: 'pointer', fontSize: 14, fontWeight: 700,
              fontFamily: "'Barlow Condensed', sans-serif",
              background: aba === a ? 'var(--accent)' : 'transparent',
              color: aba === a ? '#000' : 'var(--muted)',
              border: `1px solid ${aba === a ? 'var(--accent)' : 'var(--border)'}`,
            }}>
            {a === 'manuais' ? 'Manuais' : 'Cobrança'}
          </button>
        ))}
      </div>

      {aba === 'cobranca' && <NotificacaoCobrancaTable />}
      {aba === 'manuais' && (
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead>
            <tr>
              {['Título', 'Direcionamento', 'Freq.', 'Leituras', 'Status', 'Ações'].map(c => (
                <th key={c} style={{ padding: '11px 16px', textAlign: 'left', fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', borderBottom: '1px solid var(--border)', background: 'var(--surface)' }}>{c}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={6} style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Carregando...</td></tr>
            ) : lista.length === 0 ? (
              <tr><td colSpan={6} style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Nenhuma notificação cadastrada.</td></tr>
            ) : lista.map((n, i) => (
              <Fragment key={n.id}>
                <tr style={{ borderBottom: i < lista.length - 1 && expandido !== n.id ? '1px solid var(--border)' : undefined }}>
                  <td style={{ padding: '12px 16px' }}>
                    <div style={{ fontWeight: 600 }}>{n.titulo}</div>
                    {n.subtitulo && <div style={{ fontSize: 12, color: 'var(--muted)' }}>{n.subtitulo}</div>}
                  </td>
                  <td style={{ padding: '12px 16px', fontSize: 13, color: 'var(--muted)' }}>{ALVO[n.alvo_tipo]}</td>
                  <td style={{ padding: '12px 16px', fontSize: 13, color: 'var(--muted)' }}>{n.vezes_dia}x/dia · {n.intervalo_minutos}min</td>
                  <td style={{ padding: '12px 16px' }}>
                    <button onClick={() => setExpandido(expandido === n.id ? null : n.id)}
                      style={{ padding: '5px 12px', borderRadius: 6, background: 'rgba(30,136,229,.1)', border: '1px solid rgba(30,136,229,.3)', color: 'var(--info)', cursor: 'pointer', fontSize: 13 }}>
                      {n.total_visualizacoes ?? 0} · {n.oficinas_distintas ?? 0} oficina(s) {expandido === n.id ? '▲' : '▼'}
                    </button>
                  </td>
                  <td style={{ padding: '12px 16px' }}>
                    <span className={`pill ${n.ativo ? 'pill-success' : 'pill-muted'}`}>{n.ativo ? 'Publicada' : 'Rascunho'}</span>
                  </td>
                  <td style={{ padding: '12px 16px' }}>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                      <button onClick={() => setPreviewing(n)} style={{ padding: '5px 12px', borderRadius: 6, background: 'rgba(30,136,229,.1)', border: '1px solid rgba(30,136,229,.3)', color: 'var(--info)', cursor: 'pointer', fontSize: 13 }}>👁 Visualizar</button>
                      <button onClick={() => alternarAtivo(n)} disabled={togglingId === n.id} style={{ padding: '5px 12px', borderRadius: 6, background: n.ativo ? 'rgba(122,128,144,.15)' : 'rgba(67,160,71,.1)', border: `1px solid ${n.ativo ? 'var(--border)' : 'rgba(67,160,71,.3)'}`, color: n.ativo ? 'var(--muted)' : 'var(--success)', cursor: togglingId === n.id ? 'not-allowed' : 'pointer', fontSize: 13 }}>
                        {togglingId === n.id ? '⟳' : n.ativo ? 'Despublicar' : 'Publicar'}
                      </button>
                      <button onClick={() => setEditando(n)} style={{ padding: '5px 12px', borderRadius: 6, background: 'rgba(245,166,35,.1)', border: '1px solid rgba(245,166,35,.3)', color: 'var(--accent)', cursor: 'pointer', fontSize: 13 }}>Editar</button>
                      <button onClick={() => remover(n.id)} style={{ padding: '5px 12px', borderRadius: 6, background: 'rgba(229,57,53,.1)', border: '1px solid rgba(229,57,53,.3)', color: 'var(--danger)', cursor: 'pointer', fontSize: 13 }}>🗑</button>
                    </div>
                  </td>
                </tr>
                {expandido === n.id && (
                  <NotificacaoLogInline endpoint={`/saas/notificacoes/${n.id}/log`} mostrarOficina colSpan={6} />
                )}
              </Fragment>
            ))}
          </tbody>
        </table>
      </div>
      )}
    </div>
  )
}
