'use client'
import { useState, useEffect, Suspense } from 'react'
import Link from 'next/link'
import { useSearchParams } from 'next/navigation'
import { useAuth } from '@/hooks/useAuth'

function LoginForm() {
  const { login, loading, error } = useAuth()
  const searchParams = useSearchParams()
  const defaultTenant = process.env.NEXT_PUBLIC_DEFAULT_TENANT ?? ''
  const [oficinaSlag, setOficinaSlag] = useState(defaultTenant)
  const [email, setEmail] = useState('')
  const [senha, setSenha] = useState('')
  const [showSenha, setShowSenha] = useState(false)
  const [lembrar, setLembrar] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<{ oficina_slug?: string; email?: string; senha?: string }>({})

  useEffect(() => {
    const remembered = localStorage.getItem('remember_email')
    if (remembered) { setEmail(remembered); setLembrar(true) }
    const slugFromUrl = searchParams.get('oficina')
    if (slugFromUrl) setOficinaSlag(slugFromUrl)
  }, [searchParams])

  function validate() {
    const errs: { oficina_slug?: string; email?: string; senha?: string } = {}
    if (!oficinaSlag.trim()) errs.oficina_slug = 'Informe o código da oficina'
    else if (!/^[a-z0-9-]+$/.test(oficinaSlag)) errs.oficina_slug = 'Código inválido — use apenas letras minúsculas, números e hífens'
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errs.email = 'Informe um e-mail válido'
    if (!senha.trim()) errs.senha = 'Informe sua senha'
    return errs
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    const errs = validate()
    if (Object.keys(errs).length) { setFieldErrors(errs); return }
    setFieldErrors({})
    await login(email, senha, lembrar, oficinaSlag)
  }

  const inputStyle = (hasError: boolean): React.CSSProperties => ({
    width: '100%', padding: '10px 14px', borderRadius: 8,
    background: 'var(--card)', border: `1px solid ${hasError ? 'var(--danger)' : 'var(--border)'}`,
    color: 'var(--text)', fontSize: 15, outline: 'none', boxSizing: 'border-box',
  })

  return (
    <div style={{ width: '100%', maxWidth: 380 }}>
      <h2 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 8 }}>
        Entrar na conta
      </h2>
      <p style={{ color: 'var(--muted)', marginBottom: 32, fontSize: 14 }}>
        Acesse o painel de gestão da sua oficina
      </p>

      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        {!defaultTenant && (
          <div>
            <label style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 6, display: 'block' }}>Código da oficina</label>
            <input type="text" value={oficinaSlag} onChange={e => setOficinaSlag(e.target.value)}
              placeholder="minha-oficina" autoComplete="organization"
              style={inputStyle(!!fieldErrors.oficina_slug)} />
            {fieldErrors.oficina_slug && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{fieldErrors.oficina_slug}</p>}
          </div>
        )}

        <div>
          <label style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 6, display: 'block' }}>E-mail</label>
          <input type="email" value={email} onChange={e => setEmail(e.target.value)}
            placeholder="seu@email.com" autoComplete="email"
            style={inputStyle(!!fieldErrors.email)} />
          {fieldErrors.email && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{fieldErrors.email}</p>}
        </div>

        <div>
          <label style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 6, display: 'block' }}>Senha</label>
          <div style={{ position: 'relative' }}>
            <input type={showSenha ? 'text' : 'password'} value={senha}
              onChange={e => setSenha(e.target.value)} placeholder="••••••••"
              style={{ ...inputStyle(!!fieldErrors.senha), paddingRight: 44 }} />
            <button type="button" onClick={() => setShowSenha(s => !s)}
              style={{ position: 'absolute', right: 12, top: '50%', transform: 'translateY(-50%)',
                background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 16 }}>
              {showSenha ? '🙈' : '👁'}
            </button>
          </div>
          {fieldErrors.senha && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{fieldErrors.senha}</p>}
        </div>

        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--muted)', fontSize: 14, cursor: 'pointer' }}>
            <input type="checkbox" checked={lembrar} onChange={e => setLembrar(e.target.checked)} />
            Lembrar de mim
          </label>
          <Link href="/forgot-password" style={{ color: 'var(--muted)', fontSize: 14, textDecoration: 'none' }}>
            Esqueci minha senha
          </Link>
        </div>

        {error && (
          <div style={{ background: 'rgba(229,57,53,0.1)', border: '1px solid var(--danger)', borderRadius: 8, padding: '10px 14px', color: 'var(--danger)', fontSize: 14 }}>
            {error}
          </div>
        )}

        <button type="submit" disabled={loading}
          className="font-display"
          style={{
            width: '100%', padding: 12, borderRadius: 8,
            background: loading ? 'var(--muted)' : 'var(--accent)',
            color: '#000', fontWeight: 800, fontSize: 17, border: 'none',
            cursor: loading ? 'not-allowed' : 'pointer', marginTop: 8,
          }}>
          {loading ? '⟳ Verificando...' : 'Entrar'}
        </button>
      </form>

      {process.env.NODE_ENV === 'development' && (
        <div style={{ marginTop: 32, padding: 16, background: 'var(--card)', borderRadius: 8, border: '1px solid var(--border)' }}>
          <p style={{ color: 'var(--muted)', fontSize: 12, marginBottom: 8 }}>Acesso rápido (demo):</p>
          <button onClick={() => { setOficinaSlag('oficina-silva'); setEmail('admin@mecanicapro.com'); setSenha('admin123') }}
            style={{ background: 'none', border: 'none', color: 'var(--accent)', fontSize: 13, cursor: 'pointer', padding: 0, display: 'block', marginBottom: 4, textAlign: 'left' }}>
            Admin → admin@mecanicapro.com / admin123
          </button>
          <button onClick={() => { setOficinaSlag('oficina-silva'); setEmail('mecanico@mecanicapro.com'); setSenha('mec123') }}
            style={{ background: 'none', border: 'none', color: 'var(--accent)', fontSize: 13, cursor: 'pointer', padding: 0, textAlign: 'left' }}>
            Mecânico → mecanico@mecanicapro.com / mec123
          </button>
        </div>
      )}
    </div>
  )
}

export default function LoginPage() {
  return (
    <Suspense>
      <LoginForm />
    </Suspense>
  )
}
