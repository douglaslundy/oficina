'use client'

import { useState, useEffect, useCallback } from 'react'
import saasApi from '@/lib/saas-api'
import { formatarDataUTC, formatarDataHora } from '@/lib/formatters'

// ─── Types ────────────────────────────────────────────────────────────────────

interface Cobranca {
  id: string
  oficina: { id: string; nome: string } | null
  mes_referencia: string // ISO date: "2025-11-01"
  valor: string // e.g. "199.90"
  status: 'PAGA' | 'PENDENTE' | 'VENCIDA' | 'CANCELADA' | 'ESTORNADA'
  vencimento: string // ISO date: "2025-11-30"
  pago_em: string | null // ISO8601 datetime or null
  gateway: 'ASAAS' | 'MERCADOPAGO' | null
  asaas_payment_id: string | null
  mp_payment_id: string | null
}

interface Oficina {
  id: string
  nome: string
}

interface PaginationMeta {
  total: number
  per_page: number
  current_page: number
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

const MESES_PT: Record<number, string> = {
  0: 'Jan',
  1: 'Fev',
  2: 'Mar',
  3: 'Abr',
  4: 'Mai',
  5: 'Jun',
  6: 'Jul',
  7: 'Ago',
  8: 'Set',
  9: 'Out',
  10: 'Nov',
  11: 'Dez',
}

function formatarMesReferencia(isoDate: string): string {
  // isoDate = "2025-11-01" → "Nov/2025"
  const [year, month] = isoDate.split('-').map(Number)
  const mesAbrev = MESES_PT[month - 1] ?? String(month)
  return `${mesAbrev}/${year}`
}

function formatarValor(value: string): string {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(
    parseFloat(value) || 0
  )
}

// ─── Status pill config ───────────────────────────────────────────────────────

const STATUS_CONFIG: Record<
  Cobranca['status'],
  { label: string; bg: string; color: string }
> = {
  PAGA: {
    label: 'Paga',
    bg: 'rgba(67,160,71,.15)',
    color: 'var(--success)',
  },
  PENDENTE: {
    label: 'Pendente',
    bg: 'rgba(245,166,35,.15)',
    color: 'var(--accent)',
  },
  VENCIDA: {
    label: 'Vencida',
    bg: 'rgba(229,57,53,.15)',
    color: 'var(--danger)',
  },
  CANCELADA: {
    label: 'Cancelada',
    bg: 'rgba(122,128,144,.15)',
    color: 'var(--muted)',
  },
  ESTORNADA: {
    label: 'Estornada',
    bg: 'rgba(30,136,229,.15)',
    color: 'var(--info)',
  },
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

// ─── Page ─────────────────────────────────────────────────────────────────────

const TABLE_COLS = ['Oficina', 'Mês Referência', 'Valor (R$)', 'Vencimento', 'Status', 'Data Pagamento', 'Ação']

export default function CobrancasPage() {
  const [cobrancas, setCobrancas] = useState<Cobranca[]>([])
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [toast, setToast] = useState<{ msg: string; type: 'ok' | 'err' } | null>(null)
  const [confirmDialog, setConfirmDialog] = useState<{ message: string; onConfirm: () => void } | null>(null)
  const [conciliando, setConciliando] = useState(false)
  const [estornandoId, setEstornandoId] = useState<string | null>(null)

  // Oficinas for filter select
  const [oficinas, setOficinas] = useState<Oficina[]>([])
  const [oficinasLoading, setOficinasLoading] = useState(true)

  // Filters (applied on "Filtrar" click)
  const [filterMes, setFilterMes] = useState('')
  const [filterOficinaId, setFilterOficinaId] = useState('')

  // Active filters (what was last submitted)
  const [activeMes, setActiveMes] = useState('')
  const [activeOficinaId, setActiveOficinaId] = useState('')

  // Pagination
  const [page, setPage] = useState(1)

  function showToast(msg: string, type: 'ok' | 'err' = 'ok') {
    setToast({ msg, type })
    setTimeout(() => setToast(null), 4000)
  }

  // ─── Fetch oficinas (once) ─────────────────────────────────────────────────

  useEffect(() => {
    async function fetchOficinas() {
      try {
        const res = await saasApi.get<{ data: Oficina[] }>('/saas/oficinas', {
          params: { per_page: 100 },
        })
        setOficinas(res.data.data ?? [])
      } catch {
        // Non-critical: filter select just won't have options
      } finally {
        setOficinasLoading(false)
      }
    }
    fetchOficinas()
  }, [])

  // ─── Fetch cobranças ───────────────────────────────────────────────────────

  const fetchCobrancas = useCallback(
    async (currentPage: number, mes: string, oficinaId: string) => {
      setLoading(true)
      setError(null)
      try {
        const params: Record<string, string | number> = { page: currentPage }
        if (mes) params.mes = mes
        if (oficinaId) params.oficina_id = oficinaId

        const res = await saasApi.get<{ data: Cobranca[]; meta: PaginationMeta }>(
          '/saas/cobrancas',
          { params }
        )
        setCobrancas(res.data.data ?? [])
        setMeta(res.data.meta ?? null)
      } catch {
        setError('Erro ao carregar cobranças.')
      } finally {
        setLoading(false)
      }
    },
    []
  )

  useEffect(() => {
    fetchCobrancas(page, activeMes, activeOficinaId)
  }, [fetchCobrancas, page, activeMes, activeOficinaId])

  async function handleConciliar() {
    setConciliando(true)
    try {
      const params: Record<string, string> = {}
      if (activeOficinaId) params.oficina_id = activeOficinaId
      const res = await saasApi.post<{ message: string }>('/saas/cobrancas/conciliar', null, { params })
      showToast(res.data.message)
      fetchCobrancas(page, activeMes, activeOficinaId)
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao conciliar pagamentos.'
      showToast(msg, 'err')
    } finally {
      setConciliando(false)
    }
  }

  function handleEstornar(cobranca: Cobranca) {
    setConfirmDialog({
      message: `Estornar o pagamento de ${cobranca.oficina?.nome ?? 'oficina'} (${formatarValor(cobranca.valor)})? Essa ação devolve o dinheiro no gateway e não pode ser desfeita.`,
      onConfirm: async () => {
        setEstornandoId(cobranca.id)
        try {
          const res = await saasApi.post<{ message: string }>(`/saas/cobrancas/${cobranca.id}/estornar`)
          showToast(res.data.message)
          fetchCobrancas(page, activeMes, activeOficinaId)
        } catch (e: unknown) {
          const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao estornar pagamento.'
          showToast(msg, 'err')
        } finally {
          setEstornandoId(null)
        }
      },
    })
  }

  // ─── Filter actions ────────────────────────────────────────────────────────

  function handleFiltrar() {
    setPage(1)
    setActiveMes(filterMes)
    setActiveOficinaId(filterOficinaId)
  }

  function handleLimpar() {
    setFilterMes('')
    setFilterOficinaId('')
    setPage(1)
    setActiveMes('')
    setActiveOficinaId('')
  }

  // ─── Pagination ────────────────────────────────────────────────────────────

  const totalPages = meta ? Math.ceil(meta.total / meta.per_page) : 1
  const showPagination = meta && meta.total > 15

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
        @keyframes slideIn {
          from { transform: translateX(100%); opacity: 0; }
          to   { transform: none; opacity: 1; }
        }
      `}</style>

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

      {confirmDialog && (
        <div style={{
          position: 'fixed', inset: 0, background: 'rgba(0,0,0,.6)',
          display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000,
        }}>
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, padding: 28, width: 400 }}>
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
                style={{ padding: '9px 20px', background: 'rgba(229,57,53,.15)', color: 'var(--danger)', border: '1px solid var(--danger)', borderRadius: 8, fontWeight: 700, fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif", cursor: 'pointer' }}
              >
                Confirmar
              </button>
            </div>
          </div>
        </div>
      )}

      <div style={{ padding: '32px 32px 40px', color: 'var(--text)', maxWidth: 1200, margin: '0 auto' }}>
        {/* Page header */}
        <div style={{ marginBottom: 28 }}>
          <h1
            className="font-display"
            style={{ fontSize: 26, fontWeight: 800, color: 'var(--text)', margin: 0 }}
          >
            Cobranças
          </h1>
          <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
            Histórico de cobranças das oficinas cadastradas
          </p>
        </div>

        {/* Filters bar */}
        <div
          style={{
            display: 'flex',
            alignItems: 'flex-end',
            gap: 12,
            marginBottom: 24,
            flexWrap: 'wrap',
            background: 'var(--card)',
            border: '1px solid var(--border)',
            borderRadius: 10,
            padding: '16px 20px',
          }}
        >
          {/* Mês filter */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 5 }}>
            <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)' }}>Mês</label>
            <input
              type="month"
              value={filterMes}
              onChange={(e) => setFilterMes(e.target.value)}
              style={{
                background: 'var(--bg)',
                border: '1px solid var(--border)',
                borderRadius: 8,
                color: 'var(--text)',
                fontSize: 14,
                padding: '8px 12px',
                outline: 'none',
                minWidth: 160,
                colorScheme: 'dark',
              }}
            />
          </div>

          {/* Oficina filter */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 5 }}>
            <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)' }}>Oficina</label>
            <select
              value={filterOficinaId}
              onChange={(e) => setFilterOficinaId(e.target.value)}
              disabled={oficinasLoading}
              style={{
                background: 'var(--bg)',
                border: '1px solid var(--border)',
                borderRadius: 8,
                color: filterOficinaId ? 'var(--text)' : 'var(--muted)',
                fontSize: 14,
                padding: '8px 32px 8px 12px',
                outline: 'none',
                minWidth: 200,
                cursor: 'pointer',
                appearance: 'none',
                backgroundImage:
                  "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%237a8090' d='M6 8L1 3h10z'/%3E%3C/svg%3E\")",
                backgroundRepeat: 'no-repeat',
                backgroundPosition: 'calc(100% - 10px) center',
              }}
            >
              <option value="">Todas as oficinas</option>
              {oficinas.map((o) => (
                <option key={o.id} value={o.id}>
                  {o.nome}
                </option>
              ))}
            </select>
          </div>

          {/* Action buttons */}
          <div style={{ display: 'flex', gap: 8, paddingBottom: 0, alignSelf: 'flex-end' }}>
            <button
              onClick={handleFiltrar}
              style={{
                background: 'var(--accent)',
                color: '#000',
                border: 'none',
                borderRadius: 8,
                padding: '9px 22px',
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
              Filtrar
            </button>
            <button
              onClick={handleLimpar}
              style={{
                background: 'none',
                border: '1px solid var(--border)',
                color: 'var(--muted)',
                borderRadius: 8,
                padding: '9px 18px',
                fontSize: 14,
                cursor: 'pointer',
                transition: 'color 0.15s, border-color 0.15s',
              }}
              onMouseEnter={(e) => {
                ;(e.currentTarget as HTMLButtonElement).style.color = 'var(--text)'
                ;(e.currentTarget as HTMLButtonElement).style.borderColor = 'var(--text)'
              }}
              onMouseLeave={(e) => {
                ;(e.currentTarget as HTMLButtonElement).style.color = 'var(--muted)'
                ;(e.currentTarget as HTMLButtonElement).style.borderColor = 'var(--border)'
              }}
            >
              Limpar
            </button>
            <button
              onClick={handleConciliar}
              disabled={conciliando}
              title="Verifica no gateway o status real das cobranças pendentes/vencidas — não depende do webhook ter chegado"
              style={{
                background: 'none',
                border: '1px solid var(--info)',
                color: 'var(--info)',
                borderRadius: 8,
                padding: '9px 18px',
                fontSize: 14,
                fontWeight: 600,
                cursor: conciliando ? 'not-allowed' : 'pointer',
                opacity: conciliando ? 0.6 : 1,
              }}
            >
              {conciliando ? 'Conciliando…' : '⟳ Conciliar Pagamentos'}
            </button>
          </div>
        </div>

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
              animation: 'slideDown 0.2s ease',
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
                  Array.from({ length: 6 }).map((_, i) => (
                    <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                      <td style={{ padding: '13px 16px' }}>
                        <Skeleton width="70%" height={14} />
                      </td>
                      <td style={{ padding: '13px 16px' }}>
                        <Skeleton width="60%" height={14} />
                      </td>
                      <td style={{ padding: '13px 16px' }}>
                        <Skeleton width="55%" height={14} />
                      </td>
                      <td style={{ padding: '13px 16px' }}>
                        <Skeleton width="50%" height={14} />
                      </td>
                      <td style={{ padding: '13px 16px' }}>
                        <Skeleton width={64} height={22} />
                      </td>
                      <td style={{ padding: '13px 16px' }}>
                        <Skeleton width="50%" height={14} />
                      </td>
                      <td style={{ padding: '13px 16px' }}>
                        <Skeleton width={70} height={22} />
                      </td>
                    </tr>
                  ))
                ) : cobrancas.length === 0 ? (
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
                      Nenhuma cobrança encontrada.
                    </td>
                  </tr>
                ) : (
                  cobrancas.map((cobranca, idx) => {
                    const isLast = idx === cobrancas.length - 1
                    const statusCfg = STATUS_CONFIG[cobranca.status]

                    return (
                      <tr
                        key={cobranca.id}
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
                        {/* Oficina */}
                        <td style={{ padding: '12px 16px', minWidth: 160 }}>
                          <div style={{ fontSize: 14, fontWeight: 600, color: 'var(--text)' }}>
                            {cobranca.oficina?.nome ?? (
                              <span style={{ color: 'var(--muted)', fontStyle: 'italic' }}>
                                —
                              </span>
                            )}
                          </div>
                        </td>

                        {/* Mês Referência */}
                        <td style={{ padding: '12px 16px', whiteSpace: 'nowrap' }}>
                          <span
                            style={{
                              fontFamily: "'JetBrains Mono', monospace",
                              fontSize: 14,
                              color: 'var(--text)',
                            }}
                          >
                            {formatarMesReferencia(cobranca.mes_referencia)}
                          </span>
                        </td>

                        {/* Valor */}
                        <td style={{ padding: '12px 16px', whiteSpace: 'nowrap' }}>
                          <span
                            style={{
                              fontFamily: "'JetBrains Mono', monospace",
                              fontSize: 14,
                              color: 'var(--text)',
                            }}
                          >
                            {formatarValor(cobranca.valor)}
                          </span>
                        </td>

                        {/* Vencimento */}
                        <td style={{ padding: '12px 16px', whiteSpace: 'nowrap' }}>
                          <span
                            style={{
                              fontFamily: "'JetBrains Mono', monospace",
                              fontSize: 14,
                              color: 'var(--text)',
                            }}
                          >
                            {formatarDataUTC(cobranca.vencimento)}
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
                              background: statusCfg.bg,
                              color: statusCfg.color,
                            }}
                          >
                            {statusCfg.label}
                          </span>
                        </td>

                        {/* Data Pagamento */}
                        <td style={{ padding: '12px 16px', whiteSpace: 'nowrap' }}>
                          {cobranca.pago_em ? (
                            <span
                              style={{
                                fontFamily: "'JetBrains Mono', monospace",
                                fontSize: 14,
                                color: 'var(--success)',
                              }}
                            >
                              {formatarDataHora(cobranca.pago_em)}
                            </span>
                          ) : (
                            <span style={{ color: 'var(--muted)', fontSize: 14 }}>—</span>
                          )}
                        </td>

                        {/* Ação */}
                        <td style={{ padding: '12px 16px', whiteSpace: 'nowrap' }}>
                          {cobranca.status === 'PAGA' && (cobranca.mp_payment_id || cobranca.asaas_payment_id) ? (
                            <button
                              onClick={() => handleEstornar(cobranca)}
                              disabled={estornandoId === cobranca.id}
                              style={{
                                background: 'none', border: '1px solid var(--danger)', color: 'var(--danger)',
                                borderRadius: 6, padding: '4px 10px', fontSize: 12, fontWeight: 600,
                                cursor: estornandoId === cobranca.id ? 'not-allowed' : 'pointer',
                                opacity: estornandoId === cobranca.id ? 0.6 : 1,
                              }}
                            >
                              {estornandoId === cobranca.id ? 'Estornando…' : 'Estornar'}
                            </button>
                          ) : (
                            <span style={{ color: 'var(--muted)', fontSize: 13 }}>—</span>
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
          {showPagination && (
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                padding: '12px 20px',
                borderTop: '1px solid var(--border)',
                flexWrap: 'wrap',
                gap: 8,
              }}
            >
              <span style={{ fontSize: 13, color: 'var(--muted)' }}>
                Página {page} de {totalPages}
              </span>
              <div style={{ display: 'flex', gap: 8 }}>
                <button
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page <= 1 || loading}
                  style={{
                    background: 'none',
                    border: '1px solid var(--border)',
                    color: page <= 1 ? 'var(--muted)' : 'var(--text)',
                    borderRadius: 8,
                    padding: '7px 16px',
                    fontSize: 13,
                    cursor: page <= 1 ? 'not-allowed' : 'pointer',
                    opacity: page <= 1 ? 0.5 : 1,
                    transition: 'color 0.15s, border-color 0.15s',
                  }}
                >
                  Anterior
                </button>
                <button
                  onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                  disabled={page >= totalPages || loading}
                  style={{
                    background: 'none',
                    border: '1px solid var(--border)',
                    color: page >= totalPages ? 'var(--muted)' : 'var(--text)',
                    borderRadius: 8,
                    padding: '7px 16px',
                    fontSize: 13,
                    cursor: page >= totalPages ? 'not-allowed' : 'pointer',
                    opacity: page >= totalPages ? 0.5 : 1,
                    transition: 'color 0.15s, border-color 0.15s',
                  }}
                >
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
