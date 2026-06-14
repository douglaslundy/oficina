'use client'
import { useEffect, useState } from 'react'
import { useParams, useRouter } from 'next/navigation'
import { OSForm } from '@/components/forms/OSForm'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarMoeda } from '@/lib/formatters'
import api from '@/lib/api'

interface OsData {
  id: string
  numero: number
  status: string
  saldo_devedor: number
  cliente_id: string
  mecanico_id?: string
  problema_relatado?: string
  forma_pagamento?: string
  prazo_entrega?: string
  valor_pago?: number
}

export default function OSDetailPage() {
  const { id } = useParams<{ id: string }>()
  const router = useRouter()
  const [os, setOs] = useState<OsData | null>(null)

  useEffect(() => {
    api.get(`/os/${id}`).then(r => setOs(r.data.data)).catch(() => {})
  }, [id])

  async function downloadPdf() {
    try {
      const token = localStorage.getItem('auth_token')
      const response = await fetch(
        `${process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000'}/api/os/${id}/pdf`,
        { headers: { Authorization: `Bearer ${token}` } }
      )
      if (!response.ok) throw new Error('Erro ao gerar PDF')
      const blob = await response.blob()
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `OS-${os?.numero ?? id}.pdf`
      a.click()
      URL.revokeObjectURL(url)
    } catch {
      alert('Erro ao baixar PDF.')
    }
  }

  if (!os) return <p style={{ color: 'var(--muted)' }}>Carregando...</p>

  return (
    <div style={{ maxWidth: 900, margin: '0 auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 24, flexWrap: 'wrap' }}>
        <button onClick={() => router.back()}
          style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>
          ← Voltar
        </button>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
          OS #{os.numero}
        </h1>
        <StatusPill status={os.status} />
        {os.saldo_devedor > 0 && (
          <span style={{ background: 'rgba(229,57,53,0.15)', color: 'var(--danger)', borderRadius: 8, padding: '4px 12px', fontSize: 14, fontWeight: 700 }}>
            Saldo: {formatarMoeda(os.saldo_devedor)}
          </span>
        )}
        <button onClick={downloadPdf}
          style={{ padding: '6px 14px', background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 8, cursor: 'pointer', fontSize: 13 }}>
          📄 PDF
        </button>
      </div>
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 32 }}>
        <OSForm
          initialData={os}
          onSuccess={updated => setOs(updated as unknown as OsData)}
        />
      </div>
    </div>
  )
}
