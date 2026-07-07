'use client'
import { useState, useEffect, useCallback } from 'react'
import { useRouter } from 'next/navigation'
import { DataTable, Column } from '@/components/ui/DataTable'
import { formatarMoeda } from '@/lib/formatters'
import api from '@/lib/api'

interface NotaEntradaListItem {
  id: string
  numero_nf: string | null
  serie: string | null
  fornecedor_nome: string | null
  fornecedor_cnpj: string | null
  valor_total: number
  data_emissao: string | null
  criado_em: string | null
}

interface NotaEntradaItem {
  id: string
  produto_id: string | null
  descricao_xml: string
  codigo_barras_xml: string | null
  quantidade: number
  valor_unitario: number
  produto_criado: boolean
}

interface NotaEntradaDetail extends NotaEntradaListItem {
  chave_acesso: string | null
  itens: NotaEntradaItem[]
}

interface PaginatedResponse {
  data: NotaEntradaListItem[]
  meta: { current_page: number; last_page: number; total: number }
}

export default function HistoricoEntradaNfPage() {
  const router = useRouter()
  const [notas, setNotas] = useState<NotaEntradaListItem[]>([])
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [detalhe, setDetalhe] = useState<NotaEntradaDetail | null>(null)
  const [carregandoDetalhe, setCarregandoDetalhe] = useState(false)

  const fetchNotas = useCallback(() => {
    setLoading(true)
    api.get<PaginatedResponse>('/entradas-nf', { params: { page } })
      .then(res => {
        setNotas(res.data.data ?? [])
        setLastPage(res.data.meta?.last_page ?? 1)
      })
      .catch(() => setNotas([]))
      .finally(() => setLoading(false))
  }, [page])

  useEffect(() => { fetchNotas() }, [fetchNotas])

  async function abrirDetalhe(nota: NotaEntradaListItem) {
    setCarregandoDetalhe(true)
    try {
      const res = await api.get<NotaEntradaDetail>(`/entradas-nf/${nota.id}`)
      setDetalhe(res.data)
    } catch {
      setDetalhe(null)
    } finally {
      setCarregandoDetalhe(false)
    }
  }

  const columns: Column<NotaEntradaListItem>[] = [
    {
      key: 'data_emissao', label: 'Emissão',
      render: r => <span className="font-mono" style={{ color: 'var(--text)' }}>{r.data_emissao ?? '-'}</span>,
    },
    {
      key: 'fornecedor_nome', label: 'Fornecedor',
      render: r => (
        <div>
          <p style={{ margin: 0, color: 'var(--text)' }}>{r.fornecedor_nome ?? '-'}</p>
          {r.fornecedor_cnpj && <p className="font-mono" style={{ margin: 0, color: 'var(--muted)', fontSize: 12 }}>{r.fornecedor_cnpj}</p>}
        </div>
      ),
    },
    {
      key: 'numero_nf', label: 'Nº NF',
      render: r => (
        <span className="font-mono" style={{ color: 'var(--accent)' }}>
          {r.numero_nf ? `#${r.numero_nf}${r.serie ? `/${r.serie}` : ''}` : '-'}
        </span>
      ),
    },
    {
      key: 'valor_total', label: 'Valor total',
      render: r => <span className="font-mono" style={{ color: 'var(--text)' }}>{formatarMoeda(r.valor_total)}</span>,
    },
  ]

  return (
    <div style={{ maxWidth: 1100, margin: '0 auto' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 24 }}>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
          Histórico de Entradas de NF
        </h1>
        <button onClick={() => router.push('/produtos/entrada-nf')}
          style={{ padding: '8px 16px', borderRadius: 8, background: 'rgba(245,166,35,0.12)', border: '1px solid var(--accent)', color: 'var(--accent)', cursor: 'pointer', fontSize: 14, whiteSpace: 'nowrap' }}>
          + Lançar NF
        </button>
      </div>

      <DataTable
        columns={columns}
        data={notas}
        loading={loading}
        onRowClick={abrirDetalhe}
        emptyMessage="Nenhuma entrada de NF lançada ainda."
      />

      {lastPage > 1 && (
        <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 16, marginTop: 16 }}>
          <button onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page <= 1}
            style={{ padding: '6px 14px', borderRadius: 6, background: 'none', border: '1px solid var(--border)', color: page <= 1 ? 'var(--muted)' : 'var(--text)', cursor: page <= 1 ? 'not-allowed' : 'pointer', fontSize: 13 }}>
            ← Anterior
          </button>
          <span style={{ color: 'var(--muted)', fontSize: 13 }}>Página {page} de {lastPage}</span>
          <button onClick={() => setPage(p => Math.min(lastPage, p + 1))} disabled={page >= lastPage}
            style={{ padding: '6px 14px', borderRadius: 6, background: 'none', border: '1px solid var(--border)', color: page >= lastPage ? 'var(--muted)' : 'var(--text)', cursor: page >= lastPage ? 'not-allowed' : 'pointer', fontSize: 13 }}>
            Próxima →
          </button>
        </div>
      )}

      {/* Modal de detalhe */}
      {(detalhe || carregandoDetalhe) && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.6)', zIndex: 999, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}
          onClick={() => setDetalhe(null)}>
          <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 28, width: 640, maxWidth: '100%', maxHeight: '85vh', overflowY: 'auto' }}
            onClick={e => e.stopPropagation()}>
            {carregandoDetalhe ? (
              <p style={{ color: 'var(--muted)' }}>Carregando...</p>
            ) : detalhe && (
              <>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 20 }}>
                  <h3 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
                    NF {detalhe.numero_nf ? `#${detalhe.numero_nf}${detalhe.serie ? `/${detalhe.serie}` : ''}` : ''}
                  </h3>
                  <button onClick={() => setDetalhe(null)} aria-label="Fechar"
                    style={{ background: 'none', border: 'none', color: 'var(--muted)', fontSize: 22, cursor: 'pointer', lineHeight: 1, padding: 0 }}>×</button>
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 20 }}>
                  <div>
                    <p style={{ color: 'var(--muted)', fontSize: 12, textTransform: 'uppercase', letterSpacing: '0.05em', margin: '0 0 4px' }}>Fornecedor</p>
                    <p style={{ color: 'var(--text)', fontSize: 14, margin: 0 }}>{detalhe.fornecedor_nome ?? '-'}</p>
                    {detalhe.fornecedor_cnpj && <p className="font-mono" style={{ color: 'var(--muted)', fontSize: 12, margin: '2px 0 0' }}>{detalhe.fornecedor_cnpj}</p>}
                  </div>
                  <div>
                    <p style={{ color: 'var(--muted)', fontSize: 12, textTransform: 'uppercase', letterSpacing: '0.05em', margin: '0 0 4px' }}>Emissão</p>
                    <p style={{ color: 'var(--text)', fontSize: 14, margin: 0 }}>{detalhe.data_emissao ?? '-'}</p>
                  </div>
                  <div>
                    <p style={{ color: 'var(--muted)', fontSize: 12, textTransform: 'uppercase', letterSpacing: '0.05em', margin: '0 0 4px' }}>Valor total</p>
                    <p className="font-mono" style={{ color: 'var(--text)', fontSize: 14, margin: 0 }}>{formatarMoeda(detalhe.valor_total)}</p>
                  </div>
                  <div style={{ gridColumn: '1 / -1' }}>
                    <p style={{ color: 'var(--muted)', fontSize: 12, textTransform: 'uppercase', letterSpacing: '0.05em', margin: '0 0 4px' }}>Chave de acesso</p>
                    <p className="font-mono" style={{ color: 'var(--text)', fontSize: 12, margin: 0, wordBreak: 'break-all' }}>{detalhe.chave_acesso ?? '-'}</p>
                  </div>
                </div>

                <p style={{ color: 'var(--muted)', fontSize: 12, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 8 }}>
                  Itens ({detalhe.itens.length})
                </p>
                <div style={{ border: '1px solid var(--border)', borderRadius: 8, overflow: 'hidden' }}>
                  <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                    <thead>
                      <tr>
                        {['Descrição', 'Qtd', 'Valor unit.', 'Total', ''].map(h => (
                          <th key={h} style={{ padding: '8px 12px', textAlign: 'left', fontSize: 11, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', background: 'var(--bg)', borderBottom: '1px solid var(--border)' }}>{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {detalhe.itens.map(item => (
                        <tr key={item.id} style={{ borderBottom: '1px solid var(--border)' }}>
                          <td style={{ padding: '8px 12px', fontSize: 13, color: 'var(--text)' }}>{item.descricao_xml}</td>
                          <td style={{ padding: '8px 12px', fontSize: 13, color: 'var(--text)' }} className="font-mono">{item.quantidade}</td>
                          <td style={{ padding: '8px 12px', fontSize: 13, color: 'var(--text)' }} className="font-mono">{formatarMoeda(item.valor_unitario)}</td>
                          <td style={{ padding: '8px 12px', fontSize: 13, color: 'var(--text)' }} className="font-mono">{formatarMoeda(item.quantidade * item.valor_unitario)}</td>
                          <td style={{ padding: '8px 12px' }}>
                            {item.produto_criado && (
                              <span style={{ padding: '2px 8px', borderRadius: 999, fontSize: 11, fontWeight: 700, background: 'rgba(30,136,229,.15)', color: 'var(--info)' }}>Novo</span>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
