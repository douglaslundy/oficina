'use client'
import { useToast } from '@/hooks/useToast'

export function ToastContainer() {
  const { toasts, removeToast } = useToast()

  return (
    <div style={{
      position: 'fixed', bottom: 24, right: 24,
      zIndex: 9999, display: 'flex', flexDirection: 'column', gap: 8,
    }}>
      {toasts.map(t => (
        <div key={t.id} onClick={() => removeToast(t.id)}
          style={{
            padding: '12px 20px', borderRadius: 8, fontSize: 14, fontWeight: 500,
            cursor: 'pointer', animation: 'slide-in 0.2s ease',
            background: t.type === 'success' ? 'var(--success)' : t.type === 'danger' ? 'var(--danger)' : 'var(--info)',
            color: '#fff', boxShadow: '0 4px 12px rgba(0,0,0,0.3)',
            minWidth: 260, maxWidth: 400,
          }}>
          {t.message}
        </div>
      ))}
    </div>
  )
}
