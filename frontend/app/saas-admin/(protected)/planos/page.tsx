'use client'

import { useState, useEffect, useCallback } from 'react'
import saasApi from '@/lib/saas-api'

// ─── Types ────────────────────────────────────────────────────────────────────

interface Plano {
  id: string
  nome: string
  preco_mensal: string // e.g. "199.90"
  limite_usuarios: number // -1 = unlimited
  limite_os_mes: number // -1 = unlimited
  limite_produtos: number
  limite_clientes: number
  limite_notas_mes: number
  preco_nota_excedente: string
  alerta_whatsapp: boolean
  alerta_email: boolean
  ativo: boolean
  oficinas_count: number
}

interface PlanoForm {
  nome: string
  preco_mensal: string
  limite_usuarios: string
  limite_os_mes: string
  limite_produtos: string
  limite_clientes: string
  limite_notas_mes: string
  preco_nota_excedente: string
  alerta_whatsapp: boolean
  alerta_email: boolean
}

type ModalMode = 'create' | 'edit'

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatarPreco(value: string): string {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(
    parseFloat(value) || 0
  )
}

function displayLimite(value: number): string {
  return value === -1 ? 'Ilimitado' : String(value)
}

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

// ─── Field wrapper ────────────────────────────────────────────────────────────

interface FieldProps {
  label: string
  required?: boolean
  hint?: string
  children: React.ReactNode
}

function Field({ label, required, hint, children }: FieldProps) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      <label style={{ fontSize: 13, fontWeight: 600, color: 'var(--muted)' }}>
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

// ─── Create / Edit Modal ──────────────────────────────────────────────────────

interface PlanoModalProps {
  mode: ModalMode
  initial?: Plano
  onClose: () => void
  onSuccess: () => void
}

function PlanoModal({ mode, initial, onClose, onSuccess }: PlanoModalProps) {
  const [form, setForm] = useState<PlanoForm>({
    nome: initial?.nome ?? '',
    preco_mensal: initial?.preco_mensal ?? '',
    limite_usuarios: initial ? String(initial.limite_usuarios) : '',
    limite_os_mes: initial ? String(initial.limite_os_mes) : '',
    limite_produtos: initial ? String(initial.limite_produtos ?? -1) : '',
    limite_clientes: initial ? String(initial.limite_clientes ?? -1) : '',
    limite_notas_mes: initial ? String(initial.limite_notas_mes ?? -1) : '',
    preco_nota_excedente: initial?.preco_nota_excedente ?? '',
    alerta_whatsapp: initial?.alerta_whatsapp ?? false,
    alerta_email: initial?.alerta_email ?? false,
  })
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  function handleChange(field: keyof PlanoForm, value: string) {
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError(null)

    if (!form.nome.trim()) {
      setError('O nome do plano é obrigatório.')
      return
    }
    if (!form.preco_mensal || isNaN(parseFloat(form.preco_mensal))) {
      setError('Informe um preço mensal válido.')
      return
    }
    if (form.limite_usuarios === '' || isNaN(Number(form.limite_usuarios))) {
      setError('Informe o limite de usuários (use -1 para ilimitado).')
      return
    }
    if (form.limite_os_mes === '' || isNaN(Number(form.limite_os_mes))) {
      setError('Informe o limite de OS/mês (use -1 para ilimitado).')
      return
    }

    const payload = {
      nome: form.nome.trim(),
      preco_mensal: parseFloat(form.preco_mensal),
      limite_usuarios: parseInt(form.limite_usuarios, 10),
      limite_os_mes: parseInt(form.limite_os_mes, 10),
      limite_produtos: form.limite_produtos !== '' ? parseInt(form.limite_produtos, 10) : -1,
      limite_clientes: form.limite_clientes !== '' ? parseInt(form.limite_clientes, 10) : -1,
      limite_notas_mes: form.limite_notas_mes !== '' ? parseInt(form.limite_notas_mes, 10) : -1,
      preco_nota_excedente: form.preco_nota_excedente !== '' ? parseFloat(form.preco_nota_excedente) : 0,
      alerta_whatsapp: form.alerta_whatsapp,
      alerta_email: form.alerta_email,
    }

    setSubmitting(true)
    try {
      if (mode === 'create') {
        await saasApi.post('/saas/planos', payload)
      } else {
        await saasApi.put(`/saas/planos/${initial!.id}`, payload)
      }
      onSuccess()
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string } } }
      setError(
        axiosErr.response?.data?.message ??
          (mode === 'create' ? 'Erro ao criar plano.' : 'Erro ao salvar alterações.')
      )
    } finally {
      setSubmitting(false)
    }
  }

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
          maxWidth: 480,
          maxHeight: 'calc(100vh - 48px)',
          overflowY: 'auto',
          padding: '28px 32px',
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
            {mode === 'create' ? 'Novo Plano' : 'Editar Plano'}
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
          <Field label="Nome do Plano" required>
            <input
              style={inputStyle}
              value={form.nome}
              onChange={(e) => handleChange('nome', e.target.value)}
              placeholder="Ex: Profissional"
              disabled={submitting}
            />
          </Field>

          <Field label="Preço Mensal (R$)" required>
            <input
              style={inputStyle}
              type="number"
              min="0"
              step="0.01"
              value={form.preco_mensal}
              onChange={(e) => handleChange('preco_mensal', e.target.value)}
              placeholder="199.90"
              disabled={submitting}
            />
          </Field>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <Field label="Limite de Usuários" required hint="Use -1 para ilimitado">
              <input
                style={inputStyle}
                type="number"
                step="1"
                value={form.limite_usuarios}
                onChange={(e) => handleChange('limite_usuarios', e.target.value)}
                placeholder="-1"
                disabled={submitting}
              />
            </Field>

            <Field label="Limite de OS/mês" required hint="Use -1 para ilimitado">
              <input
                style={inputStyle}
                type="number"
                step="1"
                value={form.limite_os_mes}
                onChange={(e) => handleChange('limite_os_mes', e.target.value)}
                placeholder="-1"
                disabled={submitting}
              />
            </Field>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <Field label="Limite de Produtos" hint="Use -1 para ilimitado">
              <input
                style={inputStyle}
                type="number"
                step="1"
                value={form.limite_produtos}
                onChange={(e) => handleChange('limite_produtos', e.target.value)}
                placeholder="-1"
                disabled={submitting}
              />
            </Field>

            <Field label="Limite de Clientes" hint="Use -1 para ilimitado">
              <input
                style={inputStyle}
                type="number"
                step="1"
                value={form.limite_clientes}
                onChange={(e) => handleChange('limite_clientes', e.target.value)}
                placeholder="-1"
                disabled={submitting}
              />
            </Field>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <Field label="Limite de Notas/mês" hint="Use -1 para ilimitado">
              <input
                style={inputStyle}
                type="number"
                step="1"
                value={form.limite_notas_mes}
                onChange={(e) => handleChange('limite_notas_mes', e.target.value)}
                placeholder="-1"
                disabled={submitting}
              />
            </Field>

            <Field label="Preço Nota Excedente (R$)" hint="Por nota acima do limite">
              <input
                style={inputStyle}
                type="number"
                min="0"
                step="0.01"
                value={form.preco_nota_excedente}
                onChange={(e) => handleChange('preco_nota_excedente', e.target.value)}
                placeholder="0.00"
                disabled={submitting}
              />
            </Field>
          </div>

          {/* Canais de alerta liberados pelo plano */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8, paddingTop: 8, borderTop: '1px solid var(--border)' }}>
            <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--muted)' }}>Canais de alerta inclusos no plano</span>
            <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', fontSize: 14, color: 'var(--text)' }}>
              <input type="checkbox" checked={form.alerta_whatsapp}
                onChange={(e) => setForm((p) => ({ ...p, alerta_whatsapp: e.target.checked }))}
                disabled={submitting} style={{ width: 16, height: 16, accentColor: 'var(--accent)' }} />
              💬 Alertas via WhatsApp
            </label>
            <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', fontSize: 14, color: 'var(--text)' }}>
              <input type="checkbox" checked={form.alerta_email}
                onChange={(e) => setForm((p) => ({ ...p, alerta_email: e.target.checked }))}
                disabled={submitting} style={{ width: 16, height: 16, accentColor: 'var(--accent)' }} />
              ✉️ Alertas via E-mail
            </label>
          </div>

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
              {submitting
                ? '⟳ Salvando...'
                : mode === 'create'
                ? 'Criar Plano'
                : 'Salvar Alterações'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function PlanosPage() {
  const [planos, setPlanos] = useState<Plano[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [successMsg, setSuccessMsg] = useState<string | null>(null)

  // Modal state
  const [modalMode, setModalMode] = useState<ModalMode>('create')
  const [editTarget, setEditTarget] = useState<Plano | undefined>(undefined)
  const [showModal, setShowModal] = useState(false)

  // Deactivate confirmation: key = plano id
  const [confirmDeactivate, setConfirmDeactivate] = useState<string | null>(null)
  const [deactivating, setDeactivating] = useState<string | null>(null)
  const [deactivateError, setDeactivateError] = useState<string | null>(null)

  const TABLE_COLS = ['Nome', 'Preço/mês', 'Usuários', 'OS/mês', 'Produtos', 'Clientes', 'Notas/mês', 'Excedente', 'Alertas', 'Oficinas', 'Status', 'Ações']

  const fetchPlanos = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await saasApi.get<{ data: Plano[] }>('/saas/planos')
      setPlanos(res.data.data ?? [])
    } catch {
      setError('Erro ao carregar lista de planos.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchPlanos()
  }, [fetchPlanos])

  function showSuccess(msg: string) {
    setSuccessMsg(msg)
    setTimeout(() => setSuccessMsg(null), 3500)
  }

  function openCreate() {
    setModalMode('create')
    setEditTarget(undefined)
    setShowModal(true)
  }

  function openEdit(plano: Plano) {
    setModalMode('edit')
    setEditTarget(plano)
    setShowModal(true)
  }

  function handleModalSuccess() {
    setShowModal(false)
    showSuccess(modalMode === 'create' ? 'Plano criado com sucesso!' : 'Plano atualizado com sucesso!')
    fetchPlanos()
  }

  function requestDeactivate(planoId: string) {
    setDeactivateError(null)
    setConfirmDeactivate(planoId)
  }

  function cancelDeactivate() {
    setConfirmDeactivate(null)
    setDeactivateError(null)
  }

  async function confirmDeactivateAction(plano: Plano) {
    setDeactivating(plano.id)
    setDeactivateError(null)
    try {
      await saasApi.delete(`/saas/planos/${plano.id}`)
      setConfirmDeactivate(null)
      showSuccess(`Plano "${plano.nome}" desativado.`)
      fetchPlanos()
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string } } }
      const msg =
        axiosErr.response?.data?.message ?? 'Erro ao desativar plano. Tente novamente.'
      setDeactivateError(msg)
    } finally {
      setDeactivating(null)
    }
  }

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

      <div style={{ padding: '32px 32px 40px', color: 'var(--text)', maxWidth: 1440, margin: '0 auto' }}>
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
              Planos
            </h1>
            <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
              Gerencie os planos de assinatura da plataforma
            </p>
          </div>

          <button
            onClick={openCreate}
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
            onMouseEnter={(e) => {
              ;(e.currentTarget as HTMLButtonElement).style.opacity = '0.85'
            }}
            onMouseLeave={(e) => {
              ;(e.currentTarget as HTMLButtonElement).style.opacity = '1'
            }}
          >
            + Novo Plano
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
                  Array.from({ length: 4 }).map((_, i) => (
                    <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                      <td style={{ padding: '13px 16px' }}>
                        <Skeleton width="65%" height={14} />
                      </td>
                      {Array.from({ length: 9 }).map((__, j) => (
                        <td key={j} style={{ padding: '13px 16px' }}>
                          <Skeleton height={14} width={j === 0 ? '55%' : '40%'} />
                        </td>
                      ))}
                      <td style={{ padding: '13px 16px' }}>
                        <Skeleton width={56} height={22} />
                      </td>
                      <td style={{ padding: '13px 16px' }}>
                        <div style={{ display: 'flex', gap: 8 }}>
                          <Skeleton width={60} height={28} />
                          <Skeleton width={72} height={28} />
                        </div>
                      </td>
                    </tr>
                  ))
                ) : planos.length === 0 ? (
                  <tr>
                    <td
                      colSpan={12}
                      style={{
                        padding: '48px 16px',
                        textAlign: 'center',
                        color: 'var(--muted)',
                        fontSize: 14,
                      }}
                    >
                      Nenhum plano cadastrado ainda.
                    </td>
                  </tr>
                ) : (
                  planos.map((plano, idx) => {
                    const isLast = idx === planos.length - 1
                    const isPendingDeactivate = confirmDeactivate === plano.id
                    const isDeactivating = deactivating === plano.id

                    return (
                      <tr
                        key={plano.id}
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
                        {/* Nome */}
                        <td style={{ padding: '12px 16px', minWidth: 160 }}>
                          <div style={{ fontSize: 14, fontWeight: 600, color: 'var(--text)' }}>
                            {plano.nome}
                          </div>
                        </td>

                        {/* Preço/mês */}
                        <td style={{ padding: '12px 16px', whiteSpace: 'nowrap' }}>
                          <span
                            style={{
                              fontFamily: "'JetBrains Mono', monospace",
                              fontSize: 14,
                              color: 'var(--text)',
                            }}
                          >
                            {formatarPreco(plano.preco_mensal)}
                          </span>
                        </td>

                        {/* Limite usuários */}
                        <td style={{ padding: '12px 16px' }}>
                          <span
                            style={{
                              fontFamily: "'JetBrains Mono', monospace",
                              fontSize: 14,
                              color:
                                plano.limite_usuarios === -1
                                  ? 'var(--muted)'
                                  : 'var(--text)',
                            }}
                          >
                            {displayLimite(plano.limite_usuarios)}
                          </span>
                        </td>

                        {/* Limite OS/mês */}
                        <td style={{ padding: '12px 16px' }}>
                          <span
                            style={{
                              fontFamily: "'JetBrains Mono', monospace",
                              fontSize: 14,
                              color: plano.limite_os_mes === -1 ? 'var(--muted)' : 'var(--text)',
                            }}
                          >
                            {displayLimite(plano.limite_os_mes)}
                          </span>
                        </td>

                        {/* Limite produtos */}
                        <td style={{ padding: '12px 16px' }}>
                          <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 14, color: plano.limite_produtos === -1 ? 'var(--muted)' : 'var(--text)' }}>
                            {displayLimite(plano.limite_produtos)}
                          </span>
                        </td>

                        {/* Limite clientes */}
                        <td style={{ padding: '12px 16px' }}>
                          <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 14, color: plano.limite_clientes === -1 ? 'var(--muted)' : 'var(--text)' }}>
                            {displayLimite(plano.limite_clientes)}
                          </span>
                        </td>

                        {/* Limite notas/mês */}
                        <td style={{ padding: '12px 16px' }}>
                          <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 14, color: plano.limite_notas_mes === -1 ? 'var(--muted)' : 'var(--text)' }}>
                            {displayLimite(plano.limite_notas_mes)}
                          </span>
                        </td>

                        {/* Preço excedente */}
                        <td style={{ padding: '12px 16px', whiteSpace: 'nowrap' }}>
                          <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 14, color: parseFloat(plano.preco_nota_excedente) === 0 ? 'var(--muted)' : 'var(--accent)' }}>
                            {parseFloat(plano.preco_nota_excedente) === 0 ? '—' : formatarPreco(plano.preco_nota_excedente)}
                          </span>
                        </td>

                        {/* Canais de alerta */}
                        <td style={{ padding: '12px 16px', whiteSpace: 'nowrap' }}>
                          <span style={{ fontSize: 15 }}>
                            {plano.alerta_whatsapp ? '💬' : ''}{plano.alerta_email ? '✉️' : ''}
                            {!plano.alerta_whatsapp && !plano.alerta_email && <span style={{ color: 'var(--muted)', fontSize: 13 }}>—</span>}
                          </span>
                        </td>

                        {/* Oficinas count */}
                        <td style={{ padding: '12px 16px', textAlign: 'center' }}>
                          <span
                            style={{
                              fontFamily: "'JetBrains Mono', monospace",
                              fontSize: 14,
                              color: 'var(--text)',
                            }}
                          >
                            {plano.oficinas_count}
                          </span>
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
                              textTransform: 'uppercase',
                              whiteSpace: 'nowrap',
                              background: plano.ativo
                                ? 'rgba(67,160,71,.15)'
                                : 'rgba(229,57,53,.15)',
                              color: plano.ativo ? 'var(--success)' : 'var(--danger)',
                            }}
                          >
                            {plano.ativo ? 'Ativo' : 'Inativo'}
                          </span>
                        </td>

                        {/* Ações */}
                        <td style={{ padding: '12px 16px', whiteSpace: 'nowrap' }}>
                          {isPendingDeactivate ? (
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                              <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                <span style={{ fontSize: 12, color: 'var(--muted)' }}>
                                  Confirmar desativação?
                                </span>
                              </div>
                              <div style={{ display: 'flex', gap: 6 }}>
                                <button
                                  onClick={() => confirmDeactivateAction(plano)}
                                  disabled={isDeactivating}
                                  style={{
                                    background: 'rgba(229,57,53,.15)',
                                    border: '1px solid var(--danger)',
                                    color: 'var(--danger)',
                                    borderRadius: 6,
                                    padding: '4px 12px',
                                    fontSize: 12,
                                    fontWeight: 700,
                                    cursor: isDeactivating ? 'not-allowed' : 'pointer',
                                    opacity: isDeactivating ? 0.6 : 1,
                                  }}
                                >
                                  {isDeactivating ? '⟳' : 'Sim, desativar'}
                                </button>
                                <button
                                  onClick={cancelDeactivate}
                                  disabled={isDeactivating}
                                  style={{
                                    background: 'none',
                                    border: '1px solid var(--border)',
                                    color: 'var(--muted)',
                                    borderRadius: 6,
                                    padding: '4px 10px',
                                    fontSize: 12,
                                    cursor: 'pointer',
                                  }}
                                >
                                  Cancelar
                                </button>
                              </div>
                              {/* Inline deactivate error */}
                              {deactivateError && confirmDeactivate === plano.id && (
                                <span
                                  style={{ fontSize: 12, color: 'var(--danger)', maxWidth: 240 }}
                                >
                                  {deactivateError}
                                </span>
                              )}
                            </div>
                          ) : (
                            <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                              {/* Editar */}
                              <button
                                onClick={() => openEdit(plano)}
                                style={{
                                  background: 'rgba(245,166,35,.1)',
                                  border: '1px solid rgba(245,166,35,.3)',
                                  color: 'var(--accent)',
                                  borderRadius: 6,
                                  padding: '5px 14px',
                                  fontSize: 13,
                                  fontWeight: 600,
                                  cursor: 'pointer',
                                  transition: 'background 0.15s',
                                }}
                                onMouseEnter={(e) => {
                                  ;(e.currentTarget as HTMLButtonElement).style.background =
                                    'rgba(245,166,35,.2)'
                                }}
                                onMouseLeave={(e) => {
                                  ;(e.currentTarget as HTMLButtonElement).style.background =
                                    'rgba(245,166,35,.1)'
                                }}
                              >
                                Editar
                              </button>

                              {/* Desativar — only if active */}
                              {plano.ativo && (
                                <button
                                  onClick={() => requestDeactivate(plano.id)}
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
                                  Desativar
                                </button>
                              )}
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
        </div>
      </div>

      {/* Create / Edit modal */}
      {showModal && (
        <PlanoModal
          mode={modalMode}
          initial={editTarget}
          onClose={() => setShowModal(false)}
          onSuccess={handleModalSuccess}
        />
      )}
    </>
  )
}
