'use client'

import { useState, useEffect, useCallback } from 'react'
import Link from 'next/link'
import saasApi from '@/lib/saas-api'
import { EditOficinaModal } from '@/components/saas/EditOficinaModal'

// ─── Types ────────────────────────────────────────────────────────────────────

interface Plano {
  id: string
  nome: string
  preco_mensal: string
}

interface Oficina {
  id: string
  nome: string
  cnpj: string
  slug: string
  status: 'ATIVA' | 'SUSPENSA' | 'CANCELADA'
  plano: { id: string; nome: string; preco_mensal: string } | null
  users_count: number
  os_mes_count: number
  admin_email: string
  admin_nome?: string | null
  admin_cpf?: string | null
  criado_em: string
}

interface OficinasResponse {
  data: Oficina[]
  meta: { total: number; per_page: number; current_page: number }
}

interface CreateForm {
  nome: string
  cnpj: string
  slug: string
  plano_id: string
  admin_nome: string
  admin_email: string
  admin_cpf: string
  admin_senha: string
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function statusColor(status: string): { bg: string; color: string } {
  switch (status.toUpperCase()) {
    case 'ATIVA':
      return { bg: 'rgba(67,160,71,.15)', color: 'var(--success)' }
    case 'SUSPENSA':
      return { bg: 'rgba(245,166,35,.15)', color: 'var(--accent)' }
    case 'CANCELADA':
      return { bg: 'rgba(229,57,53,.15)', color: 'var(--danger)' }
    default:
      return { bg: 'rgba(122,128,144,.15)', color: 'var(--muted)' }
  }
}

function slugify(str: string): string {
  const accents = 'áàãâäéèêëíìîïóòõôöúùûüçñ'
  const clean   = 'aaaaaeeeeiiiiooooouuuucn'
  let s = str.toLowerCase()
  for (let i = 0; i < accents.length; i++) {
    s = s.replace(new RegExp(accents[i], 'g'), clean[i])
  }
  return s
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 60)
}

function maskCNPJ(value: string): string {
  const digits = value.replace(/\D/g, '').slice(0, 14)
  return digits
    .replace(/^(\d{2})(\d)/, '$1.$2')
    .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
    .replace(/\.(\d{3})(\d)/, '.$1/$2')
    .replace(/(\d{4})(\d)/, '$1-$2')
}

function maskCPF(value: string): string {
  const digits = value.replace(/\D/g, '').slice(0, 11)
  return digits
    .replace(/^(\d{3})(\d)/, '$1.$2')
    .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
    .replace(/\.(\d{3})(\d)/, '.$1-$2')
}

const PER_PAGE = 15

// ─── Skeleton ─────────────────────────────────────────────────────────────────

function Skeleton({ width, height }: { width?: string | number; height?: string | number }) {
  return (
    <div
      style={{
        width: width ?? '100%',
        height: height ?? 16,
        borderRadius: 6,
        background: 'var(--border)',
        animation: 'pulse 1.4s ease-in-out infinite',
      }}
    />
  )
}

// ─── Input field ──────────────────────────────────────────────────────────────

interface FieldProps {
  label: string
  required?: boolean
  hint?: string
  children: React.ReactNode
}

function Field({ label, required, hint, children }: FieldProps) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      <label style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em' }}>
        {label}
        {required && <span style={{ color: 'var(--danger)', marginLeft: 3 }}>*</span>}
      </label>
      {children}
      {hint && (
        <span style={{ fontSize: 11, color: 'var(--muted)', marginTop: 1 }}>{hint}</span>
      )}
    </div>
  )
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
  transition: 'border-color 0.15s',
}

// ─── Create Modal ─────────────────────────────────────────────────────────────

interface CreateModalProps {
  planos: Plano[]
  onClose: () => void
  onSuccess: () => void
}

function CreateModal({ planos, onClose, onSuccess }: CreateModalProps) {
  const [form, setForm] = useState<CreateForm>({
    nome: '',
    cnpj: '',
    slug: '',
    plano_id: '',
    admin_nome: '',
    admin_email: '',
    admin_cpf: '',
    admin_senha: '',
  })
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [slugUserEdited, setSlugUserEdited] = useState(false)

  function handleChange(field: keyof CreateForm, value: string) {
    setForm((prev) => {
      const processed = (field === 'nome' || field === 'admin_nome') ? value.toUpperCase() : value
      const next = { ...prev, [field]: processed }
      if (field === 'nome' && !slugUserEdited) {
        next.slug = slugify(value)
      }
      return next
    })
  }

  function handleCNPJ(e: React.ChangeEvent<HTMLInputElement>) {
    handleChange('cnpj', maskCNPJ(e.target.value))
  }

  function handleCPF(e: React.ChangeEvent<HTMLInputElement>) {
    handleChange('admin_cpf', maskCPF(e.target.value))
  }

  function handleSlug(e: React.ChangeEvent<HTMLInputElement>) {
    setSlugUserEdited(true)
    const cleaned = e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '')
    setForm((prev) => ({ ...prev, slug: cleaned }))
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError(null)

    // Field-specific required checks
    if (!form.nome.trim()) { setError('Informe o nome da oficina.'); return }
    if (!form.cnpj.trim()) { setError('Informe o CNPJ da oficina.'); return }
    if (!form.slug.trim()) { setError('O slug da oficina é obrigatório.'); return }
    if (!form.plano_id.trim()) { setError('Selecione um plano para a oficina.'); return }
    if (!form.admin_nome.trim()) { setError('Informe o nome do administrador.'); return }
    if (!form.admin_email.trim()) { setError('Informe o e-mail do administrador.'); return }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.admin_email)) { setError('Informe um e-mail válido para o administrador.'); return }
    if (!form.admin_cpf.trim()) { setError('Informe o CPF do administrador.'); return }
    if (form.admin_senha && form.admin_senha.length < 8) {
      setError('A senha do administrador deve ter no mínimo 8 caracteres.')
      return
    }

    setSubmitting(true)
    try {
      const payload: Record<string, string> = {
        nome: form.nome,
        cnpj: form.cnpj,
        slug: form.slug,
        plano_id: form.plano_id,
        admin_nome: form.admin_nome,
        admin_email: form.admin_email,
        admin_cpf: form.admin_cpf,
      }
      if (form.admin_senha) payload.admin_senha = form.admin_senha

      await saasApi.post('/saas/oficinas', payload)
      onSuccess()
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string } } }
      setError(
        axiosErr.response?.data?.message ?? 'Erro ao criar oficina. Tente novamente.'
      )
    } finally {
      setSubmitting(false)
    }
  }

  // Close on backdrop click
  function handleBackdropClick(e: React.MouseEvent<HTMLDivElement>) {
    if (e.target === e.currentTarget) onClose()
  }

  return (
    <div
      onClick={handleBackdropClick}
      style={{
        position: 'fixed',
        inset: 0,
        background: 'rgba(0,0,0,.6)',
        zIndex: 1000,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 24,
      }}
    >
      <div
        style={{
          background: 'var(--card)',
          border: '1px solid var(--border)',
          borderRadius: 12,
          width: '100%',
          maxWidth: 560,
          maxHeight: 'calc(100vh - 48px)',
          overflowY: 'auto',
          padding: '28px 32px',
          position: 'relative',
        }}
      >
        {/* Header */}
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            marginBottom: 24,
          }}
        >
          <h2
            className="font-display"
            style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: 0 }}
          >
            Nova Oficina
          </h2>
          <button
            onClick={onClose}
            style={{
              background: 'none',
              border: 'none',
              color: 'var(--muted)',
              fontSize: 20,
              cursor: 'pointer',
              lineHeight: 1,
              padding: 4,
            }}
            aria-label="Fechar"
          >
            ✕
          </button>
        </div>

        {/* Error */}
        {error && (
          <div
            style={{
              background: 'rgba(229,57,53,.1)',
              border: '1px solid var(--danger)',
              borderRadius: 8,
              padding: '10px 14px',
              color: 'var(--danger)',
              fontSize: 13,
              marginBottom: 20,
            }}
          >
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {/* Separator label */}
          <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.08em' }}>
            Dados da Oficina
          </div>

          <Field label="Nome da Oficina" required>
            <input
              style={{ ...inputStyle, textTransform: 'uppercase' }}
              value={form.nome}
              onChange={(e) => handleChange('nome', e.target.value)}
              placeholder="EX: AUTO CENTRO DO JOÃO"
              disabled={submitting}
            />
          </Field>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <Field label="CNPJ" required>
              <input
                style={inputStyle}
                value={form.cnpj}
                onChange={handleCNPJ}
                placeholder="00.000.000/0000-00"
                disabled={submitting}
              />
            </Field>

            <Field label="Slug (identificador)" required hint={slugUserEdited ? 'letras minúsculas, números e hífens' : 'gerado automaticamente pelo nome'}>
              <input
                style={inputStyle}
                value={form.slug}
                onChange={handleSlug}
                placeholder="auto-centro-joao"
                disabled={submitting}
              />
            </Field>
          </div>

          <Field label="Plano" required>
            <select
              style={{ ...inputStyle, appearance: 'none' }}
              value={form.plano_id}
              onChange={(e) => handleChange('plano_id', e.target.value)}
              disabled={submitting}
            >
              <option value="">Selecione um plano...</option>
              {planos.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.nome}
                </option>
              ))}
            </select>
          </Field>

          {/* Separator label */}
          <div
            style={{
              fontSize: 11,
              fontWeight: 700,
              color: 'var(--muted)',
              textTransform: 'uppercase',
              letterSpacing: '0.08em',
              marginTop: 4,
            }}
          >
            Dados do Admin
          </div>

          <Field label="Nome Completo" required>
            <input
              style={{ ...inputStyle, textTransform: 'uppercase' }}
              value={form.admin_nome}
              onChange={(e) => handleChange('admin_nome', e.target.value)}
              placeholder="JOÃO DA SILVA"
              disabled={submitting}
            />
          </Field>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <Field label="E-mail" required>
              <input
                style={inputStyle}
                type="email"
                value={form.admin_email}
                onChange={(e) => handleChange('admin_email', e.target.value)}
                placeholder="joao@oficina.com"
                disabled={submitting}
              />
            </Field>

            <Field label="CPF" required>
              <input
                style={inputStyle}
                value={form.admin_cpf}
                onChange={handleCPF}
                placeholder="000.000.000-00"
                disabled={submitting}
              />
            </Field>
          </div>

          <Field label="Senha do Admin" hint="Opcional — mínimo 8 caracteres se informada">
            <input
              style={inputStyle}
              type="password"
              value={form.admin_senha}
              onChange={(e) => handleChange('admin_senha', e.target.value)}
              placeholder="Deixe em branco para gerar automaticamente"
              disabled={submitting}
            />
          </Field>

          {/* Actions */}
          <div
            style={{
              display: 'flex',
              gap: 12,
              justifyContent: 'flex-end',
              marginTop: 8,
              paddingTop: 16,
              borderTop: '1px solid var(--border)',
            }}
          >
            <button
              type="button"
              onClick={onClose}
              disabled={submitting}
              style={{
                background: 'none',
                border: '1px solid var(--border)',
                color: 'var(--muted)',
                borderRadius: 8,
                padding: '9px 20px',
                fontSize: 14,
                cursor: 'pointer',
                transition: 'color 0.15s, border-color 0.15s',
              }}
            >
              Cancelar
            </button>
            <button
              type="submit"
              disabled={submitting}
              style={{
                background: submitting ? 'rgba(245,166,35,.4)' : 'var(--accent)',
                color: '#000',
                border: 'none',
                borderRadius: 8,
                padding: '9px 24px',
                fontSize: 14,
                fontWeight: 700,
                cursor: submitting ? 'not-allowed' : 'pointer',
                transition: 'background 0.15s',
                fontFamily: "'Barlow Condensed', sans-serif",
                letterSpacing: '0.03em',
              }}
            >
              {submitting ? '⟳ Criando...' : 'Criar Oficina'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function OficinasPage() {
  const [oficinas, setOficinas] = useState<Oficina[]>([])
  const [meta, setMeta] = useState({ total: 0, per_page: PER_PAGE, current_page: 1 })
  const [planos, setPlanos] = useState<Plano[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [showModal, setShowModal] = useState(false)
  const [successMsg, setSuccessMsg] = useState<string | null>(null)
  // confirmação inline: key = oficina id, value = 'suspender' | 'reativar' | 'voto-confianca' | null
  const [confirmMap, setConfirmMap] = useState<Record<string, 'suspender' | 'reativar' | 'voto-confianca'>>({})
  const [actionLoading, setActionLoading] = useState<string | null>(null)
  const [editingOficina, setEditingOficina] = useState<Oficina | null>(null)
  const [deleteConfirmId, setDeleteConfirmId] = useState<string | null>(null)
  const [deleteLoading, setDeleteLoading] = useState(false)
  const [deleteError, setDeleteError] = useState<string | null>(null)

  const totalPages = Math.ceil(meta.total / meta.per_page)

  const fetchOficinas = useCallback(async (page = 1) => {
    setLoading(true)
    setError(null)
    try {
      const res = await saasApi.get<OficinasResponse>('/saas/oficinas', {
        params: { page, per_page: PER_PAGE },
      })
      setOficinas(res.data.data)
      setMeta(res.data.meta)
    } catch {
      setError('Erro ao carregar lista de oficinas.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchOficinas(1)
    saasApi
      .get<{ data: Plano[] }>('/saas/planos')
      .then((res) => setPlanos(res.data.data ?? []))
      .catch(() => setPlanos([]))
  }, [fetchOficinas])

  function showSuccess(msg: string) {
    setSuccessMsg(msg)
    setTimeout(() => setSuccessMsg(null), 3500)
  }

  function handleModalSuccess() {
    setShowModal(false)
    showSuccess('Oficina criada com sucesso!')
    fetchOficinas(1)
  }

  function requestAction(oficina: Oficina, action: 'suspender' | 'reativar' | 'voto-confianca') {
    setConfirmMap((prev) => ({ ...prev, [oficina.id]: action }))
  }

  function cancelAction(oficinaId: string) {
    setConfirmMap((prev) => {
      const next = { ...prev }
      delete next[oficinaId]
      return next
    })
  }

  async function confirmAction(oficina: Oficina) {
    const action = confirmMap[oficina.id]
    if (!action) return
    setActionLoading(oficina.id)
    try {
      await saasApi.post(`/saas/oficinas/${oficina.id}/${action}`)
      cancelAction(oficina.id)
      showSuccess(
        action === 'suspender' ? `Oficina "${oficina.nome}" suspensa.`
        : action === 'reativar' ? `Oficina "${oficina.nome}" reativada.`
        : `Voto de confiança concedido para "${oficina.nome}".`
      )
      fetchOficinas(meta.current_page)
    } catch {
      showSuccess('Erro ao executar ação. Tente novamente.')
    } finally {
      setActionLoading(null)
    }
  }

  function goToPage(page: number) {
    if (page < 1 || page > totalPages) return
    fetchOficinas(page)
  }

  function handleEditSuccess() {
    setEditingOficina(null)
    showSuccess('Dados da oficina atualizados.')
    fetchOficinas(meta.current_page)
  }

  async function handleDelete(oficina: Oficina) {
    setDeleteLoading(true)
    setDeleteError(null)
    try {
      await saasApi.delete(`/saas/oficinas/${oficina.id}`)
      setDeleteConfirmId(null)
      showSuccess(`Oficina "${oficina.nome}" excluída com sucesso.`)
      fetchOficinas(meta.current_page)
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string } } }
      setDeleteError(axiosErr.response?.data?.message ?? 'Erro ao excluir. Tente novamente.')
    } finally {
      setDeleteLoading(false)
    }
  }

  const TABLE_COLS = ['Nome', 'CNPJ', 'Plano', 'Status', 'Usuários', 'OS/mês', 'Ações', '']

  return (
    <>
      {/* Keyframes */}
      <style>{`
        @keyframes pulse {
          0%, 100% { opacity: 1; }
          50% { opacity: 0.45; }
        }
        @keyframes slideDown {
          from { opacity: 0; transform: translateY(-6px); }
          to   { opacity: 1; transform: translateY(0); }
        }
      `}</style>

      <div style={{ padding: '32px 32px 40px', color: 'var(--text)', maxWidth: 1280, margin: '0 auto' }}>
        {/* Page header */}
        <div
          style={{
            display: 'flex',
            alignItems: 'flex-end',
            justifyContent: 'space-between',
            marginBottom: 28,
            flexWrap: 'wrap',
            gap: 12,
          }}
        >
          <div>
            <h1
              className="font-display"
              style={{ fontSize: 26, fontWeight: 800, color: 'var(--text)', margin: 0 }}
            >
              Oficinas
            </h1>
            <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
              Gerencie todas as oficinas cadastradas na plataforma
            </p>
          </div>

          <button
            onClick={() => setShowModal(true)}
            style={{
              background: 'var(--accent)',
              color: '#000',
              border: 'none',
              borderRadius: 8,
              padding: '10px 22px',
              fontSize: 14,
              fontWeight: 700,
              cursor: 'pointer',
              fontFamily: "'Barlow Condensed', sans-serif",
              letterSpacing: '0.03em',
              transition: 'opacity 0.15s',
            }}
            onMouseEnter={(e) => { (e.currentTarget as HTMLButtonElement).style.opacity = '0.85' }}
            onMouseLeave={(e) => { (e.currentTarget as HTMLButtonElement).style.opacity = '1' }}
          >
            + Nova Oficina
          </button>
        </div>

        {/* Success toast */}
        {successMsg && (
          <div
            style={{
              background: 'rgba(67,160,71,.15)',
              border: '1px solid var(--success)',
              borderRadius: 8,
              padding: '11px 16px',
              color: 'var(--success)',
              fontSize: 14,
              marginBottom: 20,
              animation: 'slideDown 0.2s ease',
            }}
          >
            {successMsg}
          </div>
        )}

        {/* Error banner */}
        {error && (
          <div
            style={{
              background: 'rgba(229,57,53,.1)',
              border: '1px solid var(--danger)',
              borderRadius: 8,
              padding: '11px 16px',
              color: 'var(--danger)',
              fontSize: 14,
              marginBottom: 20,
            }}
          >
            {error}
          </div>
        )}

        {/* Table card */}
        <div
          style={{
            background: 'var(--card)',
            border: '1px solid var(--border)',
            borderRadius: 10,
            overflow: 'hidden',
          }}
        >
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead>
                <tr>
                  {TABLE_COLS.map((col) => (
                    <th
                      key={col}
                      style={{
                        padding: '11px 16px',
                        textAlign: 'left',
                        fontSize: 11,
                        fontWeight: 600,
                        color: 'var(--muted)',
                        textTransform: 'uppercase',
                        letterSpacing: '0.06em',
                        borderBottom: '1px solid var(--border)',
                        whiteSpace: 'nowrap',
                        background: 'var(--surface)',
                      }}
                    >
                      {col}
                    </th>
                  ))}
                </tr>
              </thead>

              <tbody>
                {loading ? (
                  Array.from({ length: 8 }).map((_, i) => (
                    <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                      <td style={{ padding: '13px 16px' }}>
                        <Skeleton width="70%" height={14} />
                        <div style={{ marginTop: 5 }}>
                          <Skeleton width="45%" height={11} />
                        </div>
                      </td>
                      {Array.from({ length: 5 }).map((__, j) => (
                        <td key={j} style={{ padding: '13px 16px' }}>
                          <Skeleton height={14} width={j === 3 ? 64 : j >= 4 ? '40%' : '60%'} />
                        </td>
                      ))}
                      <td style={{ padding: '13px 16px' }}>
                        <Skeleton width={72} height={28} />
                      </td>
                    </tr>
                  ))
                ) : oficinas.length === 0 ? (
                  <tr>
                    <td
                      colSpan={7}
                      style={{
                        padding: '48px 16px',
                        textAlign: 'center',
                        color: 'var(--muted)',
                        fontSize: 14,
                      }}
                    >
                      Nenhuma oficina cadastrada ainda.
                    </td>
                  </tr>
                ) : (
                  oficinas.map((oficina, idx) => {
                    const { bg, color } = statusColor(oficina.status)
                    const isLast = idx === oficinas.length - 1
                    const pendingAction = confirmMap[oficina.id]
                    const isActioning = actionLoading === oficina.id

                    return (
                      <tr
                        key={oficina.id}
                        style={{
                          borderBottom: isLast ? 'none' : '1px solid var(--border)',
                          transition: 'background 0.12s',
                        }}
                        onMouseEnter={(e) => {
                          ;(e.currentTarget as HTMLTableRowElement).style.background =
                            'rgba(255,255,255,.02)'
                        }}
                        onMouseLeave={(e) => {
                          ;(e.currentTarget as HTMLTableRowElement).style.background = ''
                        }}
                      >
                        {/* Nome + slug */}
                        <td style={{ padding: '12px 16px', minWidth: 180 }}>
                          <div style={{ fontSize: 14, fontWeight: 600, color: 'var(--text)' }}>
                            {oficina.nome}
                          </div>
                          <div
                            style={{
                              fontSize: 11,
                              color: 'var(--muted)',
                              fontFamily: "'JetBrains Mono', monospace",
                              marginTop: 2,
                            }}
                          >
                            {oficina.slug}
                          </div>
                        </td>

                        {/* CNPJ */}
                        <td style={{ padding: '12px 16px', whiteSpace: 'nowrap' }}>
                          <span
                            style={{
                              fontFamily: "'JetBrains Mono', monospace",
                              fontSize: 13,
                              color: 'var(--text)',
                            }}
                          >
                            {oficina.cnpj}
                          </span>
                        </td>

                        {/* Plano */}
                        <td style={{ padding: '12px 16px', minWidth: 120 }}>
                          {oficina.plano ? (
                            <>
                              <div style={{ fontSize: 13, color: 'var(--text)' }}>
                                {oficina.plano.nome}
                              </div>
                              <div
                                style={{
                                  fontSize: 11,
                                  color: 'var(--muted)',
                                  fontFamily: "'JetBrains Mono', monospace",
                                  marginTop: 2,
                                }}
                              >
                                {oficina.plano.preco_mensal}
                              </div>
                            </>
                          ) : (
                            <span style={{ color: 'var(--muted)', fontSize: 13 }}>—</span>
                          )}
                        </td>

                        {/* Status pill */}
                        <td style={{ padding: '12px 16px' }}>
                          <span
                            style={{
                              display: 'inline-block',
                              padding: '3px 10px',
                              borderRadius: 999,
                              fontSize: 11,
                              fontWeight: 700,
                              letterSpacing: '0.04em',
                              background: bg,
                              color,
                              textTransform: 'uppercase',
                              whiteSpace: 'nowrap',
                            }}
                          >
                            {oficina.status}
                          </span>
                        </td>

                        {/* Usuários */}
                        <td style={{ padding: '12px 16px', textAlign: 'center' }}>
                          <span
                            style={{
                              fontFamily: "'JetBrains Mono', monospace",
                              fontSize: 14,
                              color: 'var(--text)',
                            }}
                          >
                            {oficina.users_count}
                          </span>
                        </td>

                        {/* OS/mês */}
                        <td style={{ padding: '12px 16px', textAlign: 'center' }}>
                          <span
                            style={{
                              fontFamily: "'JetBrains Mono', monospace",
                              fontSize: 14,
                              color: 'var(--text)',
                            }}
                          >
                            {oficina.os_mes_count}
                          </span>
                        </td>

                        {/* Ações */}
                        <td style={{ padding: '12px 16px', whiteSpace: 'nowrap' }}>
                          {pendingAction ? (
                            // Confirmation state
                            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                              <span style={{ fontSize: 12, color: 'var(--muted)' }}>
                                Confirmar?
                              </span>
                              <button
                                onClick={() => confirmAction(oficina)}
                                disabled={isActioning}
                                style={{
                                  background:
                                    pendingAction === 'suspender'
                                      ? 'rgba(229,57,53,.15)'
                                      : 'rgba(67,160,71,.15)',
                                  border: `1px solid ${pendingAction === 'suspender' ? 'var(--danger)' : 'var(--success)'}`,
                                  color:
                                    pendingAction === 'suspender'
                                      ? 'var(--danger)'
                                      : 'var(--success)',
                                  borderRadius: 6,
                                  padding: '4px 10px',
                                  fontSize: 12,
                                  fontWeight: 700,
                                  cursor: isActioning ? 'not-allowed' : 'pointer',
                                  opacity: isActioning ? 0.6 : 1,
                                }}
                              >
                                {isActioning ? '⟳' : 'Sim'}
                              </button>
                              <button
                                onClick={() => cancelAction(oficina.id)}
                                disabled={isActioning}
                                style={{
                                  background: 'none',
                                  border: '1px solid var(--border)',
                                  color: 'var(--muted)',
                                  borderRadius: 6,
                                  padding: '4px 8px',
                                  fontSize: 12,
                                  cursor: 'pointer',
                                }}
                              >
                                Não
                              </button>
                            </div>
                          ) : oficina.status === 'ATIVA' ? (
                            <button
                              onClick={() => requestAction(oficina, 'suspender')}
                              style={{
                                background: 'rgba(229,57,53,.1)',
                                border: '1px solid rgba(229,57,53,.3)',
                                color: 'var(--danger)',
                                borderRadius: 6,
                                padding: '5px 14px',
                                fontSize: 13,
                                fontWeight: 600,
                                cursor: 'pointer',
                                transition: 'background 0.15s',
                              }}
                              onMouseEnter={(e) => {
                                ;(e.currentTarget as HTMLButtonElement).style.background =
                                  'rgba(229,57,53,.2)'
                              }}
                              onMouseLeave={(e) => {
                                ;(e.currentTarget as HTMLButtonElement).style.background =
                                  'rgba(229,57,53,.1)'
                              }}
                            >
                              Suspender
                            </button>
                          ) : oficina.status === 'SUSPENSA' ? (
                            <div style={{ display: 'flex', gap: 6 }}>
                              <button
                                onClick={() => requestAction(oficina, 'reativar')}
                                style={{ background: 'rgba(67,160,71,.1)', border: '1px solid rgba(67,160,71,.3)', color: 'var(--success)', borderRadius: 6, padding: '5px 14px', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}
                              >
                                Reativar
                              </button>
                              <button
                                onClick={() => requestAction(oficina, 'voto-confianca')}
                                style={{ background: 'rgba(245,166,35,.1)', border: '1px solid rgba(245,166,35,.3)', color: 'var(--accent)', borderRadius: 6, padding: '5px 14px', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}
                              >
                                Voto de Confiança
                              </button>
                            </div>
                          ) : (
                            <span style={{ color: 'var(--muted)', fontSize: 13 }}>—</span>
                          )}
                        </td>
                        <td style={{ padding: '12px 16px', whiteSpace: 'nowrap' }}>
                          {deleteConfirmId === oficina.id ? (
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 6, animation: 'slideDown .15s' }}>
                              {deleteError && (
                                <span style={{ fontSize: 11, color: 'var(--danger)' }}>{deleteError}</span>
                              )}
                              <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                <span style={{ fontSize: 12, color: 'var(--danger)', fontWeight: 700 }}>Confirmar exclusão?</span>
                                <button
                                  onClick={() => handleDelete(oficina)}
                                  disabled={deleteLoading}
                                  style={{
                                    background: 'rgba(229,57,53,.15)',
                                    border: '1px solid var(--danger)',
                                    color: 'var(--danger)',
                                    borderRadius: 6,
                                    padding: '4px 10px',
                                    fontSize: 12,
                                    fontWeight: 700,
                                    cursor: deleteLoading ? 'not-allowed' : 'pointer',
                                    opacity: deleteLoading ? 0.6 : 1,
                                  }}
                                >
                                  {deleteLoading ? '⟳' : 'Excluir'}
                                </button>
                                <button
                                  onClick={() => { setDeleteConfirmId(null); setDeleteError(null) }}
                                  disabled={deleteLoading}
                                  style={{
                                    background: 'none',
                                    border: '1px solid var(--border)',
                                    color: 'var(--muted)',
                                    borderRadius: 6,
                                    padding: '4px 8px',
                                    fontSize: 12,
                                    cursor: 'pointer',
                                  }}
                                >
                                  Cancelar
                                </button>
                              </div>
                            </div>
                          ) : (
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                              <button
                                onClick={() => setEditingOficina(oficina)}
                                style={{
                                  background: 'none',
                                  border: '1px solid var(--info)',
                                  color: 'var(--info)',
                                  borderRadius: 6,
                                  padding: '4px 12px',
                                  fontSize: 12,
                                  fontWeight: 600,
                                  cursor: 'pointer',
                                }}
                              >
                                Editar
                              </button>
                              <button
                                onClick={() => { setDeleteConfirmId(oficina.id); setDeleteError(null) }}
                                style={{
                                  background: 'none',
                                  border: '1px solid rgba(229,57,53,.4)',
                                  color: 'var(--danger)',
                                  borderRadius: 6,
                                  padding: '4px 10px',
                                  fontSize: 12,
                                  fontWeight: 600,
                                  cursor: 'pointer',
                                }}
                              >
                                Excluir
                              </button>
                              <Link href={`/saas-admin/oficinas/${oficina.id}`}
                                style={{ fontSize: 13, color: 'var(--info)', textDecoration: 'none', fontWeight: 600 }}>
                                Detalhes →
                              </Link>
                            </div>
                          )}
                        </td>
                      </tr>
                    )
                  })
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {!loading && meta.total > PER_PAGE && (
            <div
              style={{
                padding: '14px 20px',
                borderTop: '1px solid var(--border)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                gap: 12,
              }}
            >
              <span style={{ fontSize: 13, color: 'var(--muted)' }}>
                {meta.total} oficina{meta.total !== 1 ? 's' : ''} no total
              </span>

              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <button
                  onClick={() => goToPage(meta.current_page - 1)}
                  disabled={meta.current_page <= 1}
                  style={{
                    background: 'none',
                    border: '1px solid var(--border)',
                    color: meta.current_page <= 1 ? 'var(--muted)' : 'var(--text)',
                    borderRadius: 6,
                    padding: '5px 14px',
                    fontSize: 13,
                    cursor: meta.current_page <= 1 ? 'not-allowed' : 'pointer',
                    opacity: meta.current_page <= 1 ? 0.5 : 1,
                    transition: 'opacity 0.15s',
                  }}
                >
                  ← Anterior
                </button>

                <span
                  style={{
                    fontFamily: "'JetBrains Mono', monospace",
                    fontSize: 13,
                    color: 'var(--text)',
                    minWidth: 80,
                    textAlign: 'center',
                  }}
                >
                  Página {meta.current_page} de {totalPages}
                </span>

                <button
                  onClick={() => goToPage(meta.current_page + 1)}
                  disabled={meta.current_page >= totalPages}
                  style={{
                    background: 'none',
                    border: '1px solid var(--border)',
                    color: meta.current_page >= totalPages ? 'var(--muted)' : 'var(--text)',
                    borderRadius: 6,
                    padding: '5px 14px',
                    fontSize: 13,
                    cursor: meta.current_page >= totalPages ? 'not-allowed' : 'pointer',
                    opacity: meta.current_page >= totalPages ? 0.5 : 1,
                    transition: 'opacity 0.15s',
                  }}
                >
                  Próxima →
                </button>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Create modal */}
      {showModal && (
        <CreateModal
          planos={planos}
          onClose={() => setShowModal(false)}
          onSuccess={handleModalSuccess}
        />
      )}

      {/* Edit modal */}
      {editingOficina && (
        <EditOficinaModal
          oficina={editingOficina}
          planos={planos}
          onClose={() => setEditingOficina(null)}
          onSuccess={handleEditSuccess}
        />
      )}
    </>
  )
}
