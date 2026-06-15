'use client'
import { useState, useEffect } from 'react'
import { useRouter } from 'next/navigation'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarData } from '@/lib/formatters'
import { usePlanLimites } from '@/hooks/usePlanLimites'
import api from '@/lib/api'

interface Usuario {
  id: string
  nome: string
  email: string
  role: string
  status: string
  ultimo_acesso: string | null
}

function PlanUsageBar({ atual, limite, label }: { atual: number; limite: number; label: string }) {
  if (limite === -1) return null
  const pct = Math.min(100, Math.round((atual / limite) * 100))
  const color = pct >= 100 ? 'var(--danger)' : pct >= 80 ? 'var(--accent)' : 'var(--success)'
  return (
    <div style={{ marginBottom: 20, padding: '12px 16px', borderRadius: 10, background: 'var(--card)', border: '1px solid var(--border)', maxWidth: 380 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
        <span style={{ color: 'var(--muted)', fontSize: 13 }}>{label}</span>
        <span className="font-mono" style={{ fontSize: 13, color, fontWeight: 600 }}>{atual} / {limite}</span>
      </div>
      <div style={{ height: 6, borderRadius: 3, background: 'var(--border)', overflow: 'hidden' }}>
        <div style={{ height: '100%', width: `${pct}%`, borderRadius: 3, background: color, transition: 'width .4s ease' }} />
      </div>
    </div>
  )
}

export default function UsuariosPage() {
  const router = useRouter()
  const [usuarios, setUsuarios] = useState<Usuario[]>([])
  const [loading, setLoading] = useState(true)
  const { limites } = usePlanLimites()

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
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 16 }}>Usuários</h1>
      {limites?.usuarios && (
        <PlanUsageBar atual={limites.usuarios.atual} limite={limites.usuarios.limite} label="Usuários ativos do plano" />
      )}
      <DataTable columns={columns} data={usuarios} loading={loading} emptyMessage="Nenhum usuário cadastrado." onRowClick={r => router.push(`/usuarios/${r.id}`)} />
    </div>
  )
}
