'use client'
import Link from 'next/link'

interface NotaTerceiroBannerProps {
  pendentes: number
  dismissed: boolean
  onDismiss: () => void
}

export function NotaTerceiroBanner({ pendentes, dismissed, onDismiss }: NotaTerceiroBannerProps) {
  if (dismissed || pendentes <= 0) return null

  return (
    <div style={{
      background: 'rgba(30,136,229,0.1)', border: '1px solid rgba(30,136,229,0.3)',
      borderRadius: 8, padding: '10px 16px', marginBottom: 20,
      display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12,
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <span style={{ color: 'var(--info)', fontSize: 16 }}>📥</span>
        <span style={{ color: 'var(--info)', fontSize: 13, fontWeight: 600 }}>
          {pendentes === 1
            ? '1 nova nota fiscal recebida pro CNPJ da sua oficina'
            : `${pendentes} novas notas fiscais recebidas pro CNPJ da sua oficina`}
        </span>
        <Link href="/produtos/entrada-nf" style={{ color: 'var(--accent)', fontSize: 13, textDecoration: 'none' }}>
          Ver notas →
        </Link>
      </div>
      <button onClick={onDismiss}
        style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 18, lineHeight: 1, flexShrink: 0 }}>
        ×
      </button>
    </div>
  )
}
