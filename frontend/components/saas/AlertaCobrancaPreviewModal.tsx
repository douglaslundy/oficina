'use client'
import { formatarDataHora } from '@/lib/formatters'

interface AlertaCobrancaPreviewModalProps {
  titulo: string
  mensagem: string
  visualizadoEm: string
  usuarioNome?: string | null
  ip?: string | null
  onClose: () => void
}

export function AlertaCobrancaPreviewModal({ titulo, mensagem, visualizadoEm, usuarioNome, ip, onClose }: AlertaCobrancaPreviewModalProps) {
  const vencida = titulo === 'Fatura vencida'

  return (
    <div style={{
      position: 'fixed', inset: 0, background: 'rgba(0,0,0,.7)', zIndex: 2000,
      display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20,
    }}>
      <div style={{
        background: 'var(--card)', border: `1px solid ${vencida ? 'var(--danger)' : 'var(--border)'}`,
        borderRadius: 14, width: '100%', maxWidth: 480, padding: 32, position: 'relative',
      }}>
        <button
          onClick={onClose}
          style={{ position: 'absolute', top: 16, right: 16, background: 'none', border: 'none', color: 'var(--muted)', fontSize: 20, cursor: 'pointer', lineHeight: 1 }}
        >
          ✕
        </button>

        <div style={{ fontSize: 32, marginBottom: 12 }}>{vencida ? '⚠️' : '💳'}</div>
        <h2 className="font-display" style={{ fontSize: 22, fontWeight: 800, color: vencida ? 'var(--danger)' : 'var(--text)', margin: '0 0 12px' }}>
          {titulo}
        </h2>
        <p style={{ fontSize: 14, color: 'var(--text)', lineHeight: 1.6, margin: '0 0 8px' }}>
          {mensagem}
        </p>

        <p style={{ fontSize: 12, color: 'var(--muted)', margin: '16px 0 0', paddingTop: 16, borderTop: '1px solid var(--border)' }}>
          Visualizado em {formatarDataHora(visualizadoEm)} por {usuarioNome ?? 'Sistema'} · IP {ip ?? '—'}
        </p>

        <button onClick={onClose}
          style={{ width: '100%', marginTop: 22, padding: '11px', borderRadius: 9, border: 'none', background: 'var(--accent)', color: '#000', fontSize: 15, fontWeight: 800, cursor: 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
          Fechar
        </button>
      </div>
    </div>
  )
}
