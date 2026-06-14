interface StockBarProps {
  qtyAtual: number
  qtyMinima: number
  status: string
}

export function StockBar({ qtyAtual, qtyMinima, status }: StockBarProps) {
  const pct = qtyMinima === 0
    ? 100
    : Math.min(100, (qtyAtual / (qtyMinima * 1.5)) * 100)

  const isCritical = status === 'CRITICO' || status === 'SEM_ESTOQUE'
  const fillClass = isCritical
    ? 'stock-fill critico'
    : status === 'BAIXO' ? 'stock-fill baixo' : 'stock-fill normal'

  const numColor = isCritical
    ? 'var(--danger)'
    : status === 'BAIXO' ? 'var(--accent)' : 'var(--success)'

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 120 }}>
      <div className="stock-bar" style={{ flex: 1 }}>
        <div className={fillClass} style={{ width: `${Math.max(2, pct)}%` }} />
      </div>
      <span className="font-mono" style={{ fontSize: 13, color: numColor, minWidth: 24, textAlign: 'right' }}>
        {qtyAtual}
      </span>
    </div>
  )
}
