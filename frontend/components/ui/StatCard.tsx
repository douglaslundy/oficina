interface StatCardProps {
  title: string
  value: string | number
  icon: string
  color: string
  subtitle?: string
}

export function StatCard({ title, value, icon, color, subtitle }: StatCardProps) {
  return (
    <div style={{
      background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)',
      padding: 24, position: 'relative', overflow: 'hidden',
    }}>
      <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 2, background: color }} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <div>
          <p style={{ color: 'var(--muted)', fontSize: 12, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', margin: '0 0 8px' }}>{title}</p>
          <p className="font-mono" style={{ color: 'var(--text)', fontSize: 26, fontWeight: 700, margin: 0, lineHeight: 1 }}>{value}</p>
          {subtitle && <p style={{ color: 'var(--muted)', fontSize: 12, margin: '6px 0 0' }}>{subtitle}</p>}
        </div>
        <span style={{ fontSize: 28, opacity: 0.12 }}>{icon}</span>
      </div>
    </div>
  )
}
