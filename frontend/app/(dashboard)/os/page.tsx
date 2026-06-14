'use client'
import { useState, useEffect } from 'react'
import { useRouter } from 'next/navigation'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarMoeda, formatarData } from '@/lib/formatters'
import api from '@/lib/api'

interface OS {
  id: string
  numero: number
  cliente?: { nome: string; veiculo_placa?: string }
  veiculo_placa?: string
  problema_relatado?: string
  valor_total: number
  status: string
  criado_em: string
}

export default function OSPage() {
  const router = useRouter()
  const [os, setOs] = useState<OS[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api.get('/os').then(r => setOs(r.data.data ?? [])).catch(() => {}).finally(() => setLoading(false))
  }, [])

  const columns: Column<OS>[] = [
    { key: 'numero', label: '#OS', render: r => <span className="font-mono" style={{ color: 'var(--accent)' }}>#{r.numero}</span> },
    { key: 'cliente', label: 'Cliente', render: r => r.cliente?.nome ?? '-' },
    { key: 'veiculo', label: 'Veículo', render: r => r.cliente?.veiculo_placa ?? r.veiculo_placa ?? '-' },
    { key: 'problema', label: 'Serviço', render: r => <span style={{ color: 'var(--text)', fontSize: 13 }}>{r.problema_relatado?.slice(0, 50) ?? '-'}</span> },
    { key: 'valor_total', label: 'Valor', render: r => <span className="font-mono">{formatarMoeda(r.valor_total)}</span> },
    { key: 'status', label: 'Status', render: r => <StatusPill status={r.status} /> },
    { key: 'criado_em', label: 'Data', render: r => formatarData(r.criado_em) },
  ]

  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>
        Ordens de Serviço
      </h1>
      <DataTable
        columns={columns}
        data={os}
        loading={loading}
        onRowClick={r => router.push(`/os/${r.id}`)}
        emptyMessage="Nenhuma OS encontrada."
      />
    </div>
  )
}
