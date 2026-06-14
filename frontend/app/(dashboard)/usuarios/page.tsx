'use client'
import { useState, useEffect } from 'react'
import { useRouter } from 'next/navigation'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarData } from '@/lib/formatters'
import api from '@/lib/api'

interface Usuario {
  id: string
  nome: string
  email: string
  role: string
  status: string
  ultimo_acesso: string | null
}

export default function UsuariosPage() {
  const router = useRouter()
  const [usuarios, setUsuarios] = useState<Usuario[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api.get('/usuarios').then(r => setUsuarios(r.data.data ?? [])).catch(() => {}).finally(() => setLoading(false))
  }, [])

  const columns: Column<Usuario>[] = [
    { key: 'nome', label: 'Nome', render: r => <span style={{ color: 'var(--text)', fontWeight: 500 }}>{r.nome}</span> },
    { key: 'email', label: 'E-mail', render: r => <span style={{ color: 'var(--muted)', fontSize: 13 }}>{r.email}</span> },
    { key: 'role', label: 'Perfil', render: r => <span className="pill pill-info">{r.role}</span> },
    { key: 'ultimo_acesso', label: 'Último acesso', render: r => formatarData(r.ultimo_acesso) },
    { key: 'status', label: 'Status', render: r => <StatusPill status={r.status} /> },
  ]

  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Usuários</h1>
      <DataTable columns={columns} data={usuarios} loading={loading} emptyMessage="Nenhum usuário cadastrado." onRowClick={r => router.push(`/usuarios/${r.id}`)} />
    </div>
  )
}
