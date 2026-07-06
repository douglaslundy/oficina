'use client'

interface NotificacaoCardData {
  titulo: string
  subtitulo: string | null
  texto: string
  imagem: string | null
}

export function NotificacaoCard({ notificacao, onFechar }: { notificacao: NotificacaoCardData; onFechar: () => void }) {
  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.7)', zIndex: 2000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 16, width: 460, maxWidth: '100%', maxHeight: '90vh', overflowY: 'auto', boxShadow: '0 12px 48px rgba(0,0,0,.5)' }}>
        {notificacao.imagem && (
          <img src={notificacao.imagem} alt={notificacao.titulo}
            style={{ width: '100%', maxHeight: 220, objectFit: 'cover', display: 'block', borderTopLeftRadius: 16, borderTopRightRadius: 16 }} />
        )}
        <div style={{ padding: 28 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
            <h2 className="font-display" style={{ fontSize: 22, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
              {notificacao.titulo}
            </h2>
            <button onClick={onFechar} aria-label="Fechar"
              style={{ background: 'none', border: 'none', color: 'var(--muted)', fontSize: 24, cursor: 'pointer', lineHeight: 1, padding: 0 }}>×</button>
          </div>
          {notificacao.subtitulo && (
            <p style={{ color: 'var(--accent)', fontSize: 14, fontWeight: 600, margin: '6px 0 0' }}>{notificacao.subtitulo}</p>
          )}
          <p style={{ color: 'var(--text)', fontSize: 14, lineHeight: 1.6, marginTop: 14, whiteSpace: 'pre-wrap' }}>
            {notificacao.texto}
          </p>
          <button onClick={onFechar}
            style={{ width: '100%', marginTop: 22, padding: '11px', borderRadius: 9, border: 'none', background: 'var(--accent)', color: '#000', fontSize: 15, fontWeight: 800, cursor: 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
            Fechar
          </button>
        </div>
      </div>
    </div>
  )
}
