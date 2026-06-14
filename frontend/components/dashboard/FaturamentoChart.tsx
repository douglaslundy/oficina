'use client'
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, Cell } from 'recharts'

interface FaturamentoChartProps {
  data: Array<{ mes: string; total: number }>
}

const CustomTooltip = ({ active, payload, label }: { active?: boolean; payload?: Array<{ value: number }>; label?: string }) => {
  if (!active || !payload?.length) return null
  return (
    <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 8, padding: '10px 16px' }}>
      <p style={{ color: 'var(--muted)', fontSize: 12, margin: '0 0 4px' }}>{label}</p>
      <p style={{ color: 'var(--accent)', fontWeight: 700, margin: 0 }}>
        {new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(payload[0].value)}
      </p>
    </div>
  )
}

export function FaturamentoChart({ data }: FaturamentoChartProps) {
  const maxIdx = data.length === 0 ? 0 : data.reduce(
    (maxI, d, i, arr) => d.total > arr[maxI].total ? i : maxI, 0
  )

  if (data.length === 0) {
    return (
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
        <h3 className="font-display" style={{ fontSize: 18, fontWeight: 800, color: 'var(--text)', marginBottom: 20 }}>Faturamento Mensal</h3>
        <p style={{ color: 'var(--muted)', textAlign: 'center', padding: '40px 0' }}>Nenhuma NF autorizada nos últimos 7 meses.</p>
      </div>
    )
  }

  return (
    <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
      <h3 className="font-display" style={{ fontSize: 18, fontWeight: 800, color: 'var(--text)', marginBottom: 20 }}>Faturamento Mensal</h3>
      <ResponsiveContainer width="100%" height={200}>
        <BarChart data={data} barCategoryGap="30%">
          <XAxis dataKey="mes" tick={{ fill: 'var(--muted)', fontSize: 12 }} axisLine={false} tickLine={false} />
          <YAxis hide />
          <Tooltip content={<CustomTooltip />} cursor={{ fill: 'rgba(255,255,255,0.03)' }} />
          <Bar dataKey="total" radius={[4, 4, 0, 0]}>
            {data.map((_, idx) => (
              <Cell key={idx} fill={idx === maxIdx ? 'var(--accent)' : 'rgba(245,166,35,0.3)'} />
            ))}
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}
