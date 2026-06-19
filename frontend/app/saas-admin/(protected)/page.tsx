'use client'

import { useState, useEffect } from 'react'
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  Tooltip,
  ResponsiveContainer,
} from 'recharts'
import saasApi from '@/lib/saas-api'
import { formatarMoeda, formatarData } from '@/lib/formatters'

// ─── Types ────────────────────────────────────────────────────────────────────

interface DashboardData {
  total_oficinas: number
  ativas: number
  inadimplentes: number
  suspensas: number
  mrr: number
  crescimento_mensal: { mes: string; total: number }[]
}

interface Plano {
  nome: string
  preco_mensal: number
}

interface Oficina {
  id: string
  nome: string
  cnpj: string
  slug: string
  status: string
  plano: Plano
  users_count: number
  os_mes_count: number
  admin_email: string
  criado_em: string
}

interface OficinasResponse {
  data: Oficina[]
  meta: { total: number; per_page: number; current_page: number }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

const MES_ABBR: Record<string, string> = {
  '01': 'Jan',
  '02': 'Fev',
  '03': 'Mar',
  '04': 'Abr',
  '05': 'Mai',
  '06': 'Jun',
  '07': 'Jul',
  '08': 'Ago',
  '09': 'Set',
  '10': 'Out',
  '11': 'Nov',
  '12': 'Dez',
}

function mesAbreviado(mes: string): string {
  // mes format: "2025-11"
  const parts = mes.split('-')
  return MES_ABBR[parts[1]] ?? mes
}

function statusColor(status: string): { bg: string; color: string } {
  switch (status.toUpperCase()) {
    case 'ATIVA':
      return { bg: 'rgba(67,160,71,.15)', color: 'var(--success)' }
    case 'SUSPENSA':
      return { bg: 'rgba(245,166,35,.15)', color: 'var(--accent)' }
    case 'CANCELADA':
    case 'INADIMPLENTE':
      return { bg: 'rgba(229,57,53,.15)', color: 'var(--danger)' }
    default:
      return { bg: 'rgba(122,128,144,.15)', color: 'var(--muted)' }
  }
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

// ─── Stat Card ────────────────────────────────────────────────────────────────

interface StatCardProps {
  icon: string
  label: string
  value: string | number
  accentColor: string
  loading: boolean
}

function StatCard({ icon, label, value, accentColor, loading }: StatCardProps) {
  return (
    <div
      style={{
        background: 'var(--card)',
        border: '1px solid var(--border)',
        borderRadius: 10,
        padding: '20px 24px',
        flex: 1,
        minWidth: 0,
        position: 'relative',
        overflow: 'hidden',
      }}
    >
      {/* Top accent bar */}
      <div
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          right: 0,
          height: 2,
          background: accentColor,
        }}
      />

      {/* Icon ghost */}
      <div
        style={{
          position: 'absolute',
          top: 12,
          right: 16,
          fontSize: 32,
          opacity: 0.08,
          userSelect: 'none',
          lineHeight: 1,
        }}
      >
        {icon}
      </div>

      <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 10, fontWeight: 500 }}>
        {label}
      </div>

      {loading ? (
        <Skeleton width="60%" height={28} />
      ) : (
        <div
          style={{
            fontFamily: "'JetBrains Mono', monospace",
            fontSize: 28,
            fontWeight: 700,
            color: accentColor,
            lineHeight: 1,
          }}
        >
          {value}
        </div>
      )}
    </div>
  )
}

// ─── Custom Tooltip ───────────────────────────────────────────────────────────

function CustomTooltip({ active, payload, label }: {
  active?: boolean
  payload?: { value: number }[]
  label?: string
}) {
  if (!active || !payload?.length) return null
  return (
    <div
      style={{
        background: 'var(--surface)',
        border: '1px solid var(--border)',
        borderRadius: 6,
        padding: '8px 14px',
        fontSize: 13,
        color: 'var(--text)',
      }}
    >
      <div style={{ marginBottom: 4, color: 'var(--muted)' }}>{label}</div>
      <div style={{ fontWeight: 700, color: 'var(--accent)' }}>
        {payload[0].value} oficina{payload[0].value !== 1 ? 's' : ''}
      </div>
    </div>
  )
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function SaasAdminDashboardPage() {
  const [dashboard, setDashboard] = useState<DashboardData | null>(null)
  const [oficinas, setOficinas] = useState<Oficina[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    async function fetchAll() {
      try {
        const [dashRes, oficinasRes] = await Promise.all([
          saasApi.get<DashboardData>('/saas/dashboard'),
          saasApi.get<OficinasResponse>('/saas/oficinas', { params: { per_page: 5 } }),
        ])
        setDashboard(dashRes.data)
        setOficinas(oficinasRes.data.data)
      } catch (err) {
        setError('Erro ao carregar dados do dashboard.')
        console.error(err)
      } finally {
        setLoading(false)
      }
    }

    fetchAll()
  }, [])

  const chartData =
    dashboard?.crescimento_mensal.map((item) => ({
      mes: mesAbreviado(item.mes),
      total: item.total,
    })) ?? []

  const inadimplenteTotal = dashboard
    ? dashboard.inadimplentes + dashboard.suspensas
    : 0

  return (
    <>
      {/* Pulse keyframe */}
      <style>{`
        @keyframes pulse {
          0%, 100% { opacity: 1; }
          50% { opacity: 0.45; }
        }
      `}</style>

      <div style={{ padding: '32px 32px 40px', color: 'var(--text)', maxWidth: 1200, margin: '0 auto' }}>
        {/* Page title */}
        <div style={{ marginBottom: 28 }}>
          <h1
            className="font-display"
            style={{ fontSize: 26, fontWeight: 800, color: 'var(--text)', margin: 0 }}
          >
            Dashboard
          </h1>
          <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
            Visão geral da plataforma MecânicaPro SaaS
          </p>
        </div>

        {/* Error state */}
        {error && (
          <div
            style={{
              background: 'rgba(229,57,53,.1)',
              border: '1px solid var(--danger)',
              borderRadius: 8,
              padding: '12px 16px',
              color: 'var(--danger)',
              fontSize: 14,
              marginBottom: 24,
            }}
          >
            {error}
          </div>
        )}

        {/* Stat cards row */}
        <div
          style={{
            display: 'flex',
            gap: 16,
            marginBottom: 28,
            flexWrap: 'wrap',
          }}
        >
          <StatCard
            icon="🏪"
            label="Total Oficinas"
            value={dashboard?.total_oficinas ?? 0}
            accentColor="var(--info)"
            loading={loading}
          />
          <StatCard
            icon="✅"
            label="Ativas"
            value={dashboard?.ativas ?? 0}
            accentColor="var(--success)"
            loading={loading}
          />
          <StatCard
            icon="💰"
            label="MRR"
            value={dashboard ? formatarMoeda(dashboard.mrr) : 'R$ 0,00'}
            accentColor="var(--success)"
            loading={loading}
          />
          <StatCard
            icon="⚠"
            label="Inadimplentes + Suspensas"
            value={inadimplenteTotal}
            accentColor="var(--danger)"
            loading={loading}
          />
        </div>

        {/* Chart + table row */}
        <div style={{ display: 'flex', gap: 20, flexWrap: 'wrap' }}>
          {/* Bar chart */}
          <div
            style={{
              background: 'var(--card)',
              border: '1px solid var(--border)',
              borderRadius: 10,
              padding: '20px 24px',
              flex: '1 1 340px',
              minWidth: 0,
            }}
          >
            <div
              style={{
                fontSize: 14,
                fontWeight: 600,
                color: 'var(--text)',
                marginBottom: 20,
              }}
            >
              Crescimento de Oficinas (últimos 7 meses)
            </div>

            {loading ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                <Skeleton height={140} />
              </div>
            ) : (
              <ResponsiveContainer width="100%" height={180}>
                <BarChart data={chartData} barSize={28}>
                  <XAxis
                    dataKey="mes"
                    tick={{ fill: 'var(--muted)', fontSize: 12 }}
                    axisLine={{ stroke: 'var(--border)' }}
                    tickLine={false}
                  />
                  <YAxis
                    tick={{ fill: 'var(--muted)', fontSize: 12 }}
                    axisLine={false}
                    tickLine={false}
                    allowDecimals={false}
                    width={30}
                  />
                  <Tooltip content={<CustomTooltip />} cursor={{ fill: 'rgba(245,166,35,.06)' }} />
                  <Bar dataKey="total" fill="#f5a623" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            )}
          </div>

          {/* Quick stats panel */}
          <div
            style={{
              background: 'var(--card)',
              border: '1px solid var(--border)',
              borderRadius: 10,
              padding: '20px 24px',
              flex: '0 0 200px',
              display: 'flex',
              flexDirection: 'column',
              gap: 16,
            }}
          >
            <div style={{ fontSize: 14, fontWeight: 600, color: 'var(--text)' }}>
              Resumo
            </div>

            {[
              { label: 'Ativas', value: dashboard?.ativas, color: 'var(--success)' },
              { label: 'Inadimplentes', value: dashboard?.inadimplentes, color: 'var(--danger)' },
              { label: 'Suspensas', value: dashboard?.suspensas, color: 'var(--accent)' },
            ].map(({ label, value, color }) => (
              <div key={label}>
                <div
                  style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    marginBottom: 6,
                  }}
                >
                  <span style={{ fontSize: 12, color: 'var(--muted)' }}>{label}</span>
                  {loading ? (
                    <Skeleton width={24} height={14} />
                  ) : (
                    <span
                      style={{
                        fontFamily: "'JetBrains Mono', monospace",
                        fontSize: 14,
                        fontWeight: 700,
                        color,
                      }}
                    >
                      {value ?? 0}
                    </span>
                  )}
                </div>
                {!loading && dashboard && (
                  <div
                    style={{
                      height: 4,
                      borderRadius: 99,
                      background: 'var(--border)',
                      overflow: 'hidden',
                    }}
                  >
                    <div
                      style={{
                        height: '100%',
                        width: `${dashboard.total_oficinas > 0
                          ? ((value ?? 0) / dashboard.total_oficinas) * 100
                          : 0}%`,
                        background: color,
                        borderRadius: 99,
                        transition: 'width 0.5s ease',
                      }}
                    />
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>

        {/* Últimas Oficinas table */}
        <div
          style={{
            background: 'var(--card)',
            border: '1px solid var(--border)',
            borderRadius: 10,
            marginTop: 24,
            overflow: 'hidden',
          }}
        >
          <div
            style={{
              padding: '16px 24px',
              borderBottom: '1px solid var(--border)',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
            }}
          >
            <span style={{ fontSize: 14, fontWeight: 600, color: 'var(--text)' }}>
              Últimas Oficinas
            </span>
            <a
              href="/saas-admin/oficinas"
              style={{ fontSize: 13, color: 'var(--accent)', textDecoration: 'none' }}
            >
              Ver todas →
            </a>
          </div>

          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead>
                <tr>
                  {['Nome', 'Plano', 'Status', 'OS/mês', 'Admin E-mail', 'Data Cadastro'].map(
                    (col) => (
                      <th
                        key={col}
                        style={{
                          padding: '10px 16px',
                          textAlign: 'left',
                          fontSize: 11,
                          fontWeight: 600,
                          color: 'var(--muted)',
                          textTransform: 'uppercase',
                          letterSpacing: '0.06em',
                          borderBottom: '1px solid var(--border)',
                          whiteSpace: 'nowrap',
                        }}
                      >
                        {col}
                      </th>
                    )
                  )}
                </tr>
              </thead>

              <tbody>
                {loading ? (
                  Array.from({ length: 5 }).map((_, i) => (
                    <tr key={i}>
                      {Array.from({ length: 6 }).map((__, j) => (
                        <td key={j} style={{ padding: '12px 16px' }}>
                          <Skeleton height={14} width={j === 0 ? '80%' : j === 4 ? '90%' : '60%'} />
                        </td>
                      ))}
                    </tr>
                  ))
                ) : oficinas.length === 0 ? (
                  <tr>
                    <td
                      colSpan={6}
                      style={{
                        padding: '32px 16px',
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
                        {/* Nome */}
                        <td style={{ padding: '12px 16px' }}>
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

                        {/* Plano */}
                        <td style={{ padding: '12px 16px' }}>
                          <div style={{ fontSize: 13, color: 'var(--text)' }}>
                            {oficina.plano?.nome ?? '-'}
                          </div>
                          {oficina.plano?.preco_mensal != null && (
                            <div
                              style={{
                                fontSize: 11,
                                color: 'var(--muted)',
                                fontFamily: "'JetBrains Mono', monospace",
                                marginTop: 2,
                              }}
                            >
                              {formatarMoeda(oficina.plano.preco_mensal)}
                            </div>
                          )}
                        </td>

                        {/* Status */}
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
                            }}
                          >
                            {oficina.status}
                          </span>
                        </td>

                        {/* OS/mês */}
                        <td style={{ padding: '12px 16px' }}>
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

                        {/* Admin e-mail */}
                        <td style={{ padding: '12px 16px' }}>
                          <span style={{ fontSize: 13, color: 'var(--muted)' }}>
                            {oficina.admin_email}
                          </span>
                        </td>

                        {/* Data cadastro */}
                        <td style={{ padding: '12px 16px' }}>
                          <span
                            style={{
                              fontFamily: "'JetBrains Mono', monospace",
                              fontSize: 13,
                              color: 'var(--muted)',
                            }}
                          >
                            {formatarData(oficina.criado_em)}
                          </span>
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
    </>
  )
}
