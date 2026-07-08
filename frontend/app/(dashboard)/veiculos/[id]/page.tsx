'use client'
import { useEffect, useState } from 'react'
import { useParams, useRouter } from 'next/navigation'
import Link from 'next/link'
import { StatCard } from '@/components/ui/StatCard'
import { StatusPill } from '@/components/ui/StatusPill'
import { DataTable, Column } from '@/components/ui/DataTable'
import { formatarMoeda, formatarPlaca } from '@/lib/formatters'
import { toast } from '@/hooks/useToast'
import api from '@/lib/api'

interface Proprietario { id: string; nome: string; telefone?: string | null }
interface HistoricoProprietario { cliente_id: string; cliente_nome: string | null; data_inicio: string | null; data_fim: string | null }
interface OsHistorico { id: string; numero: number; tipo: string; status: string; valor_total: number; valor_pago: number; mecanico: string | null; criado_em: string }
interface VeiculoDetalhe {
  id: string; modelo: string; ano: number | null; placa: string | null; chassi: string | null; ativo: boolean
  proprietario_atual: Proprietario | null
  historico_proprietarios: HistoricoProprietario[]
  historico_os: OsHistorico[]
  resumo: { total_os: number; valor_total_gasto: number; ultima_visita: string | null }
}
interface ClienteBusca { id: string; nome: string }

export default function VeiculoDetailPage() {
  const { id } = useParams<{ id: string }>()
  const router = useRouter()
  const [veiculo, setVeiculo] = useState<VeiculoDetalhe | null>(null)
  const [loading, setLoading] = useState(true)
  const [transferindo, setTransferindo] = useState(false)
  const [buscaCliente, setBuscaCliente] = useState('')
  const [clientesEncontrados, setClientesEncontrados] = useState<ClienteBusca[]>([])
  const [salvandoTransferencia, setSalvandoTransferencia] = useState(false)

  function carregar() {
    api.get(`/veiculos/${id}`)
      .then(res => setVeiculo(res.data))
      .catch(() => setVeiculo(null))
      .finally(() => setLoading(false))
  }

  useEffect(() => { carregar() }, [id]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!transferindo || !buscaCliente.trim()) {
      setClientesEncontrados([])
      return
    }
    const timeout = setTimeout(() => {
      api.get('/clientes', { params: { search: buscaCliente, per_page: 10 } })
        .then(res => setClientesEncontrados(res.data.data ?? []))
        .catch(() => setClientesEncontrados([]))
    }, 300)
    return () => clearTimeout(timeout)
  }, [buscaCliente, transferindo])

  async function handleTransferir(novoClienteId: string) {
    setSalvandoTransferencia(true)
    try {
      await api.post(`/veiculos/${id}/transferir`, { novo_cliente_id: novoClienteId })
      toast('Veículo transferido com sucesso.', 'success')
      setTransferindo(false)
      setBuscaCliente('')
      setClientesEncontrados([])
      carregar()
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao transferir veículo.', 'danger')
    } finally {
      setSalvandoTransferencia(false)
    }
  }

  if (loading) return <p style={{ color: 'var(--muted)' }}>Carregando...</p>
  if (!veiculo) return <p style={{ color: 'var(--danger)' }}>Veículo não encontrado.</p>

  const osColumns: Column<OsHistorico>[] = [
    { key: 'numero', label: '#', render: r => <span className="font-mono">{r.numero}</span> },
    { key: 'criado_em', label: 'Data' },
    { key: 'status', label: 'Status', render: r => <StatusPill status={r.status} /> },
    { key: 'mecanico', label: 'Mecânico', render: r => r.mecanico ?? '-' },
    { key: 'valor_total', label: 'Valor', render: r => <span className="font-mono">{formatarMoeda(r.valor_total)}</span> },
  ]

  return (
    <div style={{ maxWidth: 1100, margin: '0 auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 24, flexWrap: 'wrap' }}>
        <button onClick={() => router.back()} style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>← Voltar</button>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
          {veiculo.modelo}{veiculo.ano ? ` ${veiculo.ano}` : ''}
        </h1>
        <StatusPill status={veiculo.ativo ? 'ATIVO' : 'INATIVO'} />
        {veiculo.placa && (
          <span className="font-mono" style={{ color: 'var(--accent)', fontSize: 18, fontWeight: 700 }}>
            {formatarPlaca(veiculo.placa)}
          </span>
        )}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16, marginBottom: 24 }}>
        <StatCard title="Total de OS" value={veiculo.resumo.total_os} icon="🔧" color="var(--info)" />
        <StatCard title="Valor Total Gasto" value={formatarMoeda(veiculo.resumo.valor_total_gasto)} icon="💰" color="var(--success)" />
        <StatCard title="Última Visita" value={veiculo.resumo.ultima_visita ?? '-'} icon="📅" color="var(--accent)" />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20, marginBottom: 24 }}>
        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
            <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: 0 }}>Proprietário Atual</h3>
            <button
              onClick={() => setTransferindo(v => !v)}
              style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, padding: '4px 10px', cursor: 'pointer', fontSize: 12 }}
            >
              Transferir
            </button>
          </div>
          {veiculo.proprietario_atual ? (
            <>
              <Link href={`/clientes/${veiculo.proprietario_atual.id}`} style={{ color: 'var(--text)', fontSize: 15, fontWeight: 600, textDecoration: 'none' }}>
                {veiculo.proprietario_atual.nome}
              </Link>
              {veiculo.proprietario_atual.telefone && (
                <p style={{ color: 'var(--muted)', fontSize: 13, margin: '4px 0 0' }}>{veiculo.proprietario_atual.telefone}</p>
              )}
            </>
          ) : (
            <p style={{ color: 'var(--muted)', fontSize: 14 }}>Sem proprietário cadastrado.</p>
          )}

          {transferindo && (
            <div style={{ marginTop: 16, borderTop: '1px solid var(--border)', paddingTop: 16 }}>
              <input
                autoFocus
                placeholder="Buscar cliente por nome..."
                value={buscaCliente}
                onChange={e => setBuscaCliente(e.target.value)}
                style={{ width: '100%', padding: '9px 12px', borderRadius: 8, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }}
              />
              <div style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 4, maxHeight: 200, overflowY: 'auto' }}>
                {clientesEncontrados
                  .filter(c => c.id !== veiculo.proprietario_atual?.id)
                  .map(c => (
                    <div
                      key={c.id}
                      onClick={() => !salvandoTransferencia && handleTransferir(c.id)}
                      style={{ padding: '8px 10px', borderRadius: 6, cursor: 'pointer', fontSize: 14, color: 'var(--text)', background: 'var(--bg)' }}
                    >
                      {c.nome}
                    </div>
                  ))}
              </div>
            </div>
          )}
        </div>

        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
          <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 12 }}>Histórico de Proprietários</h3>
          {veiculo.historico_proprietarios.length === 0 ? (
            <p style={{ color: 'var(--muted)', fontSize: 14 }}>Nenhum histórico registrado.</p>
          ) : (
            veiculo.historico_proprietarios.map((p, i) => (
              <div key={i} style={{ padding: '8px 0', borderBottom: '1px solid var(--border)', display: 'flex', justifyContent: 'space-between' }}>
                <span style={{ color: 'var(--text)', fontSize: 14 }}>{p.cliente_nome ?? '-'}</span>
                <span style={{ color: 'var(--muted)', fontSize: 12 }}>{p.data_inicio} {p.data_fim ? `→ ${p.data_fim}` : '(atual)'}</span>
              </div>
            ))
          )}
        </div>
      </div>

      <div>
        <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 12 }}>Histórico de OS</h3>
        <DataTable
          columns={osColumns}
          data={veiculo.historico_os}
          onRowClick={r => router.push(r.tipo === 'VENDA_BALCAO' ? `/pdv/${r.id}` : `/os/${r.id}`)}
          emptyMessage="Nenhuma OS encontrada para este veículo."
        />
      </div>
    </div>
  )
}
