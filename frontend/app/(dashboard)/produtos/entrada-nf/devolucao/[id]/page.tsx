'use client'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useParams, useRouter } from 'next/navigation'
import { formatarMoeda } from '@/lib/formatters'
import { toast } from '@/hooks/useToast'
import { useAuth } from '@/hooks/useAuth'
import { papelPermitido } from '@/lib/roleRules'
import api from '@/lib/api'

interface ItemDevolucao {
  id: string
  produto_id: string
  descricao: string
  unidade: string | null
  quantidade_original: number
  valor_unitario: number
  devolvida_nf: number
  devolvivel_nf: number
  devolvida_estoque: number
  devolvivel_estoque: number
  qty_atual: number | null
}

interface NotaDevolucao {
  id: string
  numero_nf: string | null
  serie: string | null
  chave_acesso: string | null
  fornecedor_nome: string | null
  fornecedor_cnpj: string | null
  data_emissao: string | null
}

interface Linha {
  marcado: boolean
  quantidade: string
}

type RespostaErro = { response?: { status?: number; data?: { message?: string } } }

function mensagemDe(e: unknown, padrao: string): string {
  return (e as RespostaErro)?.response?.data?.message ?? padrao
}

function numeroBr(n: number): string {
  return String(n).replace('.', ',')
}

export default function DevolucaoCompraPage() {
  const { id } = useParams<{ id: string }>()
  const router = useRouter()
  const { getUser } = useAuth()
  const [nota, setNota] = useState<NotaDevolucao | null>(null)
  const [itens, setItens] = useState<ItemDevolucao[]>([])
  const [linhas, setLinhas] = useState<Record<string, Linha>>({})
  const [carregando, setCarregando] = useState(true)
  const [observacoes, setObservacoes] = useState('')
  const [baixarEstoqueJunto, setBaixarEstoqueJunto] = useState(true)
  const [emitindo, setEmitindo] = useState(false)
  const [baixando, setBaixando] = useState(false)

  const carregar = useCallback(async () => {
    setCarregando(true)
    try {
      const r = await api.get<{ data: { nota: NotaDevolucao; itens: ItemDevolucao[] } }>(`/entradas-nf/${id}/devolucao`)
      setNota(r.data.data.nota)
      setItens(r.data.data.itens)
      // Quantidade já vem preenchida com tudo que ainda pode ser devolvido (a nota inteira, se nada foi devolvido antes).
      setLinhas(Object.fromEntries(r.data.data.itens.map(i => [i.id, {
        marcado: false,
        quantidade: numeroBr(i.devolvivel_nf),
      }])))
    } catch (e: unknown) {
      toast(mensagemDe(e, 'Erro ao carregar a nota de entrada.'), 'danger')
    } finally {
      setCarregando(false)
    }
  }, [id])

  useEffect(() => { carregar() }, [carregar])

  const quantidadeDe = (i: ItemDevolucao): number => {
    const n = parseFloat((linhas[i.id]?.quantidade ?? '').replace(',', '.'))
    return Number.isFinite(n) ? n : 0
  }

  const selecionados = useMemo(() => itens.filter(i => linhas[i.id]?.marcado), [itens, linhas])
  const totalDevolucao = useMemo(
    () => selecionados.reduce((s, i) => s + Math.round(quantidadeDe(i) * i.valor_unitario * 100) / 100, 0),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [selecionados, linhas],
  )

  function alterar(itemId: string, parcial: Partial<Linha>) {
    setLinhas(prev => ({ ...prev, [itemId]: { ...prev[itemId], ...parcial } }))
  }

  /** Mensagem do primeiro problema com a seleção para a ação (NF-e: fracionado permitido; estoque: unidades inteiras). */
  function validar(acao: 'nf' | 'estoque'): string | null {
    if (selecionados.length === 0) return 'Selecione ao menos um item.'
    for (const i of selecionados) {
      const q = quantidadeDe(i)
      const teto = acao === 'nf' ? i.devolvivel_nf : i.devolvivel_estoque
      if (q <= 0) return `Informe a quantidade de "${i.descricao}".`
      if (acao === 'estoque' && !Number.isInteger(q)) return `"${i.descricao}": o estoque é controlado em unidades inteiras.`
      if (q > teto) return `"${i.descricao}": máximo ${numeroBr(teto)} (o que a nota de compra permite, descontando o que já foi devolvido).`
      if (acao === 'estoque' && i.qty_atual !== null && q > i.qty_atual) return `"${i.descricao}": só há ${i.qty_atual} em estoque agora.`
    }
    return null
  }

  const pedido = () => selecionados.map(i => ({ item_id: i.id, quantidade: quantidadeDe(i) }))

  async function baixarEstoque(): Promise<boolean> {
    try {
      await api.post(`/entradas-nf/${id}/devolucao-estoque`, { itens: pedido().map(p => ({ ...p, quantidade: Math.trunc(p.quantidade) })) })
      return true
    } catch (e: unknown) {
      toast(mensagemDe(e, 'Erro ao retirar do estoque.'), 'danger')
      return false
    }
  }

  async function aoBaixarEstoque() {
    const erro = validar('estoque')
    if (erro) { toast(erro, 'danger'); return }
    setBaixando(true)
    try {
      if (await baixarEstoque()) {
        toast('Itens retirados do estoque.', 'success')
        await carregar()
      }
    } finally { setBaixando(false) }
  }

  async function aoEmitirNf() {
    const erro = validar('nf')
    if (erro) { toast(erro, 'danger'); return }
    const querBaixar = baixarEstoqueJunto && !validar('estoque')
    setEmitindo(true)
    try {
      const r = await api.post<{ data: { id: string } }>(`/entradas-nf/${id}/devolucao`, {
        itens: pedido(),
        ...(observacoes.trim() ? { observacoes: observacoes.trim() } : {}),
      })
      const notaId = r.data.data.id

      try {
        await api.post(`/notas-fiscais/${notaId}/emitir`)
        toast('NF-e de devolução enviada para emissão.', 'success')
      } catch (e: unknown) {
        const status = (e as RespostaErro)?.response?.status
        toast(
          status === 403
            ? 'Rascunho da NF-e criado, mas só ADMIN ou FINANCEIRO emitem. Peça a um deles para emitir em Notas Fiscais.'
            : mensagemDe(e, 'Rascunho criado, mas a emissão falhou. Tente novamente em Notas Fiscais.'),
          'danger',
        )
      }

      if (querBaixar && await baixarEstoque()) toast('Itens retirados do estoque.', 'success')

      const role = getUser()?.role
      if (role && papelPermitido('/fiscal/historico', role)) router.push('/fiscal/historico')
      else await carregar()
    } catch (e: unknown) {
      toast(mensagemDe(e, 'Erro ao criar a NF-e de devolução.'), 'danger')
    } finally { setEmitindo(false) }
  }

  const thStyle: React.CSSProperties = {
    padding: '8px 12px', textAlign: 'left', fontSize: 11, fontWeight: 700, color: 'var(--muted)',
    textTransform: 'uppercase', background: 'var(--bg)', borderBottom: '1px solid var(--border)',
  }
  const tdStyle: React.CSSProperties = { padding: '8px 12px', fontSize: 13, color: 'var(--text)', verticalAlign: 'middle' }

  if (carregando) return <p style={{ color: 'var(--muted)' }}>Carregando...</p>
  if (!nota) return <p style={{ color: 'var(--danger)' }}>Nota de entrada não encontrada.</p>

  const semChave = !nota.chave_acesso || nota.chave_acesso.replace(/\D/g, '').length !== 44

  return (
    <div style={{ maxWidth: 1000, margin: '0 auto' }}>
      <button onClick={() => router.push('/produtos/entrada-nf/historico')}
        style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 14, padding: 0, marginBottom: 12 }}>
        ← Voltar ao histórico de entradas
      </button>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: '0 0 4px' }}>
        Devolução de compra
      </h1>
      <p style={{ color: 'var(--muted)', fontSize: 14, margin: '0 0 20px' }}>
        NF {nota.numero_nf ? `#${nota.numero_nf}${nota.serie ? `/${nota.serie}` : ''}` : ''} — {nota.fornecedor_nome ?? 'Fornecedor'}
        {nota.fornecedor_cnpj ? ` (${nota.fornecedor_cnpj})` : ''}
      </p>

      {semChave && (
        <div style={{ background: 'rgba(229,57,53,0.1)', border: '1px solid var(--danger)', color: 'var(--danger)', borderRadius: 8, padding: '10px 14px', fontSize: 13, marginBottom: 16 }}>
          Esta nota de entrada não tem a chave de acesso (44 dígitos). Sem ela não dá para emitir a NF-e de devolução
          (ela precisa referenciar a nota de compra), mas ainda é possível retirar os itens do estoque.
        </div>
      )}

      <div style={{ border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden', background: 'var(--card)' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 760 }}>
            <thead>
              <tr>
                <th style={{ ...thStyle, width: 36 }} />
                <th style={thStyle}>Item</th>
                <th style={thStyle}>Qtd na nota</th>
                <th style={thStyle}>Já devolvida (NF)</th>
                <th style={thStyle}>Já retirada (estoque)</th>
                <th style={thStyle}>Qtd a devolver</th>
                <th style={thStyle}>Valor unit.</th>
                <th style={thStyle}>Total</th>
              </tr>
            </thead>
            <tbody>
              {itens.map(i => {
                const l = linhas[i.id]
                const esgotado = i.devolvivel_nf <= 0 && i.devolvivel_estoque <= 0
                return (
                  <tr key={i.id} style={{ borderBottom: '1px solid var(--border)', opacity: esgotado ? 0.5 : 1 }}>
                    <td style={tdStyle}>
                      <input type="checkbox" aria-label={`Selecionar ${i.descricao}`} checked={l?.marcado ?? false} disabled={esgotado}
                        onChange={e => alterar(i.id, { marcado: e.target.checked })} />
                    </td>
                    <td style={tdStyle}>{i.descricao}</td>
                    <td style={tdStyle} className="font-mono">{numeroBr(i.quantidade_original)} {i.unidade ?? ''}</td>
                    <td style={tdStyle} className="font-mono">{numeroBr(i.devolvida_nf)}</td>
                    <td style={tdStyle} className="font-mono">{i.devolvida_estoque}</td>
                    <td style={tdStyle}>
                      <label htmlFor={`qtd-${i.id}`} style={{ display: 'none' }}>Quantidade a devolver de {i.descricao}</label>
                      <input id={`qtd-${i.id}`} inputMode="decimal" value={l?.quantidade ?? ''} disabled={esgotado}
                        onChange={e => alterar(i.id, { quantidade: e.target.value, marcado: true })}
                        style={{ width: 90, padding: '6px 8px', borderRadius: 6, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13 }} />
                      <span style={{ color: 'var(--muted)', fontSize: 11, marginLeft: 6 }}>máx. {numeroBr(i.devolvivel_nf)}</span>
                    </td>
                    <td style={tdStyle} className="font-mono">{formatarMoeda(i.valor_unitario)}</td>
                    <td style={tdStyle} className="font-mono">{formatarMoeda(Math.round(quantidadeDe(i) * i.valor_unitario * 100) / 100)}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>

      <p style={{ color: 'var(--muted)', fontSize: 12, margin: '10px 0 16px', lineHeight: 1.5 }}>
        A devolução usa o preço unitário da nota de compra (reverte a operação original); só a quantidade pode ser
        menor — nunca maior que a da nota, já descontando o que foi devolvido antes.
      </p>

      <div style={{ marginBottom: 16 }}>
        <label htmlFor="obs-devolucao" style={{ color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }}>
          Observações da NF-e de devolução (opcional)
        </label>
        <textarea id="obs-devolucao" value={observacoes} onChange={e => setObservacoes(e.target.value)} rows={2} maxLength={2000}
          style={{ width: '100%', boxSizing: 'border-box', padding: '9px 12px', borderRadius: 8, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, resize: 'vertical' }} />
      </div>

      <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', marginBottom: 20 }}>
        <input type="checkbox" checked={baixarEstoqueJunto} onChange={e => setBaixarEstoqueJunto(e.target.checked)} />
        <span style={{ color: 'var(--text)', fontSize: 14 }}>Retirar também do estoque ao emitir a NF-e</span>
      </label>

      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center' }}>
        <button onClick={aoEmitirNf} disabled={emitindo || baixando || semChave} className="font-display"
          style={{ padding: '10px 24px', background: emitindo || semChave ? 'var(--muted)' : 'var(--success)', color: '#fff', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 16, cursor: emitindo || semChave ? 'not-allowed' : 'pointer' }}>
          {emitindo ? '⟳ Emitindo...' : `Emitir NF-e de devolução · ${formatarMoeda(totalDevolucao)}`}
        </button>
        <button onClick={aoBaixarEstoque} disabled={emitindo || baixando}
          style={{ padding: '10px 20px', background: 'none', border: '1px solid var(--border)', color: 'var(--text)', borderRadius: 8, fontSize: 14, cursor: emitindo || baixando ? 'not-allowed' : 'pointer' }}>
          {baixando ? '⟳ Retirando...' : 'Só retirar do estoque (sem NF)'}
        </button>
      </div>
    </div>
  )
}
