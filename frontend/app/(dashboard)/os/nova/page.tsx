'use client'
import { useRouter } from 'next/navigation'
import { OSForm } from '@/components/forms/OSForm'

export default function NovaOSPage() {
  const router = useRouter()
  return (
    <div style={{ maxWidth: 900, margin: '0 auto' }}>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>
        Nova Ordem de Serviço
      </h1>
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 32 }}>
        <OSForm onSuccess={os => router.push(`/os/${os.id as string}`)} />
      </div>
    </div>
  )
}
