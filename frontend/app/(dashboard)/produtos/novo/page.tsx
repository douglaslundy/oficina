'use client'
import { useRouter } from 'next/navigation'
import { ProdutoForm } from '@/components/forms/ProdutoForm'

export default function NovoProdutoPage() {
  const router = useRouter()
  return (
    <div style={{ maxWidth: 700, margin: '0 auto' }}>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>
        Novo Produto
      </h1>
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 32 }}>
        <ProdutoForm onSuccess={() => router.push('/produtos')} />
      </div>
    </div>
  )
}
