'use client'
import { useState, useEffect } from 'react'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

interface Perfil {
  id: string
  nome: string
  email: string
  cpf: string
  telefone: string | null
  role: string
  status: string
}

const ROLE_LABELS: Record<string, string> = {
  ADMIN:      'Administrador',
  MECANICO:   'Mecânico',
  ATENDENTE:  'Atendente',
  FINANCEIRO: 'Financeiro',
}

const ROLE_COLORS: Record<string, { bg: string; color: string }> = {
  ADMIN:      { bg: 'rgba(245,166,35,.15)', color: 'var(--accent)' },
  MECANICO:   { bg: 'rgba(30,136,229,.15)', color: 'var(--info)' },
  ATENDENTE:  { bg: 'rgba(67,160,71,.15)',  color: 'var(--success)' },
  FINANCEIRO: { bg: 'rgba(122,128,144,.15)', color: 'var(--muted)' },
}

const iStyle: React.CSSProperties = {
  background: 'var(--bg)',
  border: '1px solid var(--border)',
  borderRadius: 8,
  color: 'var(--text)',
  fontSize: 14,
  padding: '10px 12px',
  outline: 'none',
  width: '100%',
  boxSizing: 'border-box',
}

const lStyle: React.CSSProperties = {
  fontSize: 12,
  fontWeight: 700,
  color: 'var(--muted)',
  textTransform: 'uppercase',
  letterSpacing: '0.06em',
  display: 'block',
  marginBottom: 6,
}

function StrengthBar({ senha }: { senha: string }) {
  const score = (() => {
    if (!senha) return 0
    let s = 0
    if (senha.length >= 8) s++
    if (/[A-Z]/.test(senha)) s++
    if (/[0-9]/.test(senha)) s++
    if (/[^A-Za-z0-9]/.test(senha)) s++
    return s
  })()

  const label = ['', 'Fraca', 'Média', 'Forte', 'Muito forte'][score] ?? ''
  const colors = ['var(--border)', 'var(--danger)', 'var(--accent)', 'var(--success)', 'var(--success)']

  if (!senha) return null

  return (
    <div style={{ marginTop: 6 }}>
      <div style={{ display: 'flex', gap: 4 }}>
        {[1, 2, 3, 4].map(i => (
          <div key={i} style={{
            flex: 1, height: 4, borderRadius: 2,
            background: i <= score ? colors[score] : 'var(--border)',
            transition: 'background 0.2s',
          }} />
        ))}
      </div>
      <span style={{ fontSize: 11, color: colors[score], marginTop: 3, display: 'block' }}>
        Força: {label}
      </span>
    </div>
  )
}

export default function MeusDadosPage() {
  const [perfil, setPerfil] = useState<Perfil | null>(null)
  const [nome, setNome] = useState('')
  const [email, setEmail] = useState('')
  const [telefone, setTelefone] = useState('')
  const [senha, setSenha] = useState('')
  const [confirmarSenha, setConfirmarSenha] = useState('')
  const [showSenha, setShowSenha] = useState(false)
  const [showConfirmar, setShowConfirmar] = useState(false)
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api.get('/perfil')
      .then(r => {
        const p = r.data as Perfil
        setPerfil(p)
        setNome(p.nome ?? '')
        setEmail(p.email ?? '')
        setTelefone(p.telefone ?? '')
      })
      .catch(() => toast('Erro ao carregar dados do perfil.', 'danger'))
      .finally(() => setLoading(false))
  }, [])

  async function handleSave(e: React.FormEvent) {
    e.preventDefault()

    if (!nome.trim()) { toast('Informe seu nome.', 'danger'); return }
    if (!email.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      toast('Informe um e-mail válido.', 'danger'); return
    }
    if (senha) {
      if (senha.length < 8) { toast('A senha deve ter no mínimo 8 caracteres.', 'danger'); return }
      if (senha !== confirmarSenha) { toast('As senhas não conferem.', 'danger'); return }
    }

    setSaving(true)
    try {
      const payload: Record<string, string> = {
        nome: nome.trim(),
        email: email.trim(),
        telefone: telefone.trim(),
      }
      if (senha) payload.senha = senha

      const r = await api.put('/perfil', payload)
      const updated = r.data.data as Perfil
      setPerfil(updated)

      // Update cached user name in localStorage for sidebar
      try {
        const stored = localStorage.getItem('auth_user')
        if (stored) {
          const u = JSON.parse(stored)
          localStorage.setItem('auth_user', JSON.stringify({ ...u, nome: updated.nome, email: updated.email }))
        }
      } catch { /* ignore */ }

      setSenha('')
      setConfirmarSenha('')
      toast('Dados atualizados com sucesso!', 'success')
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string } } }
      toast(axiosErr.response?.data?.message ?? 'Erro ao salvar. Tente novamente.', 'danger')
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <div style={{ maxWidth: 640, margin: '0 auto', paddingTop: 32 }}>
        <div style={{ height: 24, background: 'var(--border)', borderRadius: 6, width: 200, marginBottom: 32, animation: 'pulse 1.4s infinite' }} />
        {[1, 2, 3].map(i => (
          <div key={i} style={{ height: 48, background: 'var(--border)', borderRadius: 8, marginBottom: 16, animation: 'pulse 1.4s infinite' }} />
        ))}
      </div>
    )
  }

  if (!perfil) return <p style={{ color: 'var(--danger)' }}>Não foi possível carregar o perfil.</p>

  const roleStyle = ROLE_COLORS[perfil.role] ?? { bg: 'rgba(122,128,144,.15)', color: 'var(--muted)' }

  return (
    <>
      <style>{`@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.45} }`}</style>
      <div style={{ maxWidth: 640, margin: '0 auto' }}>

        {/* Header */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 32, flexWrap: 'wrap' }}>
          <div style={{
            width: 52, height: 52, borderRadius: '50%',
            background: 'var(--card)', border: '2px solid var(--accent)',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            fontSize: 22, fontWeight: 800, color: 'var(--accent)', flexShrink: 0,
          }}>
            {perfil.nome.charAt(0).toUpperCase()}
          </div>
          <div>
            <h1 className="font-display" style={{ fontSize: 24, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
              Meus Dados
            </h1>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 4 }}>
              <span style={{
                display: 'inline-block', padding: '2px 10px', borderRadius: 999,
                fontSize: 11, fontWeight: 700, letterSpacing: '0.04em',
                background: roleStyle.bg, color: roleStyle.color, textTransform: 'uppercase',
              }}>
                {ROLE_LABELS[perfil.role] ?? perfil.role}
              </span>
              <span style={{ fontSize: 12, color: 'var(--muted)' }}>{perfil.cpf ? `CPF ${perfil.cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4')}` : ''}</span>
            </div>
          </div>
        </div>

        <form onSubmit={handleSave}>
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, padding: '24px 28px', display: 'flex', flexDirection: 'column', gap: 20 }}>

            {/* Info fixa */}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <div>
                <label style={lStyle}>CPF</label>
                <input
                  style={{ ...iStyle, opacity: 0.55, cursor: 'not-allowed' }}
                  value={perfil.cpf ? perfil.cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4') : '—'}
                  disabled readOnly
                />
              </div>
              <div>
                <label style={lStyle}>Perfil de acesso</label>
                <input
                  style={{ ...iStyle, opacity: 0.55, cursor: 'not-allowed' }}
                  value={ROLE_LABELS[perfil.role] ?? perfil.role}
                  disabled readOnly
                />
              </div>
            </div>

            <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
              <p style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.07em', margin: '0 0 16px' }}>
                Dados editáveis
              </p>

              <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                <div>
                  <label style={lStyle}>Nome completo <span style={{ color: 'var(--danger)' }}>*</span></label>
                  <input
                    style={iStyle}
                    value={nome}
                    onChange={e => setNome(e.target.value)}
                    placeholder="Seu nome completo"
                    disabled={saving}
                  />
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                  <div>
                    <label style={lStyle}>E-mail <span style={{ color: 'var(--danger)' }}>*</span></label>
                    <input
                      style={iStyle}
                      type="email"
                      value={email}
                      onChange={e => setEmail(e.target.value)}
                      placeholder="seu@email.com"
                      disabled={saving}
                    />
                  </div>
                  <div>
                    <label style={lStyle}>Telefone</label>
                    <input
                      style={iStyle}
                      value={telefone}
                      onChange={e => setTelefone(e.target.value)}
                      placeholder="(11) 99999-9999"
                      disabled={saving}
                    />
                  </div>
                </div>
              </div>
            </div>

            <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
              <p style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.07em', margin: '0 0 16px' }}>
                Alterar senha
              </p>
              <p style={{ fontSize: 12, color: 'var(--muted)', margin: '0 0 16px' }}>
                Deixe em branco para manter a senha atual.
              </p>

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                <div>
                  <label style={lStyle}>Nova senha</label>
                  <div style={{ position: 'relative' }}>
                    <input
                      style={{ ...iStyle, paddingRight: 40 }}
                      type={showSenha ? 'text' : 'password'}
                      value={senha}
                      onChange={e => setSenha(e.target.value)}
                      placeholder="Mínimo 8 caracteres"
                      disabled={saving}
                      autoComplete="new-password"
                    />
                    <button
                      type="button"
                      onClick={() => setShowSenha(v => !v)}
                      style={{ position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)', background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 14, padding: 2 }}
                    >
                      {showSenha ? '🙈' : '👁'}
                    </button>
                  </div>
                  <StrengthBar senha={senha} />
                </div>

                <div>
                  <label style={lStyle}>Confirmar senha</label>
                  <div style={{ position: 'relative' }}>
                    <input
                      style={{
                        ...iStyle,
                        paddingRight: 40,
                        borderColor: confirmarSenha && confirmarSenha !== senha ? 'var(--danger)' : undefined,
                      }}
                      type={showConfirmar ? 'text' : 'password'}
                      value={confirmarSenha}
                      onChange={e => setConfirmarSenha(e.target.value)}
                      placeholder="Repita a nova senha"
                      disabled={saving || !senha}
                      autoComplete="new-password"
                    />
                    <button
                      type="button"
                      onClick={() => setShowConfirmar(v => !v)}
                      style={{ position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)', background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 14, padding: 2 }}
                    >
                      {showConfirmar ? '🙈' : '👁'}
                    </button>
                  </div>
                  {confirmarSenha && confirmarSenha !== senha && (
                    <span style={{ fontSize: 11, color: 'var(--danger)', marginTop: 4, display: 'block' }}>As senhas não conferem</span>
                  )}
                </div>
              </div>
            </div>
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 20 }}>
            <button
              type="submit"
              disabled={saving}
              className="font-display"
              style={{
                padding: '10px 32px',
                background: saving ? 'rgba(245,166,35,.4)' : 'var(--accent)',
                color: '#000',
                border: 'none',
                borderRadius: 8,
                fontSize: 15,
                fontWeight: 800,
                cursor: saving ? 'not-allowed' : 'pointer',
                letterSpacing: '0.03em',
              }}
            >
              {saving ? '⟳ Salvando…' : 'Salvar alterações'}
            </button>
          </div>
        </form>
      </div>
    </>
  )
}
