'use client'
import { useEffect, useState } from 'react'
import { useParams, useRouter } from 'next/navigation'
import { UsuarioForm } from '@/components/forms/UsuarioForm'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarData } from '@/lib/formatters'
import api from '@/lib/api'

interface Usuario {
  id: string
  nome: string
  email: string
  cpf: string
  telefone?: string
  role: string
  status: string
  ultimo_acesso: string | null
}

const ROLE_LABELS: Record<string, string> = {
  ADMIN:      'Administrador',
  MECANICO:   'Mecânico',
  ATENDENTE:  'Atendente',
  FINANCEIRO: 'Financeiro',
}

export default function UsuarioDetailPage() {
  const { id } = useParams<{ id: string }>()
  const router = useRouter()
  const [usuario, setUsuario] = useState<Usuario | null>(null)
  const [loading, setLoading] = useState(true)

  const fetchUsuario = () => {
    setLoading(true)
    api.get(`/usuarios/${id}`)
      .then(r => setUsuario(r.data.data as Usuario))
      .catch(() => setUsuario(null))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    fetchUsuario()
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  if (loading) return <p style={{ color: 'var(--muted)' }}>Carregando...</p>
  if (!usuario) return <p style={{ color: 'var(--danger)' }}>Usuário não encontrado.</p>

  return (
    <div style={{ maxWidth: 700, margin: '0 auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 24, flexWrap: 'wrap' }}>
        <button
          onClick={() => router.back()}
          style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}
        >
          ← Voltar
        </button>
        <h1
          className="font-display"
          style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0, flex: 1 }}
        >
          {usuario.nome}
        </h1>
        <span className="pill pill-info">{ROLE_LABELS[usuario.role] ?? usuario.role}</span>
        <StatusPill status={usuario.status} />
      </div>

      {/* Info rápida */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 24 }}>
        {[
          { label: 'E-mail',        value: usuario.email },
          { label: 'Último acesso', value: formatarData(usuario.ultimo_acesso) },
        ].map(({ label, value }) => (
          <div
            key={label}
            style={{ background: 'var(--card)', borderRadius: 10, border: '1px solid var(--border)', padding: 16 }}
          >
            <p style={{ color: 'var(--muted)', fontSize: 12, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', margin: '0 0 6px' }}>
              {label}
            </p>
            <p style={{ color: 'var(--text)', fontSize: 15, margin: 0 }}>{value}</p>
          </div>
        ))}
      </div>

      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 32 }}>
        <h3
          className="font-display"
          style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 20 }}
        >
          Editar usuário
        </h3>
        <UsuarioForm
          initialData={{
            ...usuario,
            role: usuario.role as 'ADMIN' | 'MECANICO' | 'ATENDENTE' | 'FINANCEIRO',
            status: usuario.status as 'ATIVO' | 'INATIVO',
          }}
          onSuccess={fetchUsuario}
        />
      </div>
    </div>
  )
}
