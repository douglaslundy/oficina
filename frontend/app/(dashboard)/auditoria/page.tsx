'use client'

import { useState, useEffect, useCallback } from 'react'
import api from '@/lib/api'

// ─── Types ────────────────────────────────────────────────────────────────────

interface AuditEntry {
  id: number
  evento: string | null
  descricao: string
  modelo: string
  subject_type: string
  subject_id: string | null
  causer_nome: string
  causer_id: string | null
  campos_alterados: number
  criado_em: string
}

interface DiffItem {
  campo: string
  antes: unknown
  depois: unknown
}

interface AuditDetail extends AuditEntry {
  diff: DiffItem[]
  propriedades: Record<string, unknown>
}

interface PaginationMeta {
  total: number
  per_page: number
  current_page: number
}

interface Usuario {
  id: string
  nome: string
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

const EVENTO_CONFIG: Record<string, { label: string; bg: string; color: string }> = {
  created: { label: 'Criado',    bg: 'rgba(67,160,71,.15)',  color: 'var(--success)' },
  updated: { label: 'Alterado',  bg: 'rgba(30,136,229,.15)', color: 'var(--info)'    },
  deleted: { label: 'Excluído',  bg: 'rgba(229,57,53,.15)',  color: 'var(--danger)'  },
}

function eventoCfg(evento: string | null) {
  return EVENTO_CONFIG[evento ?? ''] ?? { label: evento ?? '—', bg: 'rgba(122,128,144,.15)', color: 'var(--muted)' }
}

function fmtValor(v: unknown): string {
  if (v === null || v === undefined) return <span style={{ color: 'var(--muted)', fontStyle: 'italic' }}>null</span> as unknown as string
  if (typeof v === 'boolean') return v ? 'true' : 'false'
  return String(v)
}

function Sk({ w, h }: { w?: string | number; h?: number }) {
  return <div style={{ width: w ?? '100%', height: h ?? 14, borderRadius: 6, background: 'var(--border)', animation: 'pulse 1.4s ease-in-out infinite' }} />
}

const MODELOS = [
  { value: '', label: 'Todos os modelos' },
  { value: 'cliente',     label: 'Cliente' },
  { value: 'produto',     label: 'Produto' },
  { value: 'os',          label: 'Ordem de Serviço' },
  { value: 'nota_fiscal', label: 'Nota Fiscal' },
  { value: 'usuario',     label: 'Usuário' },
]

const EVENTOS = [
  { value: '', label: 'Todos os eventos' },
  { value: 'created', label: 'Criado' },
  { value: 'updated', label: 'Alterado' },
  { value: 'deleted', label: 'Excluído' },
]

// ─── DiffTable component ───────────────────────────────────────────────────────

function DiffTable({ diff }: { diff: DiffItem[] }) {
  if (diff.length === 0) return <p style={{ color: 'var(--muted)', fontSize: 13, margin: 0 }}>Sem alterações registradas.</p>
  return (
    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
      <thead>
        <tr>
          {['Campo', 'Antes', 'Depois'].map(h => (
            <th key={h} style={{ padding: '6px 10px', textAlign: 'left', fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', borderBottom: '1px solid var(--border)' }}>
              {h}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {diff.map((d, i) => (
          <tr key={i} style={{ borderBottom: i < diff.length - 1 ? '1px solid var(--border)' : 'none' }}>
            <td style={{ padding: '7px 10px', fontFamily: "'JetBrains Mono', monospace", fontSize: 12, color: 'var(--accent)', fontWeight: 600 }}>{d.campo}</td>
            <td style={{ padding: '7px 10px', fontFamily: "'JetBrains Mono', monospace", fontSize: 12, color: 'var(--danger)', maxWidth: 200, wordBreak: 'break-all' }}>
              {d.antes === null || d.antes === undefined
                ? <span style={{ color: 'var(--muted)', fontStyle: 'italic' }}>—</span>
                : String(d.antes)}
            </td>
            <td style={{ padding: '7px 10px', fontFamily: "'JetBrains Mono', monospace", fontSize: 12, color: 'var(--success)', maxWidth: 200, wordBreak: 'break-all' }}>
              {d.depois === null || d.depois === undefined
                ? <span style={{ color: 'var(--muted)', fontStyle: 'italic' }}>—</span>
                : String(d.depois)}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function AuditoriaPage() {
  const [entries, setEntries] = useState<AuditEntry[]>([])
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)

  const [usuarios, setUsuarios] = useState<Usuario[]>([])

  // Filters
  const [fUsuario, setFUsuario] = useState('')
  const [fModelo, setFModelo] = useState('')
  const [fEvento, setFEvento] = useState('')
  const [fDataInicio, setFDataInicio] = useState('')
  const [fDataFim, setFDataFim] = useState('')
  // Applied
  const [aUsuario, setAUsuario] = useState('')
  const [aModelo, setAModelo] = useState('')
  const [aEvento, setAEvento] = useState('')
  const [aDataInicio, setADataInicio] = useState('')
  const [aDataFim, setADataFim] = useState('')

  // Detail modal
  const [detail, setDetail] = useState<AuditDetail | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)

  useEffect(() => {
    api.get<{ data: Usuario[] }>('/usuarios').then(r => setUsuarios(r.data.data ?? [])).catch(() => {})
  }, [])

  const fetchEntries = useCallback(async (pg: number, u: string, m: string, ev: string, di: string, df: string) => {
    setLoading(true)
    try {
      const params: Record<string, string | number> = { page: pg }
      if (u)  params.usuario_id  = u
      if (m)  params.modelo      = m
      if (ev) params.evento      = ev
      if (di) params.data_inicio = di
      if (df) params.data_fim    = df
      const res = await api.get<{ data: AuditEntry[]; meta: PaginationMeta }>('/auditoria', { params })
      setEntries(res.data.data ?? [])
      setMeta(res.data.meta ?? null)
    } catch {
      setEntries([])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchEntries(page, aUsuario, aModelo, aEvento, aDataInicio, aDataFim)
  }, [fetchEntries, page, aUsuario, aModelo, aEvento, aDataInicio, aDataFim])

  function handleFiltrar() {
    setPage(1)
    setAUsuario(fUsuario)
    setAModelo(fModelo)
    setAEvento(fEvento)
    setADataInicio(fDataInicio)
    setADataFim(fDataFim)
  }

  function handleLimpar() {
    setFUsuario(''); setFModelo(''); setFEvento(''); setFDataInicio(''); setFDataFim('')
    setPage(1)
    setAUsuario(''); setAModelo(''); setAEvento(''); setADataInicio(''); setADataFim('')
  }

  async function openDetail(id: number) {
    setDetailLoading(true)
    setDetail(null)
    try {
      const res = await api.get<{ data: AuditDetail }>(`/auditoria/${id}`)
      setDetail(res.data.data)
    } catch {
      // ignore
    } finally {
      setDetailLoading(false)
    }
  }

  const totalPages = meta ? Math.ceil(meta.total / meta.per_page) : 1
  const inputStyle: React.CSSProperties = {
    background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8,
    color: 'var(--text)', fontSize: 14, padding: '8px 12px', outline: 'none',
  }
  const selectStyle: React.CSSProperties = {
    ...inputStyle, appearance: 'none', cursor: 'pointer',
    backgroundImage: "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%237a8090' d='M6 8L1 3h10z'/%3E%3C/svg%3E\")",
    backgroundRepeat: 'no-repeat', backgroundPosition: 'calc(100% - 10px) center', paddingRight: 32,
  }

  return (
    <>
      <style>{`@keyframes pulse{0%,100%{opacity:1}50%{opacity:.45}} @keyframes slideDown{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}`}</style>

      {/* Detail modal */}
      {(detail || detailLoading) && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.65)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000 }}
          onClick={() => { if (!detailLoading) setDetail(null) }}>
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, padding: 28, width: '100%', maxWidth: 580, maxHeight: '80vh', overflowY: 'auto', animation: 'slideDown 0.2s ease' }}
            onClick={e => e.stopPropagation()}>
            {detailLoading ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                {[...Array(6)].map((_, i) => <Sk key={i} h={16} />)}
              </div>
            ) : detail ? (
              <>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 20 }}>
                  <div>
                    <h3 className="font-display" style={{ fontSize: 18, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
                      Detalhe do Log #{detail.id}
                    </h3>
                    <p style={{ color: 'var(--muted)', fontSize: 13, margin: '4px 0 0' }}>
                      {detail.criado_em} · {detail.causer_nome}
                    </p>
                  </div>
                  <button onClick={() => setDetail(null)}
                    style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, padding: '4px 10px', fontSize: 16, cursor: 'pointer', lineHeight: 1 }}>
                    ×
                  </button>
                </div>

                {/* Meta grid */}
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, marginBottom: 20 }}>
                  {[
                    ['Evento', (() => {
                      const cfg = eventoCfg(detail.evento)
                      return <span style={{ padding: '2px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700, background: cfg.bg, color: cfg.color, textTransform: 'uppercase' }}>{cfg.label}</span>
                    })()],
                    ['Modelo', detail.modelo],
                    ['Registro ID', <span key="sid" style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 11, color: 'var(--muted)' }}>{detail.subject_id?.slice(0, 12)}…</span>],
                    ['Usuário', detail.causer_nome],
                  ].map(([label, value]) => (
                    <div key={String(label)} style={{ background: 'var(--surface)', borderRadius: 8, padding: '10px 12px' }}>
                      <p style={{ fontSize: 11, fontWeight: 600, color: 'var(--muted)', margin: '0 0 4px', textTransform: 'uppercase', letterSpacing: '0.05em' }}>{label}</p>
                      <div style={{ fontSize: 14, color: 'var(--text)' }}>{value as React.ReactNode}</div>
                    </div>
                  ))}
                </div>

                {/* Diff */}
                <div style={{ background: 'var(--surface)', borderRadius: 8, padding: 16 }}>
                  <p style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', margin: '0 0 12px', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                    Campos Alterados ({detail.diff.length})
                  </p>
                  <DiffTable diff={detail.diff} />
                </div>
              </>
            ) : null}
          </div>
        </div>
      )}

      <div style={{ padding: '24px 24px 48px', maxWidth: 1200, color: 'var(--text)' }}>
        <div style={{ marginBottom: 24 }}>
          <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, color: 'var(--text)', margin: 0 }}>Auditoria</h1>
          <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
            Registro de todas as ações realizadas no sistema
          </p>
        </div>

        {/* Filters */}
        <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: '16px 20px', marginBottom: 20, display: 'flex', flexWrap: 'wrap', gap: 14, alignItems: 'flex-end' }}>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 5 }}>
            <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)' }}>Usuário</label>
            <select value={fUsuario} onChange={e => setFUsuario(e.target.value)} style={{ ...selectStyle, minWidth: 180 }}>
              <option value="">Todos os usuários</option>
              {usuarios.map(u => <option key={u.id} value={u.id}>{u.nome}</option>)}
            </select>
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 5 }}>
            <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)' }}>Modelo</label>
            <select value={fModelo} onChange={e => setFModelo(e.target.value)} style={{ ...selectStyle, minWidth: 160 }}>
              {MODELOS.map(m => <option key={m.value} value={m.value}>{m.label}</option>)}
            </select>
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 5 }}>
            <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)' }}>Evento</label>
            <select value={fEvento} onChange={e => setFEvento(e.target.value)} style={{ ...selectStyle, minWidth: 140 }}>
              {EVENTOS.map(ev => <option key={ev.value} value={ev.value}>{ev.label}</option>)}
            </select>
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 5 }}>
            <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)' }}>De</label>
            <input type="date" value={fDataInicio} onChange={e => setFDataInicio(e.target.value)} style={{ ...inputStyle, minWidth: 140, colorScheme: 'dark' }} />
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 5 }}>
            <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)' }}>Até</label>
            <input type="date" value={fDataFim} onChange={e => setFDataFim(e.target.value)} style={{ ...inputStyle, minWidth: 140, colorScheme: 'dark' }} />
          </div>

          <div style={{ display: 'flex', gap: 8, alignSelf: 'flex-end' }}>
            <button onClick={handleFiltrar}
              style={{ padding: '9px 22px', background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 8, fontSize: 14, fontWeight: 700, cursor: 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
              Filtrar
            </button>
            <button onClick={handleLimpar}
              style={{ padding: '9px 18px', background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 8, fontSize: 14, cursor: 'pointer' }}>
              Limpar
            </button>
          </div>
        </div>

        {/* Stats bar */}
        {meta && !loading && (
          <div style={{ marginBottom: 16, display: 'flex', gap: 20 }}>
            <span style={{ fontSize: 13, color: 'var(--muted)' }}>
              <strong style={{ color: 'var(--text)' }}>{meta.total}</strong> registro{meta.total !== 1 ? 's' : ''} encontrado{meta.total !== 1 ? 's' : ''}
            </span>
          </div>
        )}

        {/* Table */}
        <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead>
                <tr>
                  {['Data/Hora', 'Usuário', 'Evento', 'Modelo', 'Campos', ''].map(h => (
                    <th key={h} style={{ padding: '11px 14px', textAlign: 'left', fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', borderBottom: '1px solid var(--border)', background: 'var(--surface)', whiteSpace: 'nowrap' }}>
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  Array.from({ length: 10 }).map((_, i) => (
                    <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                      {Array.from({ length: 6 }).map((_, j) => (
                        <td key={j} style={{ padding: '13px 14px' }}><Sk h={14} /></td>
                      ))}
                    </tr>
                  ))
                ) : entries.length === 0 ? (
                  <tr>
                    <td colSpan={6} style={{ padding: '52px 16px', textAlign: 'center', color: 'var(--muted)', fontSize: 14 }}>
                      Nenhum registro de auditoria encontrado.
                    </td>
                  </tr>
                ) : entries.map((entry, idx) => {
                  const cfg = eventoCfg(entry.evento)
                  return (
                    <tr key={entry.id} style={{ borderBottom: idx < entries.length - 1 ? '1px solid var(--border)' : 'none' }}
                      onMouseEnter={e => (e.currentTarget as HTMLTableRowElement).style.background = 'rgba(255,255,255,.02)'}
                      onMouseLeave={e => (e.currentTarget as HTMLTableRowElement).style.background = ''}>

                      {/* Data/Hora */}
                      <td style={{ padding: '11px 14px', whiteSpace: 'nowrap' }}>
                        <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 12, color: 'var(--muted)' }}>
                          {entry.criado_em}
                        </span>
                      </td>

                      {/* Usuário */}
                      <td style={{ padding: '11px 14px' }}>
                        <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--text)' }}>{entry.causer_nome}</span>
                      </td>

                      {/* Evento */}
                      <td style={{ padding: '11px 14px' }}>
                        <span style={{ padding: '2px 9px', borderRadius: 999, fontSize: 11, fontWeight: 700, background: cfg.bg, color: cfg.color, textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }}>
                          {cfg.label}
                        </span>
                      </td>

                      {/* Modelo */}
                      <td style={{ padding: '11px 14px' }}>
                        <div style={{ fontSize: 13, color: 'var(--text)' }}>{entry.modelo}</div>
                        {entry.subject_id && (
                          <div style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 10, color: 'var(--muted)', marginTop: 2 }}>
                            {entry.subject_id.slice(0, 12)}…
                          </div>
                        )}
                      </td>

                      {/* Campos alterados */}
                      <td style={{ padding: '11px 14px', textAlign: 'center' }}>
                        {entry.campos_alterados > 0 ? (
                          <span style={{ display: 'inline-block', background: 'rgba(30,136,229,.12)', color: 'var(--info)', borderRadius: 999, fontSize: 11, fontWeight: 700, padding: '2px 8px', minWidth: 28, textAlign: 'center' }}>
                            {entry.campos_alterados}
                          </span>
                        ) : (
                          <span style={{ color: 'var(--muted)', fontSize: 13 }}>—</span>
                        )}
                      </td>

                      {/* Ver detalhes */}
                      <td style={{ padding: '11px 14px' }}>
                        <button onClick={() => openDetail(entry.id)}
                          style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, padding: '4px 10px', fontSize: 12, cursor: 'pointer', whiteSpace: 'nowrap' }}>
                          Ver diff
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {meta && meta.total > 25 && (
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 20px', borderTop: '1px solid var(--border)', flexWrap: 'wrap', gap: 8 }}>
              <span style={{ fontSize: 13, color: 'var(--muted)' }}>Página {page} de {totalPages}</span>
              <div style={{ display: 'flex', gap: 8 }}>
                <button onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page <= 1 || loading}
                  style={{ background: 'none', border: '1px solid var(--border)', color: page <= 1 ? 'var(--muted)' : 'var(--text)', borderRadius: 8, padding: '7px 16px', fontSize: 13, cursor: page <= 1 ? 'not-allowed' : 'pointer', opacity: page <= 1 ? 0.5 : 1 }}>
                  Anterior
                </button>
                <button onClick={() => setPage(p => Math.min(totalPages, p + 1))} disabled={page >= totalPages || loading}
                  style={{ background: 'none', border: '1px solid var(--border)', color: page >= totalPages ? 'var(--muted)' : 'var(--text)', borderRadius: 8, padding: '7px 16px', fontSize: 13, cursor: page >= totalPages ? 'not-allowed' : 'pointer', opacity: page >= totalPages ? 0.5 : 1 }}>
                  Próxima
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </>
  )
}
