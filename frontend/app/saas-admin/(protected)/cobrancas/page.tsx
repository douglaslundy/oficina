'use client'

import { useState, useEffect, useCallback } from 'react'
import saasApi from '@/lib/saas-api'

// ─── Types ────────────────────────────────────────────────────────────────────

interface Cobranca {
  id: string
  oficina: { id: string; nome: string } | null
  mes_referencia: string // ISO date: "2025-11-01"
  valor: string // e.g. "199.90"
  status: 'PAGO' | 'PENDENTE' | 'VENCIDO'
  vencimento: string // ISO date: "2025-11-30"
  pago_em: string | null // ISO8601 datetime or null
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

function formatarData(isoDate: string): string {
  // isoDate = "2025-11-30" or "2025-11-15T10:00:00Z" → "30/11/2025"
  const date = new Date(isoDate)
  const d = String(date.getUTCDate()).padStart(2, '0')
  const m = String(date.getUTCMonth() + 1).padStart(2, '0')
  const y = date.getUTCFullYear()
  return `${d}/${m}/${y}`
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
  PAGO: {
    label: 'Pago',
    bg: 'rgba(67,160,71,.15)',
    color: 'var(--success)',
  },
  PENDENTE: {
    label: 'Pendente',
    bg: 'rgba(245,166,35,.15)',
    color: 'var(--accent)',
  },
  VENCIDO: {
    label: 'Vencido',
    bg: 'rgba(229,57,53,.15)',
    color: 'var(--danger)',
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

const TABLE_COLS = ['Oficina', 'Mês Referência', 'Valor (R$)', 'Vencimento', 'Status', 'Data Pagamento']

export default function CobrancasPage() {
  const [cobrancas, setCobrancas] = useState<Cobranca[]>([])
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

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
      `}</style>

      <div style={{ padding: '32px 32px 40px', color: 'var(--text)', maxWidth: 1200 }}>
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
                    </tr>
                  ))
                ) : cobrancas.length === 0 ? (
                  <tr>
                    <td
                      colSpan={6}
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
                            {formatarData(cobranca.vencimento)}
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
                              {formatarData(cobranca.pago_em)}
                            </span>
                          ) : (
                            <span style={{ color: 'var(--muted)', fontSize: 14 }}>—</span>
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
