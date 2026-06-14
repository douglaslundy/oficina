'use client'
import { useEffect, useState } from 'react'
import { useParams, useRouter } from 'next/navigation'
import { ClienteForm } from '@/components/forms/ClienteForm'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarMoeda, formatarData } from '@/lib/formatters'
import api from '@/lib/api'

interface OsResumo {
  id: string
  numero: number
  status: string
  saldo_devedor: number
  criado_em: string
}

interface ClienteData {
  id: string
  nome: string
  status: string
  cpf_cnpj: string
  telefone?: string
  email?: string
  cep?: string
  endereco?: string
  bairro?: string
  cidade?: string
  uf?: string
  veiculo_modelo?: string
  veiculo_ano?: number
  veiculo_placa?: string
}

export default function ClienteDetailPage() {
  const { id } = useParams<{ id: string }>()
  const router = useRouter()
  const [cliente, setCliente] = useState<ClienteData | null>(null)
  const [os, setOs] = useState<OsResumo[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    Promise.all([
      api.get(`/clientes/${id}`),
      api.get(`/os?cliente_id=${id}`),
    ]).then(([c, o]) => {
      setCliente(c.data.data)
      setOs(o.data.data ?? [])
    }).catch(() => {})
    .finally(() => setLoading(false))
  }, [id])

  if (loading) return <p style={{ color: 'var(--muted)' }}>Carregando...</p>
  if (!cliente) return <p style={{ color: 'var(--danger)' }}>Cliente não encontrado.</p>

  const saldoDevedor = os.filter(o => o.saldo_devedor > 0).reduce((acc, o) => acc + o.saldo_devedor, 0)

  return (
    <div style={{ maxWidth: 1620, margin: '0 auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 24, flexWrap: 'wrap' }}>
        <button onClick={() => router.back()} style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>← Voltar</button>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>{cliente.nome}</h1>
        <StatusPill status={cliente.status} />
        {saldoDevedor > 0 && (
          <span style={{ background: 'rgba(229,57,53,0.15)', color: 'var(--danger)', borderRadius: 8, padding: '4px 12px', fontSize: 14, fontWeight: 700 }}>
            Débito: {formatarMoeda(saldoDevedor)}
          </span>
        )}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1.3fr 0.7fr', gap: 24 }}>
        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
          <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 16 }}>Editar dados</h3>
          <ClienteForm initialData={cliente} onSuccess={() => api.get(`/clientes/${id}`).then(r => setCliente(r.data.data))} />
        </div>

        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
          <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 16 }}>Histórico de OS</h3>
          {os.length === 0 ? (
            <p style={{ color: 'var(--muted)', fontSize: 14 }}>Nenhuma OS encontrada.</p>
          ) : (
            os.map(o => (
              <div key={o.id} style={{ padding: '10px 0', borderBottom: '1px solid var(--border)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <div>
                  <p style={{ color: 'var(--text)', fontSize: 14, margin: 0, fontWeight: 600 }}>OS #{o.numero}</p>
                  <p style={{ color: 'var(--muted)', fontSize: 12, margin: 0 }}>{formatarData(o.criado_em)}</p>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <StatusPill status={o.status} />
                  {o.saldo_devedor > 0 && (
                    <p style={{ color: 'var(--danger)', fontSize: 12, margin: '4px 0 0', fontWeight: 700 }}>{formatarMoeda(o.saldo_devedor)}</p>
                  )}
                </div>
              </div>
            ))
          )}
        </div>
      </div>
    </div>
  )
}
