'use client'

import { useEffect, useState } from 'react'
import Link from 'next/link'
import { toast } from '@/hooks/useToast'
import api from '@/lib/api'

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

export default function PendenciasFiscaisPage() {
  const [produtos, setProdutos] = useState<ProdutoPendente[]>([])
  const [divergencias, setDivergencias] = useState<Divergencia[]>([])
  const [carregando, setCarregando] = useState(true)
  const [resolvendo, setResolvendo] = useState<string | null>(null)
  const [erroCarregamento, setErroCarregamento] = useState<string | null>(null)

  async function carregar() {
    setCarregando(true)
    setErroCarregamento(null)
    try {
      const { data } = await api.get('/produtos/pendencias-fiscais')
      setProdutos(data.data ?? [])
      setDivergencias(data.divergencias ?? [])
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      const mensagem = e.response?.data?.message ?? 'Erro ao carregar pendências fiscais.'
      setErroCarregamento(mensagem)
      toast(mensagem, 'danger')
    } finally {
      setCarregando(false)
    }
  }

  useEffect(() => { carregar() }, [])

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

  function situacao(p: ProdutoPendente): { texto: string; cor: string } {
    if (!p.ncm) return { texto: 'Sem NCM', cor: 'var(--danger)' }
    if (p.fiscal_fonte === 'PADRAO') return { texto: 'Padrão da categoria', cor: 'var(--accent)' }
    return { texto: 'Não revisado', cor: 'var(--accent)' }
  }

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

      <h2 style={{ fontFamily: 'Barlow Condensed', fontWeight: 700, fontSize: 18, marginBottom: 12 }}>
        Produtos pendentes ({produtos.length})
      </h2>

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
                </tr>
              )
            })}
          </tbody>
        </table>
      )}
    </div>
  )
}
