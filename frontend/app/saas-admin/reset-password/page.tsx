'use client'
import { useState, Suspense } from 'react'
import { useSearchParams } from 'next/navigation'
import Link from 'next/link'
import saasApi from '@/lib/saas-api'

function ResetForm() {
  const searchParams = useSearchParams()
  const token = searchParams.get('token')
  const [senha, setSenha] = useState('')
  const [confirmacao, setConfirmacao] = useState('')
  const [showSenha, setShowSenha] = useState(false)
  const [showConfirmacao, setShowConfirmacao] = useState(false)
  const [loading, setLoading] = useState(false)
  const [done, setDone] = useState(false)
  const [error, setError] = useState('')

  function senhaStrength(s: string): { score: number; label: string } {
    let score = 0
    if (s.length >= 8) score++
    if (/[A-Z]/.test(s)) score++
    if (/[0-9]/.test(s)) score++
    if (/[^A-Za-z0-9]/.test(s)) score++
    const labels = ['', 'Fraca', 'Média', 'Forte', 'Forte']
    return { score, label: labels[score] || '' }
  }

  const strength = senhaStrength(senha)
  const strengthColor = strength.score <= 1 ? 'var(--danger)' : strength.score === 2 ? 'var(--accent)' : 'var(--success)'

  if (!token) {
    return (
      <div style={{ textAlign: 'center', padding: 32 }}>
        <div style={{ fontSize: 36, marginBottom: 16, color: 'var(--danger)' }}>⚠</div>
        <h2 className="font-display" style={{ fontSize: 24, fontWeight: 800, color: 'var(--danger)' }}>Link inválido</h2>
        <p style={{ color: 'var(--muted)', marginBottom: 24 }}>Token não encontrado na URL.</p>
        <Link href="/saas-admin/forgot-password" style={{ padding: '10px 24px', background: 'var(--accent)', color: '#000', borderRadius: 8, textDecoration: 'none', fontWeight: 700 }}>
          Solicitar novo link
        </Link>
      </div>
    )
  }

  if (error === 'EXPIRED') {
    return (
      <div style={{ textAlign: 'center', padding: 32 }}>
        <div style={{ fontSize: 36, marginBottom: 16, color: 'var(--danger)' }}>⚠</div>
        <h2 className="font-display" style={{ fontSize: 24, fontWeight: 800, color: 'var(--danger)', marginBottom: 12 }}>Link expirado</h2>
        <p style={{ color: 'var(--muted)', marginBottom: 24 }}>Este link de recuperação expirou ou já foi utilizado.</p>
        <Link href="/saas-admin/forgot-password" style={{ padding: '10px 24px', background: 'var(--accent)', color: '#000', borderRadius: 8, textDecoration: 'none', fontWeight: 700 }}>
          Solicitar novo link
        </Link>
      </div>
    )
  }

  if (done) {
    return (
      <div style={{ textAlign: 'center', padding: 32 }}>
        <div style={{ fontSize: 36, marginBottom: 16, color: 'var(--success)' }}>✓</div>
        <h2 className="font-display" style={{ fontSize: 24, fontWeight: 800, color: 'var(--success)', marginBottom: 12 }}>Senha redefinida!</h2>
        <p style={{ color: 'var(--muted)', marginBottom: 24 }}>Faça login com sua nova senha.</p>
        <Link href="/saas-admin/login" style={{ color: 'var(--accent)', textDecoration: 'none' }}>← Ir para o login</Link>
      </div>
    )
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (senha.length < 8) { setError('A senha deve ter no mínimo 8 caracteres.'); return }
    if (!/[A-Z]/.test(senha)) { setError('A senha deve conter pelo menos uma letra maiúscula.'); return }
    if (!/[0-9]/.test(senha)) { setError('A senha deve conter pelo menos um número.'); return }
    if (senha !== confirmacao) { setError('As senhas não coincidem.'); return }
    setLoading(true)
    setError('')
    try {
      await saasApi.post('/saas/auth/reset-password', {
        token,
        password: senha,
        password_confirmation: confirmacao,
      })
      setDone(true)
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      const msg = e.response?.data?.message ?? 'Não foi possível redefinir a senha. Tente novamente.'
      if (msg.includes('inválido') || msg.includes('expirado')) {
        setError('EXPIRED')
      } else {
        setError(msg)
      }
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
    paddingRight: 44,
  }

  return (
    <div style={{ width: '100%', maxWidth: 380 }}>
      <Link href="/saas-admin/login" style={{ color: 'var(--muted)', fontSize: 14, textDecoration: 'none', display: 'inline-block', marginBottom: 24 }}>
        ← Voltar ao login
      </Link>
      <h2 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 8 }}>Nova senha</h2>
      <p style={{ color: 'var(--muted)', fontSize: 14, marginBottom: 32 }}>Crie uma senha segura para o painel administrativo.</p>

      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <div>
          <label style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 6, display: 'block' }}>Nova senha</label>
          <div style={{ position: 'relative' }}>
            <span style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', fontSize: 15, pointerEvents: 'none' }}>🔒</span>
            <input
              type={showSenha ? 'text' : 'password'}
              value={senha}
              onChange={(e) => setSenha(e.target.value)}
              placeholder="••••••••"
              style={{ ...inputStyle, paddingLeft: 36 }}
            />
            <button type="button" onClick={() => setShowSenha(s => !s)} style={{ position: 'absolute', right: 12, top: '50%', transform: 'translateY(-50%)', background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 16 }}>
              {showSenha ? '🙈' : '👁'}
            </button>
          </div>
          {senha && (
            <div style={{ marginTop: 8 }}>
              <div style={{ display: 'flex', gap: 4, marginBottom: 4 }}>
                {[1, 2, 3, 4].map((i) => (
                  <div key={i} style={{ flex: 1, height: 4, borderRadius: 2, background: i <= strength.score ? strengthColor : 'var(--border)', transition: 'background 0.2s' }} />
                ))}
              </div>
              {strength.label && <p style={{ color: strengthColor, fontSize: 12 }}>Força: {strength.label}</p>}
            </div>
          )}
        </div>

        <div>
          <label style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 6, display: 'block' }}>Confirmar senha</label>
          <div style={{ position: 'relative' }}>
            <span style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', fontSize: 15, pointerEvents: 'none' }}>🔒</span>
            <input
              type={showConfirmacao ? 'text' : 'password'}
              value={confirmacao}
              onChange={(e) => setConfirmacao(e.target.value)}
              placeholder="••••••••"
              style={{ ...inputStyle, paddingLeft: 36 }}
            />
            <button type="button" onClick={() => setShowConfirmacao(s => !s)} style={{ position: 'absolute', right: 12, top: '50%', transform: 'translateY(-50%)', background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 16 }}>
              {showConfirmacao ? '🙈' : '👁'}
            </button>
          </div>
        </div>

        {error && error !== 'EXPIRED' && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}

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
          {loading ? '⟳ Salvando...' : 'Redefinir senha'}
        </button>
      </form>
    </div>
  )
}

export default function SaasAdminResetPasswordPage() {
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

      {/* Right panel */}
      <div style={{
        width: 480,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 48,
      }}>
        <Suspense>
          <ResetForm />
        </Suspense>
      </div>
    </div>
  )
}
