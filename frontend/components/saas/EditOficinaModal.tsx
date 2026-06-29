'use client'

import { useState } from 'react'
import saasApi from '@/lib/saas-api'

interface Plano {
  id: string
  nome: string
  preco_mensal: string
}

interface Oficina {
  id: string
  nome: string
  cnpj?: string | null
  plano: { id: string; nome: string; preco_mensal: string } | null
  admin_nome?: string | null
  admin_email: string
  admin_cpf?: string | null
}

interface EditOficinaModalProps {
  oficina: Oficina
  planos: Plano[]
  onClose: () => void
  onSuccess: () => void
}

const inputStyle: React.CSSProperties = {
  background: 'var(--bg)',
  border: '1px solid var(--border)',
  borderRadius: 8,
  color: 'var(--text)',
  fontSize: 14,
  padding: '9px 12px',
  outline: 'none',
  width: '100%',
  boxSizing: 'border-box',
}

function Field({ label, required, hint, children }: { label: string; required?: boolean; hint?: string; children: React.ReactNode }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      <label style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em' }}>
        {label}{required && <span style={{ color: 'var(--danger)', marginLeft: 3 }}>*</span>}
      </label>
      {children}
      {hint && <span style={{ fontSize: 11, color: 'var(--muted)' }}>{hint}</span>}
    </div>
  )
}

function SectionLabel({ children }: { children: React.ReactNode }) {
  return (
    <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.08em', marginTop: 4 }}>
      {children}
    </div>
  )
}

export function EditOficinaModal({ oficina, planos, onClose, onSuccess }: EditOficinaModalProps) {
  const [nome, setNome] = useState(oficina.nome)
  const [planoId, setPlanoId] = useState(oficina.plano?.id ?? '')
  const [adminNome, setAdminNome] = useState(oficina.admin_nome ?? '')
  const [adminEmail, setAdminEmail] = useState(oficina.admin_email)
  const [adminSenha, setAdminSenha] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  function handleBackdrop(e: React.MouseEvent<HTMLDivElement>) {
    if (e.target === e.currentTarget) onClose()
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError(null)

    if (!nome.trim()) { setError('Informe o nome da oficina.'); return }
    if (!adminEmail.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(adminEmail)) {
      setError('Informe um e-mail válido para o administrador.'); return
    }
    if (adminSenha && adminSenha.length < 8) {
      setError('A nova senha deve ter no mínimo 8 caracteres.'); return
    }

    const payload: Record<string, string> = {
      nome: nome.trim(),
      admin_email: adminEmail.trim(),
    }
    if (planoId) payload.plano_id = planoId
    if (adminNome.trim()) payload.admin_nome = adminNome.trim()
    if (adminSenha) payload.admin_senha = adminSenha

    setSubmitting(true)
    try {
      await saasApi.put(`/saas/oficinas/${oficina.id}`, payload)
      onSuccess()
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string } } }
      setError(axiosErr.response?.data?.message ?? 'Erro ao salvar. Tente novamente.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div
      onClick={handleBackdrop}
      style={{
        position: 'fixed', inset: 0, background: 'rgba(0,0,0,.6)', zIndex: 1000,
        display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24,
      }}
    >
      <div style={{
        background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12,
        width: '100%', maxWidth: 560, maxHeight: 'calc(100vh - 48px)', overflowY: 'auto',
        padding: '28px 32px', position: 'relative',
      }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 24 }}>
          <h2 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
            Editar Oficina
          </h2>
          <button onClick={onClose} style={{ background: 'none', border: 'none', color: 'var(--muted)', fontSize: 20, cursor: 'pointer', padding: 4, lineHeight: 1 }}>
            ✕
          </button>
        </div>

        {error && (
          <div style={{ background: 'rgba(229,57,53,.1)', border: '1px solid var(--danger)', borderRadius: 8, padding: '10px 14px', color: 'var(--danger)', fontSize: 13, marginBottom: 20 }}>
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <SectionLabel>Dados da Oficina</SectionLabel>

          <Field label="Nome da Oficina" required>
            <input
              style={{ ...inputStyle, textTransform: 'uppercase' }}
              value={nome}
              onChange={e => setNome(e.target.value.toUpperCase())}
              placeholder="EX: AUTO CENTRO DO JOÃO"
              disabled={submitting}
            />
          </Field>

          <Field label="CNPJ" hint="Não editável após cadastro">
            <input
              style={{ ...inputStyle, opacity: 0.6, cursor: 'not-allowed' }}
              value={oficina.cnpj || '—'}
              disabled
              readOnly
            />
          </Field>

          <Field label="Plano">
            <select
              style={{ ...inputStyle, appearance: 'none' }}
              value={planoId}
              onChange={e => setPlanoId(e.target.value)}
              disabled={submitting}
            >
              <option value="">Sem plano</option>
              {planos.map(p => (
                <option key={p.id} value={p.id}>{p.nome}</option>
              ))}
            </select>
          </Field>

          <SectionLabel>Admin</SectionLabel>

          <Field label="CPF do Admin" hint="Não editável após cadastro">
            <input
              style={{ ...inputStyle, opacity: 0.6, cursor: 'not-allowed' }}
              value={oficina.admin_cpf || '—'}
              disabled
              readOnly
            />
          </Field>

          <Field label="Nome Completo">
            <input
              style={{ ...inputStyle, textTransform: 'uppercase' }}
              value={adminNome}
              onChange={e => setAdminNome(e.target.value.toUpperCase())}
              placeholder="JOÃO DA SILVA"
              disabled={submitting}
            />
          </Field>

          <Field label="E-mail" required>
            <input
              style={inputStyle}
              type="email"
              value={adminEmail}
              onChange={e => setAdminEmail(e.target.value)}
              placeholder="joao@oficina.com"
              disabled={submitting}
            />
          </Field>

          <Field label="Nova Senha" hint="Deixe em branco para não alterar — mínimo 8 caracteres se informada">
            <input
              style={inputStyle}
              type="password"
              value={adminSenha}
              onChange={e => setAdminSenha(e.target.value)}
              placeholder="••••••••"
              disabled={submitting}
            />
          </Field>

          <div style={{ display: 'flex', gap: 12, justifyContent: 'flex-end', marginTop: 8, paddingTop: 16, borderTop: '1px solid var(--border)' }}>
            <button
              type="button"
              onClick={onClose}
              disabled={submitting}
              style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 8, padding: '9px 20px', fontSize: 14, cursor: 'pointer' }}
            >
              Cancelar
            </button>
            <button
              type="submit"
              disabled={submitting}
              style={{
                background: submitting ? 'rgba(245,166,35,.4)' : 'var(--accent)',
                color: '#000', border: 'none', borderRadius: 8, padding: '9px 24px',
                fontSize: 14, fontWeight: 700, cursor: submitting ? 'not-allowed' : 'pointer',
                fontFamily: "'Barlow Condensed', sans-serif", letterSpacing: '0.03em',
              }}
            >
              {submitting ? '⟳ Salvando…' : 'Salvar'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
