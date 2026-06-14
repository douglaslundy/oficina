'use client'
import { useRouter } from 'next/navigation'
import { UsuarioForm } from '@/components/forms/UsuarioForm'

export default function NovoUsuarioPage() {
  const router = useRouter()
  return (
    <div style={{ maxWidth: 700, margin: '0 auto' }}>
      <h1
        className="font-display"
        style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}
      >
        Novo Usuário
      </h1>
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 32 }}>
        <UsuarioForm onSuccess={() => router.push('/usuarios')} />
      </div>
    </div>
  )
}
