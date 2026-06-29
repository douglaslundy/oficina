'use client'
import { useState } from 'react'
import Link from 'next/link'
import saasApi from '@/lib/saas-api'

export default function SaasAdminForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [loading, setLoading] = useState(false)
  const [sent, setSent] = useState(false)
  const [error, setError] = useState('')

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setError('Informe um e-mail válido')
      return
    }
    setLoading(true)
    setError('')
    try {
      await saasApi.post('/saas/auth/forgot-password', { email })
      setSent(true)
    } catch {
      setError('Falha ao enviar o e-mail. Verifique sua conexão e tente novamente.')
    } finally {
      setLoading(false)
    }
  }

  const inputStyle: React.CSSProperties = {
    width: '100%',
    padding: '10px 14px',
    borderRadius: 8,
    background: 'var(--card)',
    border: '1px solid var(--border)',
    color: 'var(--text)',
    fontSize: 15,
    outline: 'none',
    boxSizing: 'border-box',
  }

  if (sent) {
    return (
      <div style={{ display: 'flex', minHeight: '100vh', background: 'var(--bg)', alignItems: 'center', justifyContent: 'center' }}>
        <div style={{ width: '100%', maxWidth: 380, textAlign: 'center', padding: 32 }}>
          <div style={{ fontSize: 40, marginBottom: 16 }}>📨</div>
          <h2 className="font-display" style={{ fontSize: 26, fontWeight: 800, color: 'var(--success)', marginBottom: 12 }}>
            E-mail enviado!
          </h2>
          <p style={{ color: 'var(--muted)', fontSize: 14, lineHeight: 1.6, marginBottom: 8 }}>
            Enviamos as instruções para{' '}
            <span style={{ color: 'var(--accent)', fontWeight: 600 }}>{email}</span>.
          </p>
          <p style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 32 }}>O link expira em 30 minutos.</p>
          <Link href="/saas-admin/login" style={{ color: 'var(--accent)', fontSize: 14, textDecoration: 'none' }}>
            ← Voltar ao login
          </Link>
        </div>
      </div>
    )
  }

  return (
    <div style={{ display: 'flex', minHeight: '100vh', background: 'var(--bg)' }}>
      {/* Left panel — brand */}
      <div style={{
        flex: 1,
        background: 'var(--surface)',
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'center',
        padding: '60px',
        position: 'relative',
        overflow: 'hidden',
      }}>
        <div style={{
          position: 'absolute',
          inset: 0,
          backgroundImage: 'radial-gradient(circle at 30% 50%, rgba(245,166,35,0.08) 0%, transparent 60%)',
          pointerEvents: 'none',
        }} />
        <div style={{ position: 'relative', zIndex: 1 }}>
          <div style={{
            width: 72,
            height: 72,
            borderRadius: 18,
            background: 'var(--accent)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontSize: 32,
            marginBottom: 24,
          }}>
            🔧
          </div>
          <h1 className="font-display" style={{ fontSize: 36, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
            MecânicaPro
          </h1>
          <p style={{ color: 'var(--muted)', marginTop: 8, fontSize: 16 }}>
            Painel SaaS Admin
          </p>
        </div>
      </div>

      {/* Right panel — form */}
      <div style={{
        width: 480,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 48,
      }}>
        <div style={{ width: '100%', maxWidth: 380 }}>
          <Link
            href="/saas-admin/login"
            style={{ color: 'var(--muted)', fontSize: 14, textDecoration: 'none', display: 'inline-block', marginBottom: 24 }}
          >
            ← Voltar ao login
          </Link>

          <h2 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 8 }}>
            Recuperar senha
          </h2>
          <p style={{ color: 'var(--muted)', fontSize: 14, marginBottom: 32 }}>
            Informe seu e-mail de administrador para receber o link de recuperação.
          </p>

          <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <div>
              <label style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 6, display: 'block' }}>
                E-mail
              </label>
              <div style={{ position: 'relative' }}>
                <span style={{
                  position: 'absolute',
                  left: 12,
                  top: '50%',
                  transform: 'translateY(-50%)',
                  fontSize: 15,
                  pointerEvents: 'none',
                }}>✉</span>
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="super@mecanicapro.com"
                  autoComplete="email"
                  style={{ ...inputStyle, paddingLeft: 36 }}
                />
              </div>
              {error && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{error}</p>}
            </div>

            <button
              type="submit"
              disabled={loading}
              className="font-display"
              style={{
                width: '100%',
                padding: 12,
                borderRadius: 8,
                background: loading ? 'var(--muted)' : 'var(--accent)',
                color: '#000',
                fontWeight: 800,
                fontSize: 17,
                border: 'none',
                cursor: loading ? 'not-allowed' : 'pointer',
              }}
            >
              {loading ? '⟳ Enviando...' : 'Enviar link de recuperação'}
            </button>
          </form>
        </div>
      </div>
    </div>
  )
}
