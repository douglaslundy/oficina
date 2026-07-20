'use client'

import { useState, useEffect, useCallback } from 'react'
import { useParams, useRouter } from 'next/navigation'
import Link from 'next/link'
import saasApi from '@/lib/saas-api'
import { formatarDataUTC, formatarDataHora } from '@/lib/formatters'
import { ServicosAvulsosSection } from '@/components/saas/ServicosAvulsosSection'
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
  status: 'ATIVA' | 'SUSPENSA' | 'CANCELADA' | 'INADIMPLENTE'
  plano: { id: string; nome: string; preco_mensal: string } | null
  admin_nome?: string | null
  admin_email: string
  users_count: number
  os_mes_count: number
  criado_em: string
  asaas_customer_id?: string | null
  asaas_subscription_id?: string | null
  provedor_fiscal?: 'SPEDY' | 'FOCUS' | null
  emissao_fiscal_modo?: 'MANUAL' | 'AUTOMATICO' | null
  ciclo_cobranca?: 'MENSAL' | 'ANUAL'
  proximo_vencimento?: string | null
  dias_antecedencia_cobranca?: number | null
  dias_suspensao_vencido?: number | null
  gateway?: 'ASAAS' | 'MERCADOPAGO'
  mp_customer_id?: string | null
  mp_subscription_id?: string | null
}

interface AsaasStatus {
  gateway?: 'ASAAS' | 'MERCADOPAGO'
  customer_id?: string | null
  subscription_id?: string | null
  asaas_customer_id: string | null
  asaas_subscription_id: string | null
  customer: Record<string, unknown> | null
  subscription: Record<string, unknown> | null
  ultimos_pagamentos: Array<{
    id: string
    value: number
    dueDate: string
    status: string
    paymentDate?: string
    invoiceUrl?: string
    bankSlipUrl?: string
  }>
  error?: boolean
  message?: string
}

interface Cobranca {
  id: string
  mes_referencia: string
  valor: string
  status: 'PAGA' | 'PENDENTE' | 'VENCIDA' | 'CANCELADA' | 'ESTORNADA'
  vencimento: string
  pago_em: string | null
  gateway?: 'ASAAS' | 'MERCADOPAGO' | null
  asaas_payment_id?: string | null
  mp_payment_id?: string | null
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function fmtBRL(v: number | string): string {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v))
}

function statusColor(s: string): string {
  return s === 'ATIVA' ? 'var(--success)'
    : s === 'SUSPENSA' || s === 'INADIMPLENTE' ? 'var(--accent)'
    : 'var(--danger)'
}

function statusBg(s: string): string {
  return s === 'ATIVA' ? 'rgba(67,160,71,.15)'
    : s === 'SUSPENSA' || s === 'INADIMPLENTE' ? 'rgba(245,166,35,.15)'
    : 'rgba(229,57,53,.15)'
}

function cobrancaColor(s: string) {
  return s === 'PAGA' ? { bg: 'rgba(67,160,71,.15)', color: 'var(--success)' }
    : s === 'VENCIDA' ? { bg: 'rgba(229,57,53,.15)', color: 'var(--danger)' }
    : s === 'ESTORNADA' ? { bg: 'rgba(30,136,229,.15)', color: 'var(--info)' }
    : s === 'CANCELADA' ? { bg: 'rgba(122,128,144,.15)', color: 'var(--muted)' }
    : { bg: 'rgba(245,166,35,.15)', color: 'var(--accent)' }
}

function asaasPaymentColor(s: string) {
  return (s === 'RECEIVED' || s === 'CONFIRMED') ? 'var(--success)'
    : s === 'OVERDUE' ? 'var(--danger)'
    : 'var(--accent)'
}

const MESES_PT: Record<number, string> = { 0:'Jan',1:'Fev',2:'Mar',3:'Abr',4:'Mai',5:'Jun',6:'Jul',7:'Ago',8:'Set',9:'Out',10:'Nov',11:'Dez' }
function fmtMes(iso: string): string {
  const [y, m] = iso.split('-').map(Number)
  return `${MESES_PT[m - 1]}/${y}`
}

function Sk({ w, h }: { w?: string | number; h?: number }) {
  return <div style={{ width: w ?? '100%', height: h ?? 14, borderRadius: 6, background: 'var(--border)', animation: 'pulse 1.4s ease-in-out infinite' }} />
}

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', padding: '8px 0', borderBottom: '1px solid var(--border)' }}>
      <span style={{ fontSize: 13, color: 'var(--muted)' }}>{label}</span>
      <span style={{ fontSize: 13, color: 'var(--text)', fontWeight: 500, textAlign: 'right', maxWidth: '60%' }}>{value}</span>
    </div>
  )
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function OficinaDetailPage() {
  const { id } = useParams<{ id: string }>()
  const router = useRouter()

  const [oficina, setOficina] = useState<Oficina | null>(null)
  const [loadingOficina, setLoadingOficina] = useState(true)

  const [asaas, setAsaas] = useState<AsaasStatus | null>(null)
  const [loadingAsaas, setLoadingAsaas] = useState(true)

  const [cobrancas, setCobrancas] = useState<Cobranca[]>([])
  const [loadingCobrancas, setLoadingCobrancas] = useState(true)

  const [actionLoading, setActionLoading] = useState<string | null>(null)
  const [toast, setToast] = useState<{ msg: string; type: 'ok' | 'err' } | null>(null)

  const [gerarModal, setGerarModal] = useState(false)
  const [gerarValor, setGerarValor] = useState('')
  const [gerarVencimento, setGerarVencimento] = useState('')
  const [gerarLoading, setGerarLoading] = useState(false)
  const [gerarError, setGerarError] = useState<string | null>(null)

  const [creatingCustomer, setCreatingCustomer] = useState(false)
  const [gerandoCiclo, setGerandoCiclo] = useState(false)
  const [conciliando, setConciliando] = useState(false)
  const [estornandoId, setEstornandoId] = useState<string | null>(null)

  const [confirmDialog, setConfirmDialog] = useState<{ message: string; onConfirm: () => void; danger?: boolean } | null>(null)

  const [provFiscal, setProvFiscal] = useState<string>('')
  const [modoFiscal, setModoFiscal] = useState<string>('')
  const [savingFiscal, setSavingFiscal] = useState(false)

  const [proximoVencimento, setProximoVencimento] = useState('')
  const [diasAntecedenciaOverride, setDiasAntecedenciaOverride] = useState('')
  const [diasSuspensaoOverride, setDiasSuspensaoOverride] = useState('')
  const [savingCobranca, setSavingCobranca] = useState(false)
  const [changingCiclo, setChangingCiclo] = useState(false)

  const [showEditModal, setShowEditModal] = useState(false)
  const [planos, setPlanos] = useState<Plano[]>([])

  const showToast = (msg: string, type: 'ok' | 'err' = 'ok') => {
    setToast({ msg, type })
    setTimeout(() => setToast(null), 3500)
  }

  const fetchOficina = useCallback(async () => {
    try {
      const res = await saasApi.get<{ data: Oficina }>(`/saas/oficinas/${id}`)
      setOficina(res.data.data)
      setProvFiscal(res.data.data.provedor_fiscal ?? '')
      setModoFiscal(res.data.data.emissao_fiscal_modo ?? '')
      setProximoVencimento(res.data.data.proximo_vencimento ?? '')
      setDiasAntecedenciaOverride(res.data.data.dias_antecedencia_cobranca != null ? String(res.data.data.dias_antecedencia_cobranca) : '')
      setDiasSuspensaoOverride(res.data.data.dias_suspensao_vencido != null ? String(res.data.data.dias_suspensao_vencido) : '')
    } catch {
      showToast('Erro ao carregar oficina.', 'err')
    } finally {
      setLoadingOficina(false)
    }
  }, [id])

  const fetchAsaas = useCallback(async () => {
    setLoadingAsaas(true)
    try {
      const res = await saasApi.get<AsaasStatus>(`/saas/oficinas/${id}/asaas`)
      setAsaas(res.data)
    } catch {
      setAsaas(null)
    } finally {
      setLoadingAsaas(false)
    }
  }, [id])

  const fetchCobrancas = useCallback(async () => {
    try {
      const res = await saasApi.get<{ data: Cobranca[] }>(`/saas/cobrancas/por/${id}`)
      setCobrancas(res.data.data ?? [])
    } catch {
      // non-critical
    } finally {
      setLoadingCobrancas(false)
    }
  }, [id])

  useEffect(() => {
    fetchOficina()
    fetchAsaas()
    fetchCobrancas()
    saasApi.get<{ data: Plano[] }>('/saas/planos')
      .then(res => setPlanos(res.data.data ?? []))
      .catch(() => setPlanos([]))
  }, [fetchOficina, fetchAsaas, fetchCobrancas])

  function askConfirm(message: string, onConfirm: () => void, danger = false) {
    setConfirmDialog({ message, onConfirm, danger })
  }

  async function handleAction(action: string, label: string) {
    setActionLoading(action)
    try {
      await saasApi.post(`/saas/oficinas/${id}/${action}`)
      showToast(`${label} realizado com sucesso.`)
      fetchOficina()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro inesperado.'
      showToast(msg, 'err')
    } finally {
      setActionLoading(null)
    }
  }

  async function handleSincronizar() {
    setActionLoading('sync')
    try {
      const res = await saasApi.post<{ message: string }>(`/saas/oficinas/${id}/sincronizar-cobrancas`)
      showToast(res.data.message)
      fetchCobrancas()
      fetchAsaas()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao sincronizar.'
      showToast(msg, 'err')
    } finally {
      setActionLoading(null)
    }
  }

  async function handleGerarCobranca(e: React.FormEvent) {
    e.preventDefault()
    setGerarLoading(true)
    setGerarError(null)
    try {
      const res = await saasApi.post<{ message: string }>(`/saas/oficinas/${id}/gerar-cobranca`, {
        valor: gerarValor,
        vencimento: gerarVencimento,
      })
      showToast(res.data.message)
      setGerarModal(false)
      setGerarValor('')
      setGerarVencimento('')
      fetchCobrancas()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
        ?? 'Erro ao gerar cobrança. Verifique sua conexão e tente novamente — se o problema persistir, confira em "Cobranças Locais" se ela já não foi criada antes de tentar de novo.'
      setGerarError(msg)
      showToast(msg, 'err')
    } finally {
      setGerarLoading(false)
    }
  }

  function handleCancelarCobranca(cId: string) {
    askConfirm('Cancelar esta cobrança?', async () => {
      try {
        await saasApi.delete(`/saas/cobrancas/${cId}`)
        showToast('Cobrança cancelada.')
        fetchCobrancas()
      } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao cancelar.'
        showToast(msg, 'err')
      }
    }, true)
  }

  function handleEstornarCobranca(c: Cobranca) {
    askConfirm(`Estornar o pagamento de ${fmtBRL(c.valor)}? Essa ação devolve o dinheiro no gateway e não pode ser desfeita.`, async () => {
      setEstornandoId(c.id)
      try {
        const res = await saasApi.post<{ message: string }>(`/saas/cobrancas/${c.id}/estornar`)
        showToast(res.data.message)
        fetchCobrancas()
      } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao estornar pagamento.'
        showToast(msg, 'err')
      } finally {
        setEstornandoId(null)
      }
    }, true)
  }

  async function handleConciliar() {
    setConciliando(true)
    try {
      const res = await saasApi.post<{ message: string }>('/saas/cobrancas/conciliar', null, { params: { oficina_id: id } })
      showToast(res.data.message)
      fetchCobrancas()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao conciliar pagamentos.'
      showToast(msg, 'err')
    } finally {
      setConciliando(false)
    }
  }

  async function handleCriarCustomer() {
    setCreatingCustomer(true)
    try {
      const res = await saasApi.post<{ message: string }>(`/saas/oficinas/${id}/criar-customer-gateway`)
      showToast(res.data.message)
      fetchOficina()
      fetchAsaas()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao criar cliente no gateway.'
      showToast(msg, 'err')
    } finally {
      setCreatingCustomer(false)
    }
  }

  async function handleGerarCicloManual() {
    setGerandoCiclo(true)
    try {
      const res = await saasApi.post<{ message: string }>(`/saas/oficinas/${id}/gerar-cobranca-ciclo`)
      showToast(res.data.message)
      fetchCobrancas()
      fetchOficina()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao gerar cobrança do ciclo.'
      showToast(msg, 'err')
    } finally {
      setGerandoCiclo(false)
    }
  }

  async function salvarFiscal() {
    setSavingFiscal(true)
    try {
      await saasApi.put(`/saas/oficinas/${id}/fiscal`, {
        provedor_fiscal: provFiscal || null,
        emissao_fiscal_modo: modoFiscal || null,
      })
      showToast('Configuração fiscal da oficina salva.')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao salvar.'
      showToast(msg, 'err')
    } finally {
      setSavingFiscal(false)
    }
  }

  async function salvarCobranca() {
    setSavingCobranca(true)
    try {
      const payload: Record<string, string | number | null> = {}
      if (proximoVencimento) payload.proximo_vencimento = proximoVencimento
      payload.dias_antecedencia_cobranca = diasAntecedenciaOverride ? parseInt(diasAntecedenciaOverride, 10) : null
      payload.dias_suspensao_vencido = diasSuspensaoOverride ? parseInt(diasSuspensaoOverride, 10) : null

      await saasApi.put(`/saas/oficinas/${id}`, payload)
      showToast('Configurações de cobrança salvas.')
      fetchOficina()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao salvar.'
      showToast(msg, 'err')
    } finally {
      setSavingCobranca(false)
    }
  }

  function mudarCiclo(ciclo: 'MENSAL' | 'ANUAL') {
    askConfirm(
      `Mudar o ciclo de cobrança para ${ciclo === 'ANUAL' ? 'ANUAL' : 'MENSAL'}? Isso recalcula o próximo vencimento e cancela cobranças pendentes do ciclo atual.`,
      async () => {
        setChangingCiclo(true)
        try {
          await saasApi.post(`/saas/oficinas/${id}/mudar-ciclo`, { ciclo })
          showToast('Ciclo de cobrança atualizado.')
          fetchOficina()
          fetchCobrancas()
        } catch (e: unknown) {
          const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao mudar ciclo.'
          showToast(msg, 'err')
        } finally {
          setChangingCiclo(false)
        }
      },
    )
  }

  function handleCancelarAssinatura() {
    askConfirm('ATENÇÃO: Esta ação cancela a assinatura no gateway de pagamento e desativa a oficina. Continuar?', async () => {
      setActionLoading('cancelar-assinatura')
      try {
        await saasApi.post(`/saas/oficinas/${id}/cancelar-assinatura`)
        showToast('Assinatura cancelada.')
        fetchOficina()
        fetchAsaas()
      } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao cancelar assinatura.'
        showToast(msg, 'err')
      } finally {
        setActionLoading(null)
      }
    }, true)
  }

  const sBtn = (label: string, onClick: () => void, variant: 'primary' | 'danger' | 'muted' = 'muted', disabled = false) => (
    <button
      onClick={onClick}
      disabled={disabled || !!actionLoading}
      style={{
        padding: '8px 16px', borderRadius: 8, fontWeight: 700, fontSize: 13,
        fontFamily: "'Barlow Condensed', sans-serif", letterSpacing: '0.02em',
        cursor: disabled || !!actionLoading ? 'not-allowed' : 'pointer',
        opacity: disabled || !!actionLoading ? 0.5 : 1,
        background: variant === 'primary' ? 'var(--accent)'
          : variant === 'danger' ? 'rgba(229,57,53,.15)'
          : 'var(--card)',
        color: variant === 'primary' ? '#000'
          : variant === 'danger' ? 'var(--danger)'
          : 'var(--muted)',
        border: variant === 'muted' ? '1px solid var(--border)' : variant === 'danger' ? 'none' : 'none',
      }}
    >
      {disabled && actionLoading ? '…' : label}
    </button>
  )

  return (
    <>
      <style>{`@keyframes pulse{0%,100%{opacity:1}50%{opacity:.45}} @keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:none;opacity:1}}`}</style>

      {/* Toast */}
      {toast && (
        <div style={{
          position: 'fixed', bottom: 24, right: 24, zIndex: 9999,
          padding: '12px 20px', borderRadius: 8, fontSize: 14, fontWeight: 500,
          background: toast.type === 'ok' ? 'var(--success)' : 'var(--danger)', color: '#fff',
          animation: 'slideIn 0.2s ease',
        }}>
          {toast.msg}
        </div>
      )}

      {/* Gerar cobrança modal */}
      {gerarModal && (
        <div style={{
          position: 'fixed', inset: 0, background: 'rgba(0,0,0,.6)',
          display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000,
        }}>
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, padding: 28, width: 360 }}>
            <h3 className="font-display" style={{ fontSize: 18, fontWeight: 800, color: 'var(--text)', marginBottom: 20 }}>
              Gerar Cobrança Avulsa
            </h3>
            {gerarError && (
              <div style={{ background: 'rgba(229,57,53,.1)', border: '1px solid var(--danger)', borderRadius: 8, padding: '10px 14px', color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>
                {gerarError}
              </div>
            )}
            <form onSubmit={handleGerarCobranca}>
              <div style={{ marginBottom: 14 }}>
                <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)', display: 'block', marginBottom: 6 }}>Valor (R$)</label>
                <input type="number" step="0.01" min="1" required value={gerarValor} onChange={e => setGerarValor(e.target.value)}
                  style={{ width: '100%', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', fontSize: 14, padding: '9px 12px', outline: 'none' }} />
              </div>
              <div style={{ marginBottom: 20 }}>
                <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)', display: 'block', marginBottom: 6 }}>Data de Vencimento</label>
                <input type="date" required value={gerarVencimento} onChange={e => setGerarVencimento(e.target.value)}
                  style={{ width: '100%', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', fontSize: 14, padding: '9px 12px', outline: 'none', colorScheme: 'dark' }} />
              </div>
              <div style={{ display: 'flex', gap: 8 }}>
                <button type="submit" disabled={gerarLoading}
                  style={{ flex: 1, padding: '9px', background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 8, fontWeight: 700, fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif", cursor: 'pointer' }}>
                  {gerarLoading ? 'Gerando…' : 'Gerar Cobrança'}
                </button>
                <button type="button" onClick={() => { setGerarModal(false); setGerarError(null) }}
                  style={{ padding: '9px 16px', background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 8, fontSize: 14, cursor: 'pointer' }}>
                  Cancelar
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal de confirmação genérico (substitui window.confirm) */}
      {confirmDialog && (
        <div style={{
          position: 'fixed', inset: 0, background: 'rgba(0,0,0,.6)',
          display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000,
        }}>
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, padding: 28, width: 380 }}>
            <h3 className="font-display" style={{ fontSize: 18, fontWeight: 800, color: 'var(--text)', marginBottom: 16 }}>
              Confirmar ação
            </h3>
            <p style={{ fontSize: 14, color: 'var(--text)', marginBottom: 24, lineHeight: 1.5 }}>
              {confirmDialog.message}
            </p>
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
              <button onClick={() => setConfirmDialog(null)}
                style={{ padding: '9px 16px', background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 8, fontSize: 14, cursor: 'pointer' }}>
                Cancelar
              </button>
              <button
                onClick={() => { const fn = confirmDialog.onConfirm; setConfirmDialog(null); fn() }}
                style={{
                  padding: '9px 20px',
                  background: confirmDialog.danger ? 'rgba(229,57,53,.15)' : 'var(--accent)',
                  color: confirmDialog.danger ? 'var(--danger)' : '#000',
                  border: confirmDialog.danger ? '1px solid var(--danger)' : 'none',
                  borderRadius: 8, fontWeight: 700, fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif", cursor: 'pointer',
                }}
              >
                Confirmar
              </button>
            </div>
          </div>
        </div>
      )}

      {showEditModal && oficina && (
        <EditOficinaModal
          oficina={oficina}
          planos={planos}
          onClose={() => setShowEditModal(false)}
          onSuccess={() => {
            setShowEditModal(false)
            fetchOficina()
            showToast('Dados da oficina atualizados.')
          }}
        />
      )}

      <div style={{ padding: '32px 32px 48px', maxWidth: 1200, color: 'var(--text)', margin: '0 auto' }}>
        {/* Breadcrumb */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 24 }}>
          <Link href="/saas-admin/oficinas" style={{ color: 'var(--muted)', fontSize: 14, textDecoration: 'none' }}>
            Oficinas
          </Link>
          <span style={{ color: 'var(--muted)', fontSize: 12 }}>/</span>
          {loadingOficina
            ? <Sk w={120} h={14} />
            : <span style={{ fontSize: 14, fontWeight: 600, color: 'var(--text)' }}>{oficina?.nome}</span>
          }
        </div>

        {/* Header */}
        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: 32, flexWrap: 'wrap', gap: 16 }}>
          <div>
            {loadingOficina
              ? <Sk w={220} h={28} />
              : <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>{oficina?.nome}</h1>
            }
            {!loadingOficina && oficina && (
              <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 8 }}>
                <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 13, color: 'var(--muted)' }}>
                  /{oficina.slug}
                </span>
                <span style={{
                  padding: '2px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700,
                  background: statusBg(oficina.status), color: statusColor(oficina.status),
                  textTransform: 'uppercase', letterSpacing: '0.04em',
                }}>
                  {oficina.status}
                </span>
              </div>
            )}
          </div>

          {!loadingOficina && oficina && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              {sBtn('Editar Dados', () => setShowEditModal(true), 'muted')}
              {oficina.status === 'ATIVA' && sBtn('Suspender', () => askConfirm('Confirmar: Suspender oficina?', () => handleAction('suspender', 'Suspender oficina'), true), 'danger', actionLoading === 'suspender')}
              {(oficina.status === 'SUSPENSA' || oficina.status === 'INADIMPLENTE') && sBtn('Reativar', () => askConfirm('Confirmar: Reativar oficina?', () => handleAction('reativar', 'Reativar oficina')), 'primary', actionLoading === 'reativar')}
              {oficina.status !== 'CANCELADA' && sBtn('Cancelar Assinatura', handleCancelarAssinatura, 'danger', actionLoading === 'cancelar-assinatura')}
            </div>
          )}
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20 }}>
          {/* ── Info Geral ── */}
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: 20 }}>
            <h2 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: '0 0 12px' }}>Informações Gerais</h2>
            {loadingOficina ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                {[...Array(6)].map((_, i) => <Sk key={i} h={14} />)}
              </div>
            ) : oficina ? (
              <>
                <InfoRow label="CNPJ" value={<span style={{ fontFamily: "'JetBrains Mono', monospace" }}>{oficina.cnpj}</span>} />
                <InfoRow label="Plano" value={oficina.plano?.nome ?? '—'} />
                <InfoRow label="Valor/mês" value={oficina.plano ? fmtBRL(oficina.plano.preco_mensal) : '—'} />
                {oficina.admin_nome && <InfoRow label="Admin" value={oficina.admin_nome} />}
                <InfoRow label="E-mail Admin" value={oficina.admin_email} />
                <InfoRow label="Usuários" value={oficina.users_count} />
                <InfoRow label="OS neste mês" value={oficina.os_mes_count} />
                <InfoRow label="Cadastro" value={formatarDataHora(oficina.criado_em)} />
              </>
            ) : null}
          </div>

          {/* ── Gateway de Pagamento (Asaas/Mercado Pago) ── */}
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: 20 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
              <h2 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: 0 }}>
                {oficina?.gateway === 'MERCADOPAGO' ? 'Mercado Pago' : 'Asaas'}
              </h2>
              {!loadingAsaas && (
                <button onClick={fetchAsaas} style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, padding: '4px 10px', fontSize: 12, cursor: 'pointer' }}>
                  Atualizar
                </button>
              )}
            </div>

            {loadingAsaas ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                {[...Array(4)].map((_, i) => <Sk key={i} h={14} />)}
              </div>
            ) : asaas?.error ? (
              <p style={{ color: 'var(--danger)', fontSize: 13 }}>{asaas.message}</p>
            ) : (
              <>
                <InfoRow label="Customer ID" value={
                  (asaas?.customer_id ?? asaas?.asaas_customer_id)
                    ? <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 12 }}>{asaas?.customer_id ?? asaas?.asaas_customer_id}</span>
                    : <span style={{ color: 'var(--muted)' }}>Não vinculado</span>
                } />
                <InfoRow label="Subscription ID (legado)" value={
                  (asaas?.subscription_id ?? asaas?.asaas_subscription_id)
                    ? <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 12 }}>{asaas?.subscription_id ?? asaas?.asaas_subscription_id}</span>
                    : <span style={{ color: 'var(--muted)' }}>Não usado (motor de cobrança local)</span>
                } />
                {asaas?.subscription && (
                  <>
                    <InfoRow label="Status da assinatura" value={
                      <span style={{ color: asaas.subscription['status'] === 'ACTIVE' || asaas.subscription['status'] === 'authorized' ? 'var(--success)' : 'var(--danger)', fontWeight: 600 }}>
                        {String(asaas.subscription['status'] ?? '—')}
                      </span>
                    } />
                    <InfoRow label="Próx. vencimento" value={
                      asaas.subscription['nextDueDate']
                        ? formatarDataUTC(String(asaas.subscription['nextDueDate']))
                        : '—'
                    } />
                    <InfoRow label="Valor mensal" value={fmtBRL(Number(asaas.subscription['value'] ?? 0))} />
                  </>
                )}
                {asaas?.customer && (
                  <InfoRow label="E-mail no gateway" value={String(asaas.customer['email'] ?? '—')} />
                )}
                {!loadingAsaas && !(asaas?.customer_id ?? asaas?.asaas_customer_id) && (
                  <button onClick={handleCriarCustomer} disabled={creatingCustomer}
                    style={{ marginTop: 14, width: '100%', padding: '9px', background: creatingCustomer ? 'var(--border)' : 'var(--accent)', color: creatingCustomer ? 'var(--muted)' : '#000', border: 'none', borderRadius: 8, fontWeight: 700, fontSize: 13, fontFamily: "'Barlow Condensed', sans-serif", cursor: creatingCustomer ? 'not-allowed' : 'pointer' }}>
                    {creatingCustomer ? 'Criando…' : `Criar cliente no ${oficina?.gateway === 'MERCADOPAGO' ? 'Mercado Pago' : 'Asaas'}`}
                  </button>
                )}
              </>
            )}
          </div>
        </div>

        {/* ── Fiscal (provedor por oficina) ── */}
        <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: 20, marginTop: 20 }}>
          <h2 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: '0 0 4px' }}>Emissão Fiscal</h2>
          <p style={{ fontSize: 13, color: 'var(--muted)', margin: '0 0 16px' }}>
            Sobrescreve o provedor/modo globais só para esta oficina. Deixe em &quot;Padrão da plataforma&quot; para herdar.
          </p>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, maxWidth: 560 }}>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)', display: 'block', marginBottom: 6 }}>Provedor</label>
              <select value={provFiscal} onChange={e => setProvFiscal(e.target.value)}
                style={{ width: '100%', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', fontSize: 14, padding: '9px 12px', outline: 'none' }}>
                <option value="">Padrão da plataforma</option>
                <option value="SPEDY">Spedy</option>
                <option value="FOCUS">Focus NFe</option>
              </select>
            </div>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)', display: 'block', marginBottom: 6 }}>Modo de emissão</label>
              <select value={modoFiscal} onChange={e => setModoFiscal(e.target.value)}
                style={{ width: '100%', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', fontSize: 14, padding: '9px 12px', outline: 'none' }}>
                <option value="">Padrão da plataforma</option>
                <option value="MANUAL">Manual</option>
                <option value="AUTOMATICO">Automático</option>
              </select>
            </div>
          </div>
          <button onClick={salvarFiscal} disabled={savingFiscal}
            style={{ marginTop: 16, padding: '8px 18px', background: savingFiscal ? 'var(--border)' : 'var(--accent)', color: savingFiscal ? 'var(--muted)' : '#000', border: 'none', borderRadius: 8, fontWeight: 700, fontSize: 13, fontFamily: "'Barlow Condensed', sans-serif", cursor: savingFiscal ? 'not-allowed' : 'pointer' }}>
            {savingFiscal ? 'Salvando…' : 'Salvar Fiscal'}
          </button>
        </div>

        {/* ── Cobrança Recorrente ── */}
        <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: 20, marginTop: 20 }}>
          <h2 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: '0 0 4px' }}>Cobrança Recorrente</h2>
          <p style={{ fontSize: 13, color: 'var(--muted)', margin: '0 0 16px' }}>
            Deixe os dias em branco para herdar o padrão global (Configurações).
          </p>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16 }}>
            <span style={{ fontSize: 13, color: 'var(--muted)' }}>Ciclo atual:</span>
            <span style={{ padding: '2px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700, background: 'rgba(245,166,35,.15)', color: 'var(--accent)' }}>
              {oficina?.ciclo_cobranca ?? 'MENSAL'}
            </span>
            {oficina?.ciclo_cobranca !== 'ANUAL' && (
              <button onClick={() => mudarCiclo('ANUAL')} disabled={changingCiclo}
                style={{ padding: '5px 12px', background: 'none', border: '1px solid var(--accent)', color: 'var(--accent)', borderRadius: 6, fontSize: 12, cursor: 'pointer' }}>
                Mudar para Anual
              </button>
            )}
            {oficina?.ciclo_cobranca === 'ANUAL' && (
              <button onClick={() => mudarCiclo('MENSAL')} disabled={changingCiclo}
                style={{ padding: '5px 12px', background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, fontSize: 12, cursor: 'pointer' }}>
                Voltar para Mensal
              </button>
            )}
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 16, maxWidth: 700 }}>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)', display: 'block', marginBottom: 6 }}>Próximo vencimento</label>
              <input type="date" value={proximoVencimento} onChange={e => setProximoVencimento(e.target.value)}
                style={{ width: '100%', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', fontSize: 14, padding: '9px 12px', outline: 'none', colorScheme: 'dark' }} />
            </div>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)', display: 'block', marginBottom: 6 }}>Dias de antecedência (override)</label>
              <input type="number" min={1} max={60} value={diasAntecedenciaOverride} onChange={e => setDiasAntecedenciaOverride(e.target.value)} placeholder="Padrão global"
                style={{ width: '100%', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', fontSize: 14, padding: '9px 12px', outline: 'none' }} />
            </div>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)', display: 'block', marginBottom: 6 }}>Dias p/ suspensão (override)</label>
              <input type="number" min={1} max={90} value={diasSuspensaoOverride} onChange={e => setDiasSuspensaoOverride(e.target.value)} placeholder="Padrão global"
                style={{ width: '100%', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', fontSize: 14, padding: '9px 12px', outline: 'none' }} />
            </div>
          </div>
          <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
            <button onClick={salvarCobranca} disabled={savingCobranca}
              style={{ padding: '8px 18px', background: savingCobranca ? 'var(--border)' : 'var(--accent)', color: savingCobranca ? 'var(--muted)' : '#000', border: 'none', borderRadius: 8, fontWeight: 700, fontSize: 13, fontFamily: "'Barlow Condensed', sans-serif", cursor: savingCobranca ? 'not-allowed' : 'pointer' }}>
              {savingCobranca ? 'Salvando…' : 'Salvar Cobrança'}
            </button>
            <button
              onClick={() => askConfirm(
                `Gerar agora a cobrança de ${oficina?.ciclo_cobranca === 'ANUAL' ? 'anuidade' : 'mensalidade'} do ciclo atual${oficina?.proximo_vencimento ? ` (vencimento ${formatarDataUTC(oficina.proximo_vencimento)})` : ''}? Se já existir uma cobrança de assinatura gerada para este ciclo, a geração automática mensal/anual não criará outra até o próximo ciclo. Cobranças avulsas não são afetadas.`,
                handleGerarCicloManual,
              )}
              disabled={gerandoCiclo}
              style={{ padding: '8px 18px', background: 'none', border: '1px solid var(--accent)', color: 'var(--accent)', borderRadius: 8, fontWeight: 700, fontSize: 13, fontFamily: "'Barlow Condensed', sans-serif", cursor: gerandoCiclo ? 'not-allowed' : 'pointer', opacity: gerandoCiclo ? 0.6 : 1 }}>
              {gerandoCiclo ? 'Gerando…' : 'Gerar Cobrança do Ciclo Agora'}
            </button>
          </div>
        </div>

        {/* ── Últimos Pagamentos Asaas ── */}
        {oficina?.gateway !== 'MERCADOPAGO' && !loadingAsaas && asaas && !asaas.error && asaas.ultimos_pagamentos.length > 0 && (
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: 20, marginTop: 20 }}>
            <h2 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: '0 0 14px' }}>
              Últimos Pagamentos no Asaas
            </h2>
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr>
                    {['ID Asaas', 'Vencimento', 'Valor', 'Status', 'Pago em', 'Links'].map(h => (
                      <th key={h} style={{ padding: '8px 12px', textAlign: 'left', fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', borderBottom: '1px solid var(--border)', background: 'var(--surface)', whiteSpace: 'nowrap' }}>
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {asaas.ultimos_pagamentos.map((p, i) => (
                    <tr key={p.id} style={{ borderBottom: i < asaas.ultimos_pagamentos.length - 1 ? '1px solid var(--border)' : 'none' }}>
                      <td style={{ padding: '10px 12px', fontFamily: "'JetBrains Mono', monospace", fontSize: 11, color: 'var(--muted)' }}>{p.id}</td>
                      <td style={{ padding: '10px 12px', fontFamily: "'JetBrains Mono', monospace", fontSize: 13, color: 'var(--text)' }}>{formatarDataUTC(p.dueDate)}</td>
                      <td style={{ padding: '10px 12px', fontFamily: "'JetBrains Mono', monospace", fontSize: 13, color: 'var(--text)' }}>{fmtBRL(p.value)}</td>
                      <td style={{ padding: '10px 12px' }}>
                        <span style={{ fontSize: 11, fontWeight: 700, color: asaasPaymentColor(p.status), textTransform: 'uppercase' }}>{p.status}</span>
                      </td>
                      <td style={{ padding: '10px 12px', fontSize: 13, color: p.paymentDate ? 'var(--success)' : 'var(--muted)' }}>
                        {p.paymentDate ? formatarDataUTC(p.paymentDate) : '—'}
                      </td>
                      <td style={{ padding: '10px 12px' }}>
                        <div style={{ display: 'flex', gap: 6 }}>
                          {p.bankSlipUrl && (
                            <a href={p.bankSlipUrl} target="_blank" rel="noopener noreferrer"
                              style={{ fontSize: 12, color: 'var(--info)', textDecoration: 'none', padding: '2px 8px', border: '1px solid var(--info)', borderRadius: 4 }}>
                              Boleto
                            </a>
                          )}
                          {p.invoiceUrl && (
                            <a href={p.invoiceUrl} target="_blank" rel="noopener noreferrer"
                              style={{ fontSize: 12, color: 'var(--accent)', textDecoration: 'none', padding: '2px 8px', border: '1px solid var(--accent)', borderRadius: 4 }}>
                              NF
                            </a>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* ── Cobranças Locais ── */}
        <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: 20, marginTop: 20 }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
            <h2 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: 0 }}>
              Cobranças Locais
            </h2>
            <div style={{ display: 'flex', gap: 8 }}>
              {oficina?.gateway !== 'MERCADOPAGO' && (
                <button onClick={handleSincronizar} disabled={!!actionLoading}
                  style={{ padding: '7px 14px', background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 8, fontSize: 13, cursor: 'pointer', fontWeight: 600 }}>
                  {actionLoading === 'sync' ? '…' : '⟳ Sincronizar Asaas'}
                </button>
              )}
              <button onClick={handleConciliar} disabled={conciliando}
                title="Verifica no gateway o status real das cobranças pendentes/vencidas — não depende do webhook ter chegado"
                style={{ padding: '7px 14px', background: 'none', border: '1px solid var(--info)', color: 'var(--info)', borderRadius: 8, fontSize: 13, cursor: conciliando ? 'not-allowed' : 'pointer', fontWeight: 600, opacity: conciliando ? 0.6 : 1 }}>
                {conciliando ? 'Conciliando…' : '⟳ Conciliar'}
              </button>
              <button onClick={() => { setGerarModal(true); setGerarError(null) }}
                style={{ padding: '7px 14px', background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 8, fontSize: 13, fontWeight: 700, cursor: 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
                + Cobrança Avulsa
              </button>
            </div>
          </div>

          {loadingCobrancas ? (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              {[...Array(4)].map((_, i) => <Sk key={i} h={36} />)}
            </div>
          ) : cobrancas.length === 0 ? (
            <p style={{ color: 'var(--muted)', fontSize: 14, textAlign: 'center', padding: '32px 0' }}>
              Nenhuma cobrança registrada.
            </p>
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr>
                    {['Mês', 'Vencimento', 'Valor', 'Status', 'Pago em', 'Asaas ID', 'Ação'].map(h => (
                      <th key={h} style={{ padding: '8px 12px', textAlign: 'left', fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', borderBottom: '1px solid var(--border)', background: 'var(--surface)', whiteSpace: 'nowrap' }}>
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {cobrancas.map((c, i) => {
                    const { bg, color } = cobrancaColor(c.status)
                    return (
                      <tr key={c.id} style={{ borderBottom: i < cobrancas.length - 1 ? '1px solid var(--border)' : 'none' }}>
                        <td style={{ padding: '10px 12px', fontFamily: "'JetBrains Mono', monospace", fontSize: 13, color: 'var(--text)' }}>{fmtMes(c.mes_referencia)}</td>
                        <td style={{ padding: '10px 12px', fontFamily: "'JetBrains Mono', monospace", fontSize: 13, color: 'var(--text)' }}>{formatarDataUTC(c.vencimento)}</td>
                        <td style={{ padding: '10px 12px', fontFamily: "'JetBrains Mono', monospace", fontSize: 13, color: 'var(--text)' }}>{fmtBRL(c.valor)}</td>
                        <td style={{ padding: '10px 12px' }}>
                          <span style={{ padding: '2px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700, background: bg, color, textTransform: 'uppercase' }}>
                            {c.status}
                          </span>
                        </td>
                        <td style={{ padding: '10px 12px', fontSize: 13, color: c.pago_em ? 'var(--success)' : 'var(--muted)' }}>
                          {c.pago_em ? formatarDataHora(c.pago_em) : '—'}
                        </td>
                        <td style={{ padding: '10px 12px', fontFamily: "'JetBrains Mono', monospace", fontSize: 11, color: 'var(--muted)' }}>
                          {c.asaas_payment_id ?? '—'}
                        </td>
                        <td style={{ padding: '10px 12px' }}>
                          {c.status !== 'PAGA' && (
                            <button onClick={() => handleCancelarCobranca(c.id)}
                              style={{ background: 'none', border: '1px solid var(--danger)', color: 'var(--danger)', borderRadius: 6, padding: '3px 10px', fontSize: 12, cursor: 'pointer' }}>
                              Cancelar
                            </button>
                          )}
                          {c.status === 'PAGA' && (c.mp_payment_id || c.asaas_payment_id) && (
                            <button onClick={() => handleEstornarCobranca(c)} disabled={estornandoId === c.id}
                              style={{ background: 'none', border: '1px solid var(--danger)', color: 'var(--danger)', borderRadius: 6, padding: '3px 10px', fontSize: 12, cursor: estornandoId === c.id ? 'not-allowed' : 'pointer', opacity: estornandoId === c.id ? 0.6 : 1 }}>
                              {estornandoId === c.id ? 'Estornando…' : 'Estornar'}
                            </button>
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}

          <ServicosAvulsosSection oficinaId={id} />
        </div>
      </div>
    </>
  )
}
