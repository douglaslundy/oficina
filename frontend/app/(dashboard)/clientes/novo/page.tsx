'use client'
import { useRouter } from 'next/navigation'
import { ClienteForm } from '@/components/forms/ClienteForm'

export default function NovoClientePage() {
  const router = useRouter()
  return (
    <div style={{ maxWidth: 760, margin: '0 auto' }}>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Novo Cliente</h1>
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 32 }}>
        <ClienteForm onSuccess={() => router.push('/clientes')} />
      </div>
    </div>
  )
}
