import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

export interface ProdutoBusca {
  id: string
  nome: string
  sku?: string | null
  codigo_barras?: string | null
  qty_atual: number
  unidade?: string
  preco_venda: number | null
  fiscal_pendente?: boolean
}

// Rótulo do produto na lista de busca, com a quantidade em estoque entre
// parênteses. Ex.: "Correia dentada - (20un)"
export function produtoLabel(p: Pick<ProdutoBusca, 'nome' | 'qty_atual' | 'unidade'>): string {
  const un = (p.unidade ?? 'un').toLowerCase()
  return `${p.nome} - (${p.qty_atual}${un})`
}

// Busca no servidor (parcial, por palavras, sem diferenciar acento/caixa —
// ver ProdutoController::index). Sem texto, devolve os primeiros por nome.
export async function buscarProdutos(texto: string, signal?: AbortSignal): Promise<ProdutoBusca[]> {
  const r = await api.get<{ data: ProdutoBusca[] }>('/produtos', {
    params: { search: texto, per_page: 20 },
    signal,
  })
  return r.data.data ?? []
}

export type ResultadoCodigo =
  | { tipo: 'ok'; produto: ProdutoBusca }
  | { tipo: 'nenhum' }
  | { tipo: 'duplicado'; total: number }

// Regra: só escolhe o produto sozinho se o código identificar exatamente um.
// Com mais de um (ex: SKU de um igual ao código de barras de outro) nunca
// chuta — quem chama pede pra pessoa escolher manualmente.
export function classificarCodigo(produtos: ProdutoBusca[], total: number = produtos.length): ResultadoCodigo {
  if (total > 1) return { tipo: 'duplicado', total }
  if (produtos.length === 1) return { tipo: 'ok', produto: produtos[0] }
  return { tipo: 'nenhum' }
}

// Procura, no servidor, o produto cujo SKU ou código de barras é IGUAL ao
// código digitado/lido (não parcial). Mostra o toast de erro por conta própria
// e devolve null quando não há um único produto.
export async function buscarProdutoPorCodigo(codigo: string): Promise<ProdutoBusca | null> {
  let resultado: ResultadoCodigo
  try {
    const r = await api.get<{ data: ProdutoBusca[]; meta?: { total?: number } }>('/produtos', {
      params: { codigo, per_page: 10 },
    })
    const produtos = r.data.data ?? []
    resultado = classificarCodigo(produtos, r.data.meta?.total ?? produtos.length)
  } catch {
    toast('Erro ao buscar o produto pelo código.', 'danger')
    return null
  }

  if (resultado.tipo === 'ok') return resultado.produto
  if (resultado.tipo === 'duplicado') {
    toast(`Mais de um produto com o código ${codigo}. Selecione manualmente pela busca de peça.`, 'danger')
  } else {
    toast('Nenhuma peça encontrada para este código (código de barras ou SKU).', 'danger')
  }
  return null
}
