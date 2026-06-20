// frontend/app/(dashboard)/servicos/page.tsx
'use client'
import { useState, useEffect, useCallback } from 'react'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'
import { formatarMoeda } from '@/lib/formatters'

interface Servico {
  id: string
  nome: string
  valor_padrao: number
  ativo: boolean
}

interface ServicoForm {
  nome: string
  valor_padrao: string
}

const iStyle: React.CSSProperties = {
  width: '100%', padding: '9px 12px', borderRadius: 8,
  background: 'var(--bg)', border: '1px solid var(--border)',
  color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box',
}

function ServicoModal({
  mode,
  initial,
  onClose,
  onSuccess,
}: {
  mode: 'create' | 'edit'
  initial?: Servico
  onClose: () => void
  onSuccess: (mode: 'create' | 'edit') => void
}) {
  const [form, setForm] = useState<ServicoForm>({
    nome: initial?.nome ?? '',
    valor_padrao: initial ? String(initial.valor_padrao) : '',
  })
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError(null)
    if (!form.nome.trim()) { setError('Nome é obrigatório.'); return }
    const valorNum = parseFloat(form.valor_padrao)
    if (form.valor_padrao === '' || isNaN(valorNum) || valorNum < 0) {
      setError('Informe um valor padrão válido (mínimo R$ 0,00).'); return
    }
    setSubmitting(true)
    try {
      const payload = { nome: form.nome.trim(), valor_padrao: valorNum }
      if (mode === 'create') {
        await api.post('/servicos', payload)
      } else {
        await api.put(`/servicos/${initial!.id}`, payload)
      }
      onSuccess(mode)
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      setError(e.response?.data?.message ?? 'Erro ao salvar.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div
      onClick={e => { if (e.target === e.currentTarget) onClose() }}
      style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.6)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24 }}
    >
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, width: '100%', maxWidth: 400, padding: '28px 32px' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 24 }}>
          <h2 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
            {mode === 'create' ? 'Novo Serviço' : 'Editar Serviço'}
          </h2>
          <button onClick={onClose} style={{ background: 'none', border: 'none', color: 'var(--muted)', fontSize: 20, cursor: 'pointer' }}>✕</button>
        </div>

        {error && (
          <div style={{ background: 'rgba(229,57,53,.1)', border: '1px solid var(--danger)', borderRadius: 8, padding: '10px 14px', color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <div>
            <label style={{ color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }}>
              Nome <span style={{ color: 'var(--danger)' }}>*</span>
            </label>
            <input
              style={iStyle}
              value={form.nome}
              onChange={e => setForm(p => ({ ...p, nome: e.target.value }))}
              placeholder="Ex: Troca de Óleo"
              disabled={submitting}
            />
          </div>

          <div>
            <label style={{ color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }}>
              Valor padrão (R$) <span style={{ color: 'var(--danger)' }}>*</span>
            </label>
            <input
              style={iStyle}
              type="number"
              min="0"
              step="0.01"
              value={form.valor_padrao}
              onChange={e => setForm(p => ({ ...p, valor_padrao: e.target.value }))}
              placeholder="0.00"
              disabled={submitting}
            />
          </div>

          <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 8, paddingTop: 14, borderTop: '1px solid var(--border)' }}>
            <button type="button" onClick={onClose} disabled={submitting}
              style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 8, padding: '9px 20px', fontSize: 14, cursor: 'pointer' }}>
              Cancelar
            </button>
            <button type="submit" disabled={submitting}
              className="font-display"
              style={{ background: submitting ? 'rgba(245,166,35,.4)' : 'var(--accent)', color: '#000', border: 'none', borderRadius: 8, padding: '9px 24px', fontSize: 14, fontWeight: 700, cursor: submitting ? 'not-allowed' : 'pointer' }}>
              {submitting ? '⟳ Salvando...' : mode === 'create' ? 'Criar' : 'Salvar'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

export default function ServicosPage() {
  const [servicos, setServicos] = useState<Servico[]>([])
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [modalMode, setModalMode] = useState<'create' | 'edit'>('create')
  const [editTarget, setEditTarget] = useState<Servico | undefined>()
  const [confirmDeactivate, setConfirmDeactivate] = useState<string | null>(null)
  const [deactivating, setDeactivating] = useState(false)
  const [reactivating, setReactivating] = useState(false)

  const fetchServicos = useCallback(async () => {
    setLoading(true)
    try {
      const r = await api.get('/servicos?per_page=200')
      setServicos(r.data.data ?? [])
    } catch {
      toast('Erro ao carregar serviços.', 'danger')
    } finally { setLoading(false) }
  }, [])

  useEffect(() => { fetchServicos() }, [fetchServicos])

  async function handleDeactivate(id: string) {
    setDeactivating(true)
    try {
      await api.delete(`/servicos/${id}`)
      toast('Serviço desativado.', 'success')
      setConfirmDeactivate(null)
      fetchServicos()
    } catch {
      toast('Erro ao desativar.', 'danger')
    } finally {
      setDeactivating(false)
    }
  }

  async function handleReactivate(id: string) {
    setReactivating(true)
    try {
      await api.put(`/servicos/${id}`, { ativo: true })
      toast('Serviço reativado.', 'success')
      fetchServicos()
    } catch {
      toast('Erro ao reativar.', 'danger')
    } finally {
      setReactivating(false)
    }
  }

  function handleModalSuccess(resolvedMode: 'create' | 'edit') {
    setShowModal(false)
    toast(resolvedMode === 'create' ? 'Serviço criado!' : 'Serviço atualizado!', 'success')
    fetchServicos()
  }

  return (
    <div style={{ padding: '32px 32px 40px', maxWidth: 860, margin: '0 auto', color: 'var(--text)' }}>
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 28 }}>
        <div>
          <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, color: 'var(--text)', margin: 0 }}>Serviços</h1>
          <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>Catálogo de serviços disponíveis para OS</p>
        </div>
        <button
          onClick={() => { setModalMode('create'); setEditTarget(undefined); setShowModal(true) }}
          className="font-display"
          style={{ background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 8, padding: '10px 22px', fontSize: 14, fontWeight: 700, cursor: 'pointer' }}
        >
          + Novo Serviço
        </button>
      </div>

      {/* Table */}
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr>
                {['Nome', 'Valor Padrão', 'Status', 'Ações'].map(h => (
                  <th key={h} style={{ padding: '11px 16px', textAlign: 'left', fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', borderBottom: '1px solid var(--border)', background: 'var(--surface)', whiteSpace: 'nowrap' }}>
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                Array.from({ length: 4 }).map((_, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                    {[180, 100, 60, 120].map((w, j) => (
                      <td key={j} style={{ padding: '13px 16px' }}>
                        <div style={{ height: 14, borderRadius: 4, background: 'var(--border)', width: w, animation: 'pulse 1.4s ease-in-out infinite' }} />
                      </td>
                    ))}
                  </tr>
                ))
              ) : servicos.length === 0 ? (
                <tr>
                  <td colSpan={4} style={{ padding: '48px 16px', textAlign: 'center', color: 'var(--muted)', fontSize: 14 }}>
                    Nenhum serviço cadastrado. Clique em &quot;+ Novo Serviço&quot; para começar.
                  </td>
                </tr>
              ) : (
                servicos.map((s, idx) => (
                  <tr key={s.id}
                    style={{ borderBottom: idx === servicos.length - 1 ? 'none' : '1px solid var(--border)' }}
                    onMouseEnter={e => { (e.currentTarget as HTMLTableRowElement).style.background = 'rgba(255,255,255,.02)' }}
                    onMouseLeave={e => { (e.currentTarget as HTMLTableRowElement).style.background = '' }}
                  >
                    <td style={{ padding: '12px 16px', fontSize: 14, fontWeight: 600, color: 'var(--text)' }}>{s.nome}</td>
                    <td style={{ padding: '12px 16px' }}>
                      <span className="font-mono" style={{ fontSize: 14, color: 'var(--text)' }}>{formatarMoeda(s.valor_padrao)}</span>
                    </td>
                    <td style={{ padding: '12px 16px' }}>
                      <span style={{ display: 'inline-block', padding: '3px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700, textTransform: 'uppercase', background: s.ativo ? 'rgba(67,160,71,.15)' : 'rgba(229,57,53,.15)', color: s.ativo ? 'var(--success)' : 'var(--danger)' }}>
                        {s.ativo ? 'Ativo' : 'Inativo'}
                      </span>
                    </td>
                    <td style={{ padding: '12px 16px', whiteSpace: 'nowrap' }}>
                      {confirmDeactivate === s.id ? (
                        <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                          <span style={{ fontSize: 12, color: 'var(--muted)' }}>Confirmar?</span>
                          <button onClick={() => handleDeactivate(s.id)} disabled={deactivating}
                            style={{ background: 'rgba(229,57,53,.15)', border: '1px solid var(--danger)', color: 'var(--danger)', borderRadius: 6, padding: '4px 10px', fontSize: 12, fontWeight: 700, cursor: 'pointer' }}>
                            {deactivating ? '⟳' : 'Sim'}
                          </button>
                          <button onClick={() => setConfirmDeactivate(null)}
                            style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, padding: '4px 10px', fontSize: 12, cursor: 'pointer' }}>
                            Não
                          </button>
                        </div>
                      ) : (
                        <div style={{ display: 'flex', gap: 8 }}>
                          <button
                            onClick={() => { setConfirmDeactivate(null); setModalMode('edit'); setEditTarget(s); setShowModal(true) }}
                            style={{ background: 'rgba(245,166,35,.1)', border: '1px solid rgba(245,166,35,.3)', color: 'var(--accent)', borderRadius: 6, padding: '5px 14px', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>
                            Editar
                          </button>
                          {s.ativo ? (
                            <button onClick={() => setConfirmDeactivate(s.id)}
                              style={{ background: 'rgba(229,57,53,.1)', border: '1px solid rgba(229,57,53,.3)', color: 'var(--danger)', borderRadius: 6, padding: '5px 14px', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>
                              Desativar
                            </button>
                          ) : (
                            <button onClick={() => handleReactivate(s.id)} disabled={reactivating}
                              style={{ background: 'rgba(67,160,71,.1)', border: '1px solid rgba(67,160,71,.3)', color: 'var(--success)', borderRadius: 6, padding: '5px 14px', fontSize: 13, fontWeight: 600, cursor: reactivating ? 'not-allowed' : 'pointer' }}>
                              {reactivating ? '⟳' : 'Reativar'}
                            </button>
                          )}
                        </div>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {showModal && (
        <ServicoModal
          mode={modalMode}
          initial={editTarget}
          onClose={() => setShowModal(false)}
          onSuccess={handleModalSuccess}
        />
      )}

      <style>{`@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.45} }`}</style>
    </div>
  )
}
