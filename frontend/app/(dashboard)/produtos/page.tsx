'use client'
import { useState, useEffect, useCallback } from 'react'
import { useRouter } from 'next/navigation'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { StockBar } from '@/components/ui/StockBar'
import { formatarMoeda } from '@/lib/formatters'
import { usePlanLimites } from '@/hooks/usePlanLimites'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

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

interface Produto {
  id: string
  nome: string
  sku: string
  categoria: string
  qty_atual: number
  qty_minima: number
  preco_venda: number | null
  status_estoque: string
}

export default function ProdutosPage() {
  const router = useRouter()
  const [produtos, setProdutos] = useState<Produto[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [showEntrada, setShowEntrada] = useState<string | null>(null)
  const [qtdEntrada, setQtdEntrada] = useState(1)
  const [motivoEntrada, setMotivoEntrada] = useState('')
  const { limites } = usePlanLimites()

  const fetchProdutos = useCallback(() => {
    setLoading(true)
    api.get('/produtos', { params: search ? { search } : {} })
      .then(res => setProdutos(res.data.data ?? []))
      .catch(() => setProdutos([]))
      .finally(() => setLoading(false))
  }, [search])

  useEffect(() => {
    const t = setTimeout(fetchProdutos, 300)
    return () => clearTimeout(t)
  }, [fetchProdutos])

  async function registrarEntrada(produtoId: string) {
    try {
      await api.post(`/produtos/${produtoId}/estoque/entrada`, {
        quantidade: qtdEntrada,
        motivo: motivoEntrada || 'Compra de fornecedor',
      })
      toast('Entrada registrada com sucesso!', 'success')
      setShowEntrada(null)
      fetchProdutos()
    } catch {
      toast('Erro ao registrar entrada.', 'danger')
    }
  }

  const columns: Column<Produto>[] = [
    {
      key: 'nome', label: 'Produto',
      render: r => (
        <div>
          <p style={{ margin: 0, color: 'var(--text)', fontWeight: 500 }}>{r.nome}</p>
          <p className="font-mono" style={{ margin: 0, color: 'var(--muted)', fontSize: 12 }}>{r.sku}</p>
        </div>
      ),
    },
    {
      key: 'categoria', label: 'Categoria',
      render: r => <span style={{ color: 'var(--muted)', fontSize: 13 }}>{r.categoria}</span>,
    },
    {
      key: 'estoque', label: 'Estoque',
      render: r => <StockBar qtyAtual={r.qty_atual} qtyMinima={r.qty_minima} status={r.status_estoque} />,
    },
    {
      key: 'qty_minima', label: 'Mínimo',
      render: r => <span className="font-mono" style={{ color: 'var(--muted)' }}>{r.qty_minima}</span>,
    },
    {
      key: 'preco_venda', label: 'Preço Venda',
      render: r => (
        <span className="font-mono" style={{ color: 'var(--text)' }}>
          {r.preco_venda ? formatarMoeda(r.preco_venda) : '-'}
        </span>
      ),
    },
    {
      key: 'status_estoque', label: 'Status',
      render: r => <StatusPill status={r.status_estoque} />,
    },
    {
      key: 'acoes', label: '',
      render: r => (
        <div style={{ display: 'flex', gap: 8 }}>
          <button
            onClick={e => {
              e.stopPropagation()
              setShowEntrada(r.id)
              setQtdEntrada(1)
              setMotivoEntrada('')
            }}
            style={{
              background: 'none',
              border: '1px solid var(--border)',
              color: 'var(--accent)',
              borderRadius: 6,
              padding: '4px 10px',
              cursor: 'pointer',
              fontSize: 13,
              whiteSpace: 'nowrap',
            }}>
            + Entrada
          </button>
          <button
            onClick={e => {
              e.stopPropagation()
              router.push(`/produtos/${r.id}`)
            }}
            style={{
              background: 'none',
              border: '1px solid var(--border)',
              color: 'var(--muted)',
              borderRadius: 6,
              padding: '4px 10px',
              cursor: 'pointer',
              fontSize: 13,
            }}>
            Editar
          </button>
        </div>
      ),
    },
  ]

  const inputStyle: React.CSSProperties = {
    width: '100%',
    padding: '9px 12px',
    borderRadius: 8,
    background: 'var(--bg)',
    border: '1px solid var(--border)',
    color: 'var(--text)',
    fontSize: 14,
    outline: 'none',
    boxSizing: 'border-box',
  }

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1
          className="font-display"
          style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
          Produtos / Estoque
        </h1>
        <div style={{ display: 'flex', gap: 12 }}>
          <button
            onClick={() => router.push('/produtos/entrada-nf')}
            style={{
              padding: '8px 16px',
              borderRadius: 8,
              background: 'rgba(245,166,35,0.12)',
              border: '1px solid var(--accent)',
              color: 'var(--accent)',
              cursor: 'pointer',
              fontSize: 14,
              whiteSpace: 'nowrap',
            }}>
            + Lançar NF
          </button>
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Buscar por nome ou SKU..."
            style={{
              padding: '8px 14px',
              borderRadius: 8,
              background: 'var(--card)',
              border: '1px solid var(--border)',
              color: 'var(--text)',
              fontSize: 14,
              width: 280,
              outline: 'none',
            }}
          />
        </div>
      </div>

      {limites?.produtos && (
        <PlanUsageBar atual={limites.produtos.atual} limite={limites.produtos.limite} label="Produtos cadastrados no plano" />
      )}

      <DataTable
        columns={columns}
        data={produtos}
        loading={loading}
        getRowClass={r =>
          r.status_estoque === 'CRITICO' || r.status_estoque === 'SEM_ESTOQUE' ? 'danger-row' : ''
        }
        onRowClick={r => router.push(`/produtos/${r.id}`)}
        emptyMessage="Nenhum produto cadastrado."
      />

      {/* Modal entrada estoque */}
      {showEntrada && (
        <div
          style={{
            position: 'fixed',
            inset: 0,
            background: 'rgba(0,0,0,0.6)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 999,
          }}>
          <div
            style={{
              background: 'var(--card)',
              borderRadius: 12,
              border: '1px solid var(--border)',
              padding: 28,
              width: 380,
            }}>
            <h3
              className="font-display"
              style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', marginBottom: 20 }}>
              Entrada de Estoque
            </h3>
            <div style={{ marginBottom: 16 }}>
              <label
                style={{ color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 6 }}>
                Quantidade
              </label>
              <input
                type="number"
                min={1}
                value={qtdEntrada}
                onChange={e => setQtdEntrada(Math.max(1, parseInt(e.target.value) || 1))}
                style={inputStyle}
              />
            </div>
            <div style={{ marginBottom: 24 }}>
              <label
                style={{ color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 6 }}>
                Motivo
              </label>
              <input
                value={motivoEntrada}
                onChange={e => setMotivoEntrada(e.target.value)}
                placeholder="Compra de fornecedor"
                style={inputStyle}
              />
            </div>
            <div style={{ display: 'flex', gap: 12 }}>
              <button
                onClick={() => setShowEntrada(null)}
                style={{
                  flex: 1,
                  padding: 10,
                  borderRadius: 8,
                  background: 'transparent',
                  border: '1px solid var(--border)',
                  color: 'var(--muted)',
                  cursor: 'pointer',
                }}>
                Cancelar
              </button>
              <button
                onClick={() => registrarEntrada(showEntrada)}
                className="font-display"
                style={{
                  flex: 1,
                  padding: 10,
                  borderRadius: 8,
                  background: 'var(--accent)',
                  color: '#000',
                  border: 'none',
                  fontWeight: 800,
                  cursor: 'pointer',
                }}>
                Registrar
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
