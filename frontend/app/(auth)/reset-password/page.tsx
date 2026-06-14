'use client'
import { useState, Suspense } from 'react'
import { useSearchParams } from 'next/navigation'
import Link from 'next/link'
import api from '@/lib/api'
import { PasswordInput } from '@/components/forms/PasswordInput'

function ResetForm() {
  const searchParams = useSearchParams()
  const token = searchParams.get('token')
  const [senha, setSenha] = useState('')
  const [confirmacao, setConfirmacao] = useState('')
  const [loading, setLoading] = useState(false)
  const [done, setDone] = useState(false)
  const [error, setError] = useState('')

  if (!token) return (
    <div style={{ width: '100%', maxWidth: 380, textAlign: 'center' }}>
      <div style={{ fontSize: 36, marginBottom: 16, color: 'var(--danger)' }}>⚠</div>
      <h2 className="font-display" style={{ fontSize: 24, fontWeight: 800, color: 'var(--danger)' }}>Link inválido</h2>
      <p style={{ color: 'var(--muted)', marginBottom: 24 }}>Token não encontrado na URL.</p>
      <Link href="/forgot-password" style={{ padding: '10px 24px', background: 'var(--accent)', color: '#000', borderRadius: 8, textDecoration: 'none', fontWeight: 700 }}>
        Solicitar novo link
      </Link>
    </div>
  )

  if (error === 'EXPIRED') return (
    <div style={{ width: '100%', maxWidth: 380, textAlign: 'center' }}>
      <div style={{ fontSize: 36, marginBottom: 16, color: 'var(--danger)' }}>⚠</div>
      <h2 className="font-display" style={{ fontSize: 24, fontWeight: 800, color: 'var(--danger)', marginBottom: 12 }}>Link expirado</h2>
      <p style={{ color: 'var(--muted)', marginBottom: 24 }}>Este link de recuperação expirou ou já foi utilizado.</p>
      <Link href="/forgot-password" style={{ padding: '10px 24px', background: 'var(--accent)', color: '#000', borderRadius: 8, textDecoration: 'none', fontWeight: 700 }}>
        Solicitar novo link
      </Link>
    </div>
  )

  if (done) return (
    <div style={{ width: '100%', maxWidth: 380, textAlign: 'center' }}>
      <div style={{ fontSize: 36, marginBottom: 16, color: 'var(--success)' }}>✓</div>
      <h2 className="font-display" style={{ fontSize: 24, fontWeight: 800, color: 'var(--success)', marginBottom: 12 }}>Senha redefinida!</h2>
      <p style={{ color: 'var(--muted)', marginBottom: 24 }}>Faça login com sua nova senha.</p>
      <Link href="/login" style={{ color: 'var(--accent)', textDecoration: 'none' }}>← Ir para o login</Link>
    </div>
  )

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (senha.length < 8) {
      setError('A senha deve ter no mínimo 8 caracteres.'); return
    }
    if (!/[A-Z]/.test(senha)) {
      setError('A senha deve conter pelo menos uma letra maiúscula.'); return
    }
    if (!/[0-9]/.test(senha)) {
      setError('A senha deve conter pelo menos um número.'); return
    }
    if (senha !== confirmacao) { setError('As senhas não coincidem — verifique e tente novamente.'); return }
    setLoading(true); setError('')
    try {
      await api.post('/auth/reset-password', { token, password: senha, password_confirmation: confirmacao })
      setDone(true)
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      const msg = e.response?.data?.message ?? 'Não foi possível redefinir a senha. Tente novamente.'
      if (msg.includes('inválido') || msg.includes('expirado')) { setError('EXPIRED') }
      else { setError(msg) }
    } finally { setLoading(false) }
  }

  return (
    <div style={{ width: '100%', maxWidth: 380 }}>
      <Link href="/login" style={{ color: 'var(--muted)', fontSize: 14, textDecoration: 'none', display: 'inline-block', marginBottom: 24 }}>← Voltar ao login</Link>
      <h2 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 8 }}>Nova senha</h2>
      <p style={{ color: 'var(--muted)', fontSize: 14, marginBottom: 32 }}>Crie uma senha segura para sua conta.</p>
      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <div>
          <label style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 6, display: 'block' }}>Nova senha</label>
          <PasswordInput id="nova-senha" value={senha} onChange={setSenha} showStrength placeholder="••••••••" />
        </div>
        <div>
          <label style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 6, display: 'block' }}>Confirmar senha</label>
          <PasswordInput id="confirmar-senha" value={confirmacao} onChange={setConfirmacao} placeholder="••••••••" />
        </div>
        {error && error !== 'EXPIRED' && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
        <button type="submit" disabled={loading} className="font-display"
          style={{ width: '100%', padding: 12, borderRadius: 8, background: loading ? 'var(--muted)' : 'var(--accent)', color: '#000', fontWeight: 800, fontSize: 17, border: 'none', cursor: loading ? 'not-allowed' : 'pointer' }}>
          {loading ? '⟳ Salvando...' : 'Redefinir senha'}
        </button>
      </form>
    </div>
  )
}

export default function ResetPasswordPage() {
  return <Suspense><ResetForm /></Suspense>
}
