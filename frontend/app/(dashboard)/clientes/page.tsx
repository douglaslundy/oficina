'use client'
import { useState, useEffect } from 'react'
import { useRouter } from 'next/navigation'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarCPF, formatarCNPJ, formatarData } from '@/lib/formatters'
import api from '@/lib/api'

interface Cliente {
  id: string
  nome: string
  cpf_cnpj: string
  telefone: string
  veiculo_modelo: string
  veiculo_placa: string
  status: string
  criado_em: string
}

export default function ClientesPage() {
  const router = useRouter()
  const [clientes, setClientes] = useState<Cliente[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')

  useEffect(() => {
    const timeout = setTimeout(() => {
      setLoading(true)
      api.get('/clientes', { params: search ? { search } : {} })
        .then(res => setClientes(res.data.data ?? []))
        .catch(() => setClientes([]))
        .finally(() => setLoading(false))
    }, 300)
    return () => clearTimeout(timeout)
  }, [search])

  const formatDoc = (v: string) => {
    const d = v.replace(/\D/g, '')
    return d.length === 14 ? formatarCNPJ(v) : formatarCPF(v)
  }

  const columns: Column<Cliente>[] = [
    { key: 'nome', label: 'Nome' },
    {
      key: 'cpf_cnpj', label: 'CPF / CNPJ',
      render: r => <span className="font-mono">{formatDoc(r.cpf_cnpj)}</span>
    },
    { key: 'telefone', label: 'Telefone', render: r => r.telefone || '-' },
    {
      key: 'veiculo', label: 'Veículo',
      render: r => r.veiculo_modelo ? `${r.veiculo_modelo}${r.veiculo_placa ? ' · ' + r.veiculo_placa : ''}` : '-'
    },
    { key: 'status', label: 'Situação', render: r => <StatusPill status={r.status} /> },
    { key: 'criado_em', label: 'Cadastro', render: r => formatarData(r.criado_em) },
    {
      key: 'acoes', label: '',
      render: r => (
        <button
          onClick={(e) => { e.stopPropagation(); router.push(`/clientes/${r.id}`) }}
          style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, padding: '4px 12px', cursor: 'pointer', fontSize: 13 }}>
          Ver
        </button>
      )
    },
  ]

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 24 }}>
        <div>
          <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>Clientes</h1>
          <p style={{ color: 'var(--muted)', margin: '4px 0 0', fontSize: 14 }}>{clientes.length} registros</p>
        </div>
        <input value={search} onChange={e => setSearch(e.target.value)}
          placeholder="Buscar por nome, CPF, placa..."
          style={{ padding: '8px 14px', borderRadius: 8, background: 'var(--card)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, width: 280, outline: 'none' }} />
      </div>
      <DataTable
        columns={columns}
        data={clientes}
        loading={loading}
        getRowClass={r => r.status === 'DEVEDOR' ? 'danger-row' : ''}
        onRowClick={r => router.push(`/clientes/${r.id}`)}
        emptyMessage="Nenhum cliente cadastrado."
      />
    </div>
  )
}
