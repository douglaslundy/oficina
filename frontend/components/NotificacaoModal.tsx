'use client'
import { useEffect, useState } from 'react'
import api from '@/lib/api'

interface Notificacao {
  id: string
  titulo: string
  subtitulo: string | null
  texto: string
  imagem: string | null
  vezes_dia: number
  intervalo_minutos: number
}

interface Registro { day: string; count: number; last: number }

function hoje() {
  return new Date().toISOString().slice(0, 10)
}

function ler(id: string): Registro {
  try {
    const raw = localStorage.getItem(`mp_notif_${id}`)
    if (raw) return JSON.parse(raw) as Registro
  } catch { /* ignore */ }
  return { day: '', count: 0, last: 0 }
}

function elegivel(n: Notificacao): boolean {
  const r = ler(n.id)
  const countHoje = r.day === hoje() ? r.count : 0
  if (countHoje >= n.vezes_dia) return false
  if (Date.now() - r.last < n.intervalo_minutos * 60_000) return false
  return true
}

export function NotificacaoModal() {
  const [atual, setAtual] = useState<Notificacao | null>(null)

  useEffect(() => {
    api.get<{ data: Notificacao[] }>('/notificacoes/ativas')
      .then(r => {
        const elegiveis = (r.data.data ?? []).filter(elegivel)
        if (elegiveis.length > 0) setAtual(elegiveis[0])
      })
      .catch(() => { /* silencioso */ })
  }, [])

  function fechar() {
    if (atual) {
      const r = ler(atual.id)
      const count = r.day === hoje() ? r.count + 1 : 1
      try {
        localStorage.setItem(`mp_notif_${atual.id}`, JSON.stringify({ day: hoje(), count, last: Date.now() }))
      } catch { /* ignore */ }
    }
    setAtual(null)
  }

  if (!atual) return null

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.7)', zIndex: 2000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 16, width: 460, maxWidth: '100%', maxHeight: '90vh', overflowY: 'auto', boxShadow: '0 12px 48px rgba(0,0,0,.5)' }}>
        {atual.imagem && (
          <img src={atual.imagem} alt={atual.titulo}
            style={{ width: '100%', maxHeight: 220, objectFit: 'cover', display: 'block', borderTopLeftRadius: 16, borderTopRightRadius: 16 }} />
        )}
        <div style={{ padding: 28 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
            <h2 className="font-display" style={{ fontSize: 22, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
              {atual.titulo}
            </h2>
            <button onClick={fechar} aria-label="Fechar"
              style={{ background: 'none', border: 'none', color: 'var(--muted)', fontSize: 24, cursor: 'pointer', lineHeight: 1, padding: 0 }}>×</button>
          </div>
          {atual.subtitulo && (
            <p style={{ color: 'var(--accent)', fontSize: 14, fontWeight: 600, margin: '6px 0 0' }}>{atual.subtitulo}</p>
          )}
          <p style={{ color: 'var(--text)', fontSize: 14, lineHeight: 1.6, marginTop: 14, whiteSpace: 'pre-wrap' }}>
            {atual.texto}
          </p>
          <button onClick={fechar}
            style={{ width: '100%', marginTop: 22, padding: '11px', borderRadius: 9, border: 'none', background: 'var(--accent)', color: '#000', fontSize: 15, fontWeight: 800, cursor: 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
            Fechar
          </button>
        </div>
      </div>
    </div>
  )
}
