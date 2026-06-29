'use client'

import { useState, useEffect, useCallback } from 'react'
import saasApi from '@/lib/saas-api'

type ToastType = 'success' | 'danger'

function Toast({ msg, type, onClose }: { msg: string; type: ToastType; onClose: () => void }) {
  useEffect(() => {
    const t = setTimeout(onClose, 3500)
    return () => clearTimeout(t)
  }, [onClose])
  return (
    <div style={{
      position: 'fixed', bottom: 24, right: 24, zIndex: 9999,
      background: type === 'success' ? 'var(--success)' : 'var(--danger)',
      color: '#fff', padding: '12px 20px', borderRadius: 8,
      fontSize: 14, fontWeight: 600, boxShadow: '0 4px 20px rgba(0,0,0,.35)',
      display: 'flex', alignItems: 'center', gap: 10,
    }}>
      <span>{type === 'success' ? '✓' : '✕'}</span>
      {msg}
    </div>
  )
}

function SectionCard({ title, subtitle, children }: { title: string; subtitle?: string; children: React.ReactNode }) {
  return (
    <div style={{
      background: 'var(--card)', border: '1px solid var(--border)',
      borderRadius: 10, overflow: 'hidden', marginBottom: 20,
    }}>
      <div style={{ padding: '16px 24px', borderBottom: '1px solid var(--border)' }}>
        <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--text)' }}>{title}</div>
        {subtitle && <div style={{ fontSize: 13, color: 'var(--muted)', marginTop: 2 }}>{subtitle}</div>}
      </div>
      <div style={{ padding: 24 }}>{children}</div>
    </div>
  )
}

function FieldLabel({ children }: { children: React.ReactNode }) {
  return (
    <label style={{
      display: 'block', fontSize: 12, fontWeight: 600,
      color: 'var(--muted)', textTransform: 'uppercase',
      letterSpacing: '0.05em', marginBottom: 6,
    }}>{children}</label>
  )
}

const inputStyle: React.CSSProperties = {
  width: '100%', padding: '9px 12px', borderRadius: 7,
  border: '1px solid var(--border)', background: 'var(--card)',
  color: 'var(--text)', fontSize: 14, outline: 'none',
  boxSizing: 'border-box', marginBottom: 16,
}

function SaveBtn({ loading, onClick, label = 'Salvar' }: { loading: boolean; onClick: () => void; label?: string }) {
  return (
    <button onClick={onClick} disabled={loading} style={{
      padding: '9px 24px', borderRadius: 7, border: 'none',
      background: loading ? 'var(--border)' : 'var(--accent)',
      color: loading ? 'var(--muted)' : '#000',
      fontSize: 14, fontWeight: 700, cursor: loading ? 'not-allowed' : 'pointer',
      fontFamily: "'Barlow Condensed', sans-serif", transition: 'background 0.15s',
    }}>
      {loading ? '⟳ Salvando...' : label}
    </button>
  )
}

function PasswordInput({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  const [visible, setVisible] = useState(false)
  return (
    <div style={{ marginBottom: 16 }}>
      <FieldLabel>{label}</FieldLabel>
      <div style={{ position: 'relative' }}>
        <input
          type={visible ? 'text' : 'password'}
          value={value}
          onChange={e => onChange(e.target.value)}
          style={{ ...inputStyle, marginBottom: 0, paddingRight: 40 }}
        />
        <button type="button" onClick={() => setVisible(v => !v)} style={{
          position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)',
          background: 'none', border: 'none', cursor: 'pointer',
          color: 'var(--muted)', fontSize: 14, padding: 2,
        }}>{visible ? '🙈' : '👁'}</button>
      </div>
    </div>
  )
}

export default function PerfilPage() {
  const [loading, setLoading] = useState(true)
  const [toast, setToast] = useState<{ msg: string; type: ToastType } | null>(null)

  const [nome, setNome] = useState('')
  const [email, setEmail] = useState('')
  const [salvandoPerfil, setSalvandoPerfil] = useState(false)

  const [senhaAtual, setSenhaAtual] = useState('')
  const [novaSenha, setNovaSenha] = useState('')
  const [confirmaSenha, setConfirmaSenha] = useState('')
  const [salvandoSenha, setSalvandoSenha] = useState(false)

  const showToast = useCallback((msg: string, type: ToastType) => setToast({ msg, type }), [])

  useEffect(() => {
    saasApi.get('/saas/auth/me')
      .then(r => {
        setNome(r.data.nome ?? '')
        setEmail(r.data.email ?? '')
      })
      .catch(() => showToast('Erro ao carregar perfil.', 'danger'))
      .finally(() => setLoading(false))
  }, [showToast])

  async function salvarPerfil() {
    setSalvandoPerfil(true)
    try {
      const r = await saasApi.put('/saas/auth/profile', { nome, email })
      setNome(r.data.nome)
      setEmail(r.data.email)
      // Atualiza nome no localStorage se existir
      const stored = localStorage.getItem('saas_user')
      if (stored) {
        const u = JSON.parse(stored)
        localStorage.setItem('saas_user', JSON.stringify({ ...u, nome: r.data.nome, email: r.data.email }))
      }
      showToast('Perfil atualizado com sucesso.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao atualizar perfil.', 'danger')
    } finally {
      setSalvandoPerfil(false)
    }
  }

  async function salvarSenha() {
    if (novaSenha !== confirmaSenha) {
      showToast('As senhas não coincidem.', 'danger')
      return
    }
    if (novaSenha.length < 8) {
      showToast('A nova senha deve ter pelo menos 8 caracteres.', 'danger')
      return
    }
    setSalvandoSenha(true)
    try {
      await saasApi.put('/saas/auth/password', {
        senha_atual: senhaAtual,
        nova_senha: novaSenha,
        nova_senha_confirmation: confirmaSenha,
      })
      setSenhaAtual('')
      setNovaSenha('')
      setConfirmaSenha('')
      showToast('Senha alterada com sucesso.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao alterar senha.', 'danger')
    } finally {
      setSalvandoSenha(false)
    }
  }

  if (loading) {
    return <div style={{ padding: '40px 32px', color: 'var(--muted)', fontSize: 14 }}>Carregando...</div>
  }

  return (
    <div style={{ padding: '28px 32px', maxWidth: 600, margin: '0 auto', color: 'var(--text)' }}>
      {toast && <Toast msg={toast.msg} type={toast.type} onClose={() => setToast(null)} />}

      <div style={{ marginBottom: 24 }}>
        <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, margin: 0 }}>
          Meu Perfil
        </h1>
        <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
          Dados da conta de administrador SaaS
        </p>
      </div>

      <SectionCard title="Dados Básicos" subtitle="Nome e e-mail de acesso ao painel">
        <FieldLabel>Nome</FieldLabel>
        <input value={nome} onChange={e => setNome(e.target.value)} style={inputStyle} placeholder="Seu nome" />
        <FieldLabel>E-mail</FieldLabel>
        <input value={email} onChange={e => setEmail(e.target.value)} style={inputStyle} placeholder="seu@email.com" type="email" />
        <SaveBtn loading={salvandoPerfil} onClick={salvarPerfil} label="Salvar Dados" />
      </SectionCard>

      <SectionCard title="Alterar Senha" subtitle="Defina uma nova senha de acesso">
        <PasswordInput label="Senha Atual" value={senhaAtual} onChange={setSenhaAtual} />
        <PasswordInput label="Nova Senha (mínimo 8 caracteres)" value={novaSenha} onChange={setNovaSenha} />
        <PasswordInput label="Confirmar Nova Senha" value={confirmaSenha} onChange={setConfirmaSenha} />
        <SaveBtn loading={salvandoSenha} onClick={salvarSenha} label="Alterar Senha" />
      </SectionCard>
    </div>
  )
}
