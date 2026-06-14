'use client'
import { useState } from 'react'
import Link from 'next/link'
import api from '@/lib/api'

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [loading, setLoading] = useState(false)
  const [sent, setSent] = useState(false)
  const [error, setError] = useState('')

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setError('Informe um e-mail válido'); return }
    setLoading(true); setError('')
    try {
      await api.post('/auth/forgot-password', { email })
      setSent(true)
    } catch { setError('Falha ao enviar o e-mail. Verifique sua conexão e tente novamente.') }
    finally { setLoading(false) }
  }

  const inputStyle: React.CSSProperties = {
    width: '100%', padding: '10px 14px', borderRadius: 8,
    background: 'var(--card)', border: '1px solid var(--border)',
    color: 'var(--text)', fontSize: 15, outline: 'none', boxSizing: 'border-box',
  }

  if (sent) return (
    <div style={{ width: '100%', maxWidth: 380, textAlign: 'center' }}>
      <div style={{ fontSize: 40, marginBottom: 16 }}>📨</div>
      <h2 className="font-display" style={{ fontSize: 26, fontWeight: 800, color: 'var(--success)', marginBottom: 12 }}>E-mail enviado!</h2>
      <p style={{ color: 'var(--muted)', fontSize: 14, lineHeight: 1.6, marginBottom: 8 }}>
        Enviamos as instruções para <span style={{ color: 'var(--accent)', fontWeight: 600 }}>{email}</span>.
      </p>
      <p style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 32 }}>O link expira em 30 minutos.</p>
      <Link href="/login" style={{ color: 'var(--accent)', fontSize: 14, textDecoration: 'none' }}>← Voltar ao login</Link>
    </div>
  )

  return (
    <div style={{ width: '100%', maxWidth: 380 }}>
      <Link href="/login" style={{ color: 'var(--muted)', fontSize: 14, textDecoration: 'none', display: 'inline-block', marginBottom: 24 }}>← Voltar ao login</Link>
      <h2 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 8 }}>Recuperar senha</h2>
      <p style={{ color: 'var(--muted)', fontSize: 14, marginBottom: 32 }}>Informe seu e-mail para receber o link de recuperação.</p>
      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <div>
          <label style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 6, display: 'block' }}>E-mail cadastrado</label>
          <input type="email" value={email} onChange={e => setEmail(e.target.value)} style={inputStyle} placeholder="seu@email.com" />
          {error && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{error}</p>}
        </div>
        <button type="submit" disabled={loading} className="font-display"
          style={{ width: '100%', padding: 12, borderRadius: 8, background: loading ? 'var(--muted)' : 'var(--accent)', color: '#000', fontWeight: 800, fontSize: 17, border: 'none', cursor: loading ? 'not-allowed' : 'pointer' }}>
          {loading ? '⟳ Enviando...' : 'Enviar link de recuperação'}
        </button>
      </form>
    </div>
  )
}
