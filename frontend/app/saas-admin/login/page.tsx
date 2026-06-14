'use client'
import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { z } from 'zod'
import saasApi from '@/lib/saas-api'

const schema = z.object({
  email: z.string().email('E-mail inválido'),
  senha: z.string().min(1, 'Senha obrigatória'),
})

type FormErrors = Partial<Record<'email' | 'senha', string>>

export default function SaasAdminLoginPage() {
  const router = useRouter()
  const [email, setEmail] = useState('')
  const [senha, setSenha] = useState('')
  const [showSenha, setShowSenha] = useState(false)
  const [loading, setLoading] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<FormErrors>({})
  const [globalError, setGlobalError] = useState('')

  function validate(): FormErrors {
    const result = schema.safeParse({ email, senha })
    if (result.success) return {}
    const errs: FormErrors = {}
    for (const issue of result.error.issues) {
      const field = issue.path[0] as keyof FormErrors
      if (!errs[field]) errs[field] = issue.message
    }
    return errs
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    const errs = validate()
    if (Object.keys(errs).length) {
      setFieldErrors(errs)
      return
    }
    setFieldErrors({})
    setGlobalError('')
    setLoading(true)
    try {
      const { data } = await saasApi.post<{ token: string; user: { id: string; nome: string; email: string } }>(
        '/saas/auth/login',
        { email, senha }
      )
      localStorage.setItem('saas_token', data.token)
      document.cookie = `saas_token=${data.token}; path=/; max-age=${7 * 24 * 3600}`
      router.push('/saas-admin')
    } catch {
      setGlobalError('Credenciais inválidas.')
    } finally {
      setLoading(false)
    }
  }

  const inputStyle = (hasError: boolean): React.CSSProperties => ({
    width: '100%',
    padding: '10px 14px',
    borderRadius: 8,
    background: 'var(--card)',
    border: `1px solid ${hasError ? 'var(--danger)' : 'var(--border)'}`,
    color: 'var(--text)',
    fontSize: 15,
    outline: 'none',
    boxSizing: 'border-box',
  })

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
          <ul style={{ listStyle: 'none', padding: 0, marginTop: 40, display: 'flex', flexDirection: 'column', gap: 12 }}>
            {[
              'Gestão de oficinas cadastradas',
              'Planos e cobranças',
              'Métricas globais',
            ].map((f) => (
              <li key={f} style={{ color: 'var(--muted)', fontSize: 15 }}>
                <span style={{ color: 'var(--accent)', marginRight: 8 }}>✓</span>{f}
              </li>
            ))}
          </ul>
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
          <h2 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 8 }}>
            Acesso restrito
          </h2>
          <p style={{ color: 'var(--muted)', marginBottom: 32, fontSize: 14 }}>
            Área exclusiva para administradores do sistema
          </p>

          <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            {/* E-mail */}
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
                }}>
                  ✉
                </span>
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="super@mecanicapro.com"
                  autoComplete="email"
                  style={{ ...inputStyle(!!fieldErrors.email), paddingLeft: 36 }}
                />
              </div>
              {fieldErrors.email && (
                <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{fieldErrors.email}</p>
              )}
            </div>

            {/* Senha */}
            <div>
              <label style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 6, display: 'block' }}>
                Senha
              </label>
              <div style={{ position: 'relative' }}>
                <span style={{
                  position: 'absolute',
                  left: 12,
                  top: '50%',
                  transform: 'translateY(-50%)',
                  fontSize: 15,
                  pointerEvents: 'none',
                }}>
                  🔒
                </span>
                <input
                  type={showSenha ? 'text' : 'password'}
                  value={senha}
                  onChange={(e) => setSenha(e.target.value)}
                  placeholder="••••••••"
                  autoComplete="current-password"
                  style={{ ...inputStyle(!!fieldErrors.senha), paddingLeft: 36, paddingRight: 44 }}
                />
                <button
                  type="button"
                  onClick={() => setShowSenha((s) => !s)}
                  style={{
                    position: 'absolute',
                    right: 12,
                    top: '50%',
                    transform: 'translateY(-50%)',
                    background: 'none',
                    border: 'none',
                    color: 'var(--muted)',
                    cursor: 'pointer',
                    fontSize: 16,
                  }}
                >
                  {showSenha ? '🙈' : '👁'}
                </button>
              </div>
              {fieldErrors.senha && (
                <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{fieldErrors.senha}</p>
              )}
            </div>

            {/* Global error */}
            {globalError && (
              <div style={{
                background: 'rgba(229,57,53,0.1)',
                border: '1px solid var(--danger)',
                borderRadius: 8,
                padding: '10px 14px',
                color: 'var(--danger)',
                fontSize: 14,
              }}>
                {globalError}
              </div>
            )}

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
                marginTop: 8,
              }}
            >
              {loading ? '⟳ Verificando...' : 'Entrar'}
            </button>
          </form>

          {process.env.NODE_ENV === 'development' && (
            <div style={{
              marginTop: 32,
              padding: 16,
              background: 'var(--card)',
              borderRadius: 8,
              border: '1px solid var(--border)',
            }}>
              <p style={{ color: 'var(--muted)', fontSize: 12, marginBottom: 8 }}>Acesso rápido (demo):</p>
              <button
                onClick={() => { setEmail('superadmin@mecanicapro.com'); setSenha('super123') }}
                style={{
                  background: 'none',
                  border: 'none',
                  color: 'var(--accent)',
                  fontSize: 13,
                  cursor: 'pointer',
                  padding: 0,
                  display: 'block',
                  textAlign: 'left',
                }}
              >
                Super Admin → superadmin@mecanicapro.com / super123
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
