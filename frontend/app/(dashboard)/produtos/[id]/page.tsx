'use client'
import { useEffect, useState, useCallback } from 'react'
import { useParams, useRouter } from 'next/navigation'
import { ProdutoForm } from '@/components/forms/ProdutoForm'
import { StatusPill } from '@/components/ui/StatusPill'
import { StockBar } from '@/components/ui/StockBar'
import { formatarMoeda, formatarDataHora } from '@/lib/formatters'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

interface Produto {
  id: string
  nome: string
  sku: string
  codigo_barras: string | null
  categoria: string
  unidade: string
  qty_atual: number
  qty_minima: number
  preco_custo: number | null
  preco_venda: number | null
  status_estoque: string
  ativo: boolean
}

interface Movimentacao {
  id: string
  tipo: string
  quantidade: number
  motivo: string | null
  criado_em: string
}

export default function ProdutoDetailPage() {
  const { id } = useParams<{ id: string }>()
  const router = useRouter()
  const [produto, setProduto] = useState<Produto | null>(null)
  const [movimentacoes, setMovimentacoes] = useState<Movimentacao[]>([])
  const [loading, setLoading] = useState(true)

  const fetchProduto = useCallback(() => {
    Promise.all([
      api.get(`/produtos/${id}`),
      api.get(`/produtos/${id}/estoque/historico`),
    ]).then(([p, m]) => {
      setProduto(p.data.data)
      setMovimentacoes(m.data ?? [])
    }).catch(() => {})
    .finally(() => setLoading(false))
  }, [id])

  useEffect(() => { fetchProduto() }, [fetchProduto])

  async function desativarProduto() {
    if (!confirm('Deseja desativar este produto? Ele não aparecerá mais nas listagens.')) return
    try {
      await api.put(`/produtos/${id}`, { ativo: false })
      toast('Produto desativado.', 'success')
      router.push('/produtos')
    } catch {
      toast('Erro ao desativar produto.', 'danger')
    }
  }

  if (loading) return <p style={{ color: 'var(--muted)' }}>Carregando...</p>
  if (!produto) return <p style={{ color: 'var(--danger)' }}>Produto não encontrado.</p>

  return (
    <div style={{ maxWidth: 900, margin: '0 auto' }}>
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 24, flexWrap: 'wrap' }}>
        <button onClick={() => router.back()}
          style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>
          ← Voltar
        </button>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0, flex: 1 }}>
          {produto.nome}
        </h1>
        <StatusPill status={produto.status_estoque} />
        <button onClick={desativarProduto}
          style={{ padding: '6px 14px', background: 'transparent', border: '1px solid var(--danger)', color: 'var(--danger)', borderRadius: 8, cursor: 'pointer', fontSize: 13 }}>
          Desativar
        </button>
      </div>

      {/* Info rápida */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(5, 1fr)', gap: 12, marginBottom: 24 }}>
        {[
          { label: 'SKU', value: produto.sku, mono: true },
          { label: 'Código de barras (EAN)', value: produto.codigo_barras ?? '-', mono: true },
          { label: 'Estoque atual', value: produto.qty_atual, mono: true },
          { label: 'Preço custo', value: produto.preco_custo ? formatarMoeda(produto.preco_custo) : '-', mono: true },
          { label: 'Preço venda', value: produto.preco_venda ? formatarMoeda(produto.preco_venda) : '-', mono: true },
        ].map(({ label, value, mono }) => (
          <div key={label} style={{ background: 'var(--card)', borderRadius: 10, border: '1px solid var(--border)', padding: 16 }}>
            <p style={{ color: 'var(--muted)', fontSize: 12, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', margin: '0 0 6px' }}>{label}</p>
            <p className={mono ? 'font-mono' : ''} style={{ color: 'var(--text)', fontSize: 20, fontWeight: 700, margin: 0 }}>{value}</p>
          </div>
        ))}
      </div>

      {/* Barra de estoque */}
      <div style={{ background: 'var(--card)', borderRadius: 10, border: '1px solid var(--border)', padding: 16, marginBottom: 24 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8 }}>
          <span style={{ color: 'var(--muted)', fontSize: 13 }}>Nível de estoque</span>
          <span style={{ color: 'var(--muted)', fontSize: 13 }}>Mínimo: {produto.qty_minima} {produto.unidade}</span>
        </div>
        <StockBar qtyAtual={produto.qty_atual} qtyMinima={produto.qty_minima} status={produto.status_estoque} />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24 }}>
        {/* Formulário de edição */}
        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
          <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 16 }}>
            Editar produto
          </h3>
          <ProdutoForm
            initialData={{
              ...produto,
              codigo_barras: produto.codigo_barras ?? undefined,
              preco_custo: produto.preco_custo ?? undefined,
              preco_venda: produto.preco_venda ?? undefined,
            }}
            onSuccess={fetchProduto}
          />
        </div>

        {/* Histórico de movimentações */}
        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
          <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 16 }}>
            Movimentações recentes
          </h3>
          {movimentacoes.length === 0 ? (
            <p style={{ color: 'var(--muted)', fontSize: 14 }}>Nenhuma movimentação registrada.</p>
          ) : (
            movimentacoes.slice(0, 15).map(m => (
              <div key={m.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 0', borderBottom: '1px solid var(--border)' }}>
                <div>
                  <p style={{ margin: 0, fontSize: 13, color: 'var(--text)' }}>{m.motivo ?? (m.tipo === 'ENTRADA' ? 'Entrada manual' : 'Saída')}</p>
                  <p style={{ margin: 0, fontSize: 12, color: 'var(--muted)' }}>{formatarDataHora(m.criado_em)}</p>
                </div>
                <span className="font-mono" style={{
                  fontWeight: 700, fontSize: 14,
                  color: m.tipo === 'ENTRADA' ? 'var(--success)' : 'var(--danger)',
                }}>
                  {m.tipo === 'ENTRADA' ? '+' : '-'}{m.quantidade}
                </span>
              </div>
            ))
          )}
        </div>
      </div>
    </div>
  )
}
