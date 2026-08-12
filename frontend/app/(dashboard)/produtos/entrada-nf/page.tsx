'use client'
import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { formatarMoeda } from '@/lib/formatters'
import { toast } from '@/hooks/useToast'
import api from '@/lib/api'

interface ItemPreview {
  codigo_barras: string | null
  descricao_xml: string
  quantidade: number
  valor_unitario: number
  matched: boolean
  produto_id: string | null
  nome: string
  categoria: string
  unidade: string
  qty_atual: number
  preco_venda: number
  qty_minima: number
  ncm: string | null
  cfop: string | null
  cest: string | null
  origem: number | null
  cst_csosn: string | null
  tributacao_icms: string | null
  unidade_xml: string | null
  fiscal_pendente: boolean
  sera_atualizado: boolean
}

interface NotaPreview {
  numero_nf: string | null
  serie: string | null
  chave_acesso: string | null
  data_emissao: string | null
  fornecedor_nome: string | null
  fornecedor_cnpj: string | null
  valor_total: number
  ja_lancada: boolean
  atualizacao_fiscal_disponivel: boolean
  itens: ItemPreview[]
  xml_original: string
}

const CATEGORIAS = ['Filtros', 'Óleo/Fluidos', 'Freios', 'Suspensão', 'Elétrica', 'Motor', 'Outros']

const inputStyle: React.CSSProperties = {
  width: '100%', padding: '6px 8px', borderRadius: 6,
  background: 'var(--bg)', border: '1px solid var(--border)',
  color: 'var(--text)', fontSize: 13, outline: 'none', boxSizing: 'border-box',
}

export default function EntradaNfPage() {
  const router = useRouter()
  const [uploading, setUploading] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [preview, setPreview] = useState<NotaPreview | null>(null)
  const [itens, setItens] = useState<ItemPreview[]>([])

  async function handleUpload(file: File) {
    setUploading(true)
    try {
      const formData = new FormData()
      formData.append('arquivo', file)
      const res = await api.post<NotaPreview>('/entradas-nf/parse', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      setPreview(res.data)
      setItens(res.data.itens)
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao ler o XML da nota.', 'danger')
    } finally {
      setUploading(false)
    }
  }

  function updateItem<K extends keyof ItemPreview>(idx: number, field: K, value: ItemPreview[K]) {
    setItens(prev => prev.map((it, i) => i === idx ? { ...it, [field]: value } : it))
  }

  function removeItem(idx: number) {
    setItens(prev => prev.filter((_, i) => i !== idx))
  }

  async function handleConfirmar() {
    if (!preview) return
    setConfirming(true)
    try {
      await api.post('/entradas-nf', {
        numero_nf: preview.numero_nf,
        serie: preview.serie,
        chave_acesso: preview.chave_acesso,
        fornecedor_nome: preview.fornecedor_nome,
        fornecedor_cnpj: preview.fornecedor_cnpj,
        data_emissao: preview.data_emissao,
        xml_original: preview.xml_original,
        itens: itens.map(i => ({
          produto_id: i.matched ? i.produto_id : null,
          codigo_barras: i.codigo_barras,
          nome: i.nome,
          categoria: i.categoria,
          unidade: i.unidade,
          quantidade: i.quantidade,
          valor_unitario: i.valor_unitario,
          preco_venda: i.preco_venda,
          qty_minima: i.qty_minima,
          ncm: i.ncm,
          cfop: i.cfop,
          cest: i.cest,
          origem: i.origem,
          cst_csosn: i.cst_csosn,
          tributacao_icms: i.tributacao_icms,
          unidade_xml: i.unidade_xml,
        })),
      })
      toast('Entrada de estoque registrada com sucesso!', 'success')
      router.push('/produtos')
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao confirmar a entrada.', 'danger')
    } finally {
      setConfirming(false)
    }
  }

  async function handleAtualizarFiscal() {
    if (!preview) return
    setConfirming(true)
    try {
      const res = await api.post<{ produtos_atualizados: number }>('/entradas-nf/atualizar-fiscal', {
        chave_acesso: preview.chave_acesso,
        itens: itens
          .filter(i => i.matched && i.produto_id)
          .map(i => ({
            produto_id: i.produto_id,
            ncm: i.ncm,
            cest: i.cest,
            origem: i.origem,
            tributacao_icms: i.tributacao_icms,
          })),
      })
      toast(`${res.data.produtos_atualizados} produto(s) atualizado(s).`, 'success')
      router.push('/produtos')
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao atualizar dados fiscais.', 'danger')
    } finally {
      setConfirming(false)
    }
  }

  const totalCalculado = itens.reduce((acc, i) => acc + i.quantidade * i.valor_unitario, 0)
  const modoAtualizacaoFiscal = !!preview && preview.ja_lancada && preview.atualizacao_fiscal_disponivel
  const podeConfirmar = !!preview && !confirming && itens.length > 0
    && (modoAtualizacaoFiscal || !preview.ja_lancada)

  return (
    <div style={{ maxWidth: 1100, margin: '0 auto' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 24 }}>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
          Lançar Entrada de Nota Fiscal
        </h1>
        <button onClick={() => router.push('/produtos/entrada-nf/historico')}
          style={{ padding: '8px 16px', borderRadius: 8, background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer', fontSize: 14, whiteSpace: 'nowrap' }}>
          📜 Ver histórico
        </button>
      </div>

      {!preview && (
        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 32, textAlign: 'center' }}>
          <p style={{ color: 'var(--muted)', marginBottom: 16 }}>
            Selecione o arquivo XML da NF-e enviado pelo fornecedor.
          </p>
          <input
            type="file"
            accept=".xml"
            disabled={uploading}
            onChange={e => { const f = e.target.files?.[0]; if (f) handleUpload(f) }}
            style={{ color: 'var(--text)' }}
          />
          {uploading && <p style={{ color: 'var(--muted)', marginTop: 12 }}>Lendo XML...</p>}
        </div>
      )}

      {preview && (
        <>
          <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 20, marginBottom: 20 }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 16 }}>
              <div>
                <p style={{ color: 'var(--muted)', fontSize: 12, margin: '0 0 4px' }}>NF-e</p>
                <p style={{ color: 'var(--text)', fontWeight: 600, margin: 0 }}>{preview.numero_nf ?? '-'} / série {preview.serie ?? '-'}</p>
              </div>
              <div>
                <p style={{ color: 'var(--muted)', fontSize: 12, margin: '0 0 4px' }}>Fornecedor</p>
                <p style={{ color: 'var(--text)', fontWeight: 600, margin: 0 }}>{preview.fornecedor_nome ?? '-'}</p>
              </div>
              <div>
                <p style={{ color: 'var(--muted)', fontSize: 12, margin: '0 0 4px' }}>Valor da nota (XML)</p>
                <p className="font-mono" style={{ color: 'var(--text)', fontWeight: 600, margin: 0 }}>{formatarMoeda(preview.valor_total)}</p>
              </div>
              <div>
                <p style={{ color: 'var(--muted)', fontSize: 12, margin: '0 0 4px' }}>Soma dos itens (revisado)</p>
                <p className="font-mono" style={{ color: Math.abs(totalCalculado - preview.valor_total) > 0.01 ? 'var(--accent)' : 'var(--text)', fontWeight: 600, margin: 0 }}>
                  {formatarMoeda(totalCalculado)}
                </p>
              </div>
            </div>
            {preview.ja_lancada && !modoAtualizacaoFiscal && (
              <p style={{ color: 'var(--danger)', fontSize: 13, marginTop: 16, marginBottom: 0 }}>
                Esta nota fiscal já foi lançada anteriormente. Não é possível confirmar de novo.
              </p>
            )}
            {modoAtualizacaoFiscal && (
              <p style={{ color: 'var(--accent)', fontSize: 13, marginTop: 16, marginBottom: 0 }}>
                Esta nota já foi lançada. {itens.filter(i => i.sera_atualizado).length} produto(s) têm dados
                fiscais pendentes que serão atualizados a partir deste XML. Nenhum estoque ou produto novo
                será alterado.
              </p>
            )}
          </div>

          <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', overflow: 'hidden', marginBottom: 20 }}>
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr>
                    {(modoAtualizacaoFiscal
                      ? ['Cód. barras', 'Descrição', 'Fiscal (XML)']
                      : ['Cód. barras', 'Descrição', 'Status', 'Categoria', 'Qtd', 'Custo', 'Venda', 'Fiscal', '']
                    ).map(h => (
                      <th key={h} style={{ padding: '10px 12px', textAlign: 'left', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', borderBottom: '1px solid var(--border)' }}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {itens.map((item, idx) => (
                    <tr key={idx}>
                      <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }} className="font-mono">
                        {item.codigo_barras ?? '-'}
                      </td>
                      <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>
                        <input style={inputStyle} value={item.nome} disabled={item.matched}
                          onChange={e => updateItem(idx, 'nome', e.target.value)} />
                      </td>
                      {!modoAtualizacaoFiscal && (
                        <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>
                          <span style={{
                            padding: '3px 8px', borderRadius: 6, fontSize: 11, fontWeight: 700,
                            background: item.matched ? 'rgba(67,160,71,0.15)' : 'rgba(245,166,35,0.15)',
                            color: item.matched ? 'var(--success)' : 'var(--accent)',
                          }}>
                            {item.matched ? 'Existente' : 'Novo'}
                          </span>
                        </td>
                      )}
                      {!modoAtualizacaoFiscal && (
                        <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>
                          {item.matched ? (
                            <span style={{ color: 'var(--muted)', fontSize: 13 }}>{item.categoria}</span>
                          ) : (
                            <select style={inputStyle} value={item.categoria} onChange={e => updateItem(idx, 'categoria', e.target.value)}>
                              {CATEGORIAS.map(c => <option key={c} value={c}>{c}</option>)}
                            </select>
                          )}
                        </td>
                      )}
                      {!modoAtualizacaoFiscal && (
                        <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)', width: 80 }}>
                          <input type="number" min={0.01} step="0.01" style={inputStyle} value={item.quantidade}
                            onChange={e => updateItem(idx, 'quantidade', Math.max(0.01, parseFloat(e.target.value) || 0))} />
                        </td>
                      )}
                      {!modoAtualizacaoFiscal && (
                        <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)', width: 100 }}>
                          <input type="number" min={0} step="0.01" style={inputStyle} value={item.valor_unitario}
                            onChange={e => updateItem(idx, 'valor_unitario', Math.max(0, parseFloat(e.target.value) || 0))} />
                        </td>
                      )}
                      {!modoAtualizacaoFiscal && (
                        <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)', width: 100 }}>
                          <input type="number" min={0} step="0.01" style={inputStyle} value={item.preco_venda} disabled={item.matched}
                            onChange={e => updateItem(idx, 'preco_venda', Math.max(0, parseFloat(e.target.value) || 0))} />
                        </td>
                      )}
                      <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)', fontSize: 12 }}>
                        {item.fiscal_pendente ? (
                          <span style={{ color: 'var(--accent)' }} title="Faltou NCM ou situação tributária no XML — o produto entrará com pendência fiscal">
                            Pendente
                          </span>
                        ) : (
                          <span style={{ fontFamily: 'JetBrains Mono', color: 'var(--muted)' }}>
                            {item.ncm}
                            {item.tributacao_icms === 'ST' && (
                              <span style={{ color: 'var(--accent)', marginLeft: 6 }}>ST</span>
                            )}
                          </span>
                        )}
                      </td>
                      {!modoAtualizacaoFiscal && (
                        <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>
                          <button type="button" onClick={() => removeItem(idx)}
                            style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 16 }}
                            title="Remover item">✕</button>
                        </td>
                      )}
                    </tr>
                  ))}
                  {itens.length === 0 && (
                    <tr><td colSpan={modoAtualizacaoFiscal ? 3 : 9} style={{ padding: 24, textAlign: 'center', color: 'var(--muted)' }}>Nenhum item na nota.</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 12 }}>
            <button type="button" onClick={() => { setPreview(null); setItens([]) }}
              style={{ padding: '10px 20px', borderRadius: 8, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer' }}>
              Cancelar
            </button>
            <button type="button" onClick={modoAtualizacaoFiscal ? handleAtualizarFiscal : handleConfirmar}
              disabled={!podeConfirmar} className="font-display"
              style={{
                padding: '10px 28px', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 16,
                background: podeConfirmar ? 'var(--accent)' : 'var(--muted)', color: '#000',
                cursor: podeConfirmar ? 'pointer' : 'not-allowed',
              }}>
              {confirming ? 'Confirmando...' : modoAtualizacaoFiscal ? 'Atualizar dados fiscais' : 'Confirmar Entrada'}
            </button>
          </div>
        </>
      )}
    </div>
  )
}
