'use client'

import { useCallback, useEffect, useState } from 'react'
import Link from 'next/link'
import { toast } from '@/hooks/useToast'
import api from '@/lib/api'

const CATEGORIAS = ['Filtros', 'Óleo/Fluidos', 'Freios', 'Suspensão', 'Elétrica', 'Motor', 'Outros']

interface ProdutoPendente {
  id: string
  nome: string
  sku: string
  categoria: string
  ncm: string | null
  fiscal_fonte: string | null
  fiscal_revisado_em: string | null
}

interface Divergencia {
  id: string
  produto_id: string
  produto_nome: string | null
  campo: string
  valor_atual: string | null
  valor_xml: string | null
  criado_em: string | null
}

interface Meta {
  total: number
  per_page: number
  current_page: number
}

const metaInicial: Meta = { total: 0, per_page: 20, current_page: 1 }

export default function PendenciasFiscaisPage() {
  const [produtos, setProdutos] = useState<ProdutoPendente[]>([])
  const [divergencias, setDivergencias] = useState<Divergencia[]>([])
  const [meta, setMeta] = useState<Meta>(metaInicial)
  const [carregando, setCarregando] = useState(true)
  const [resolvendo, setResolvendo] = useState<string | null>(null)
  const [marcando, setMarcando] = useState<string | null>(null)
  const [erroCarregamento, setErroCarregamento] = useState<string | null>(null)
  const [page, setPage] = useState(1)
  const [categoriaFiltro, setCategoriaFiltro] = useState('')

  const carregar = useCallback(async () => {
    setCarregando(true)
    setErroCarregamento(null)
    try {
      const { data } = await api.get('/produtos/pendencias-fiscais', {
        params: {
          page,
          per_page: metaInicial.per_page,
          categoria: categoriaFiltro || undefined,
        },
      })
      setProdutos(data.data ?? [])
      setDivergencias(data.divergencias ?? [])
      setMeta(data.meta ?? metaInicial)
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      const mensagem = e.response?.data?.message ?? 'Erro ao carregar pendências fiscais.'
      setErroCarregamento(mensagem)
      toast(mensagem, 'danger')
    } finally {
      setCarregando(false)
    }
  }, [page, categoriaFiltro])

  useEffect(() => { carregar() }, [carregar])

  function selecionarCategoria(categoria: string) {
    setCategoriaFiltro(categoria)
    setPage(1)
  }

  async function resolver(id: string, resolucao: 'MANTEVE' | 'ACEITOU_XML') {
    setResolvendo(id)
    try {
      await api.post(`/produtos/divergencias/${id}/resolver`, { resolucao })
      toast('Divergência resolvida com sucesso!', 'success')
      await carregar()
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao resolver divergência.', 'danger')
    } finally {
      setResolvendo(null)
    }
  }

  async function marcarRevisado(id: string) {
    setMarcando(id)
    try {
      await api.post(`/produtos/${id}/marcar-revisado`)
      toast('Produto marcado como revisado!', 'success')
      await carregar()
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao marcar produto como revisado.', 'danger')
    } finally {
      setMarcando(null)
    }
  }

  function situacao(p: ProdutoPendente): { texto: string; cor: string } {
    if (!p.ncm) return { texto: 'Sem NCM', cor: 'var(--danger)' }
    if (p.fiscal_fonte === 'PADRAO') return { texto: 'Padrão da categoria', cor: 'var(--accent)' }
    return { texto: 'Não revisado', cor: 'var(--accent)' }
  }

  const lastPage = Math.max(1, Math.ceil(meta.total / meta.per_page))

  if (carregando) {
    return <div style={{ padding: 24, color: 'var(--muted)' }}>Carregando pendências…</div>
  }

  if (erroCarregamento) {
    return (
      <div style={{ padding: 24 }}>
        <div style={{ background: 'rgba(229,57,53,.06)', border: '1px solid var(--danger)', borderRadius: 8, padding: 16, marginBottom: 20 }}>
          <p style={{ color: 'var(--danger)', margin: 0, fontSize: 14 }}>
            ⚠ {erroCarregamento}
          </p>
          <button
            onClick={carregar}
            style={{
              marginTop: 12,
              padding: '6px 14px',
              borderRadius: 6,
              background: 'var(--danger)',
              color: '#fff',
              border: 'none',
              cursor: 'pointer',
              fontSize: 13,
              fontWeight: 600,
            }}>
            Tentar novamente
          </button>
        </div>
      </div>
    )
  }

  return (
    <div style={{ padding: 24 }}>
      <p style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 20 }}>
        Produtos sem dado fiscal completo não podem ser incluídos em NF-e. Ao importar a nota do
        fornecedor por XML, esses campos são preenchidos automaticamente.
      </p>

      {divergencias.length > 0 && (
        <section style={{ marginBottom: 32 }}>
          <h2 style={{ fontFamily: 'Barlow Condensed', fontWeight: 700, fontSize: 18, marginBottom: 12 }}>
            Divergências com o fornecedor ({divergencias.length})
          </h2>
          <table style={{ width: '100%', borderCollapse: 'collapse', background: 'var(--card)' }}>
            <thead>
              <tr style={{ borderBottom: '1px solid var(--border)', textAlign: 'left' }}>
                <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Produto</th>
                <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Campo</th>
                <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Cadastro</th>
                <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Fornecedor</th>
                <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Ação</th>
              </tr>
            </thead>
            <tbody>
              {divergencias.map((d) => (
                <tr key={d.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td style={{ padding: 10 }}>{d.produto_nome ?? '—'}</td>
                  <td style={{ padding: 10, fontFamily: 'JetBrains Mono', fontSize: 12 }}>{d.campo}</td>
                  <td style={{ padding: 10, fontFamily: 'JetBrains Mono', fontSize: 12 }}>{d.valor_atual ?? '—'}</td>
                  <td style={{ padding: 10, fontFamily: 'JetBrains Mono', fontSize: 12, color: 'var(--accent)' }}>
                    {d.valor_xml ?? '—'}
                  </td>
                  <td style={{ padding: 10, display: 'flex', gap: 8 }}>
                    <button
                      onClick={() => resolver(d.id, 'MANTEVE')}
                      disabled={resolvendo === d.id}
                      style={{ padding: '4px 10px', fontSize: 12, background: 'transparent', color: 'var(--text)', border: '1px solid var(--border)', borderRadius: 4, cursor: 'pointer' }}
                    >
                      Manter cadastro
                    </button>
                    <button
                      onClick={() => resolver(d.id, 'ACEITOU_XML')}
                      disabled={resolvendo === d.id}
                      style={{ padding: '4px 10px', fontSize: 12, background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 4, cursor: 'pointer' }}
                    >
                      Aceitar fornecedor
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12, gap: 16, flexWrap: 'wrap' }}>
        <h2 style={{ fontFamily: 'Barlow Condensed', fontWeight: 700, fontSize: 18, margin: 0 }}>
          Produtos pendentes ({meta.total})
        </h2>
        <div>
          <label style={{ color: 'var(--muted)', fontSize: 12, marginRight: 8 }}>Categoria</label>
          <select
            value={categoriaFiltro}
            onChange={(e) => selecionarCategoria(e.target.value)}
            style={{
              padding: '6px 10px', borderRadius: 6, background: 'var(--bg)',
              border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13,
            }}
          >
            <option value="">Todas</option>
            {CATEGORIAS.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
      </div>

      {produtos.length === 0 ? (
        <p style={{ color: 'var(--muted)' }}>Nenhuma pendência fiscal. Todos os produtos ativos estão prontos para NF-e.</p>
      ) : (
        <table style={{ width: '100%', borderCollapse: 'collapse', background: 'var(--card)' }}>
          <thead>
            <tr style={{ borderBottom: '1px solid var(--border)', textAlign: 'left' }}>
              <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Produto</th>
              <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>SKU</th>
              <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Categoria</th>
              <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>NCM</th>
              <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Situação</th>
              <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Ação</th>
            </tr>
          </thead>
          <tbody>
            {produtos.map((p) => {
              const s = situacao(p)
              return (
                <tr
                  key={p.id}
                  style={{
                    borderBottom: '1px solid var(--border)',
                    background: !p.ncm ? 'rgba(229,57,53,.06)' : undefined,
                  }}
                >
                  <td style={{ padding: 10 }}>
                    <Link href={`/produtos/${p.id}`} style={{ color: 'var(--text)' }}>{p.nome}</Link>
                  </td>
                  <td style={{ padding: 10, fontFamily: 'JetBrains Mono', fontSize: 12 }}>{p.sku}</td>
                  <td style={{ padding: 10 }}>{p.categoria}</td>
                  <td style={{ padding: 10, fontFamily: 'JetBrains Mono', fontSize: 12 }}>{p.ncm ?? '—'}</td>
                  <td style={{ padding: 10 }}>
                    <span style={{ padding: '2px 8px', borderRadius: 10, fontSize: 11, border: `1px solid ${s.cor}`, color: s.cor }}>
                      {s.texto}
                    </span>
                  </td>
                  <td style={{ padding: 10 }}>
                    {p.ncm && (
                      <button
                        onClick={() => marcarRevisado(p.id)}
                        disabled={marcando === p.id}
                        style={{
                          padding: '4px 10px', fontSize: 12,
                          background: marcando === p.id ? 'var(--muted)' : 'var(--accent)',
                          color: '#000', border: 'none', borderRadius: 4,
                          cursor: marcando === p.id ? 'not-allowed' : 'pointer',
                        }}
                      >
                        {marcando === p.id ? 'Marcando...' : 'Marcar como revisado'}
                      </button>
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}

      {lastPage > 1 && (
        <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 16, marginTop: 16 }}>
          <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page <= 1}
            style={{ padding: '6px 14px', borderRadius: 6, background: 'none', border: '1px solid var(--border)', color: page <= 1 ? 'var(--muted)' : 'var(--text)', cursor: page <= 1 ? 'not-allowed' : 'pointer', fontSize: 13 }}>
            ← Anterior
          </button>
          <span style={{ color: 'var(--muted)', fontSize: 13 }}>Página {meta.current_page} de {lastPage}</span>
          <button onClick={() => setPage((p) => Math.min(lastPage, p + 1))} disabled={page >= lastPage}
            style={{ padding: '6px 14px', borderRadius: 6, background: 'none', border: '1px solid var(--border)', color: page >= lastPage ? 'var(--muted)' : 'var(--text)', cursor: page >= lastPage ? 'not-allowed' : 'pointer', fontSize: 13 }}>
            Próxima →
          </button>
        </div>
      )}
    </div>
  )
}
