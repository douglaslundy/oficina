'use client'
import Link from 'next/link'

interface AlertItem {
  nome: string
  qty_atual: number
  status: string
}

interface AlertBannerProps {
  items: AlertItem[]
  dismissed: boolean
  onDismiss: () => void
}

export function AlertBanner({ items, dismissed, onDismiss }: AlertBannerProps) {
  if (dismissed || items.length === 0) return null

  const preview = items.slice(0, 3).map(i => i.nome).join(', ')
  const extra = items.length > 3 ? ` e mais ${items.length - 3}` : ''

  return (
    <div style={{
      background: 'rgba(229,57,53,0.1)', border: '1px solid rgba(229,57,53,0.3)',
      borderRadius: 8, padding: '10px 16px', marginBottom: 20,
      display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12,
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <span style={{ color: 'var(--danger)', fontSize: 16 }}>⚠</span>
        <span style={{ color: 'var(--danger)', fontSize: 13, fontWeight: 600 }}>
          Estoque crítico: {preview}{extra}
        </span>
        <Link href="/produtos" style={{ color: 'var(--accent)', fontSize: 13, textDecoration: 'none' }}>
          Ver todos →
        </Link>
      </div>
      <button onClick={onDismiss}
        style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 18, lineHeight: 1, flexShrink: 0 }}>
        ×
      </button>
    </div>
  )
}
