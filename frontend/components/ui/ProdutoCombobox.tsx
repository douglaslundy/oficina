'use client'
import { useEffect, useId, useRef, useState } from 'react'
import { toast } from '@/hooks/useToast'
import { buscarProdutos, produtoLabel, resolverCodigoProduto, type ProdutoBusca } from '@/lib/produtoBusca'

// Como a peça foi escolhida: 'codigo' = Enter com um código de barras/SKU
// exato (leitor); 'lista' = sugestão escolhida por clique ou Enter.
export type ViaEscolha = 'codigo' | 'lista'

interface ProdutoComboboxProps {
  // Texto do produto já escolhido; aparece quando o campo não está em uso.
  selectedLabel?: string
  onSelect: (produto: ProdutoBusca, via: ViaEscolha) => void
  placeholder?: string
  disabled?: boolean
  style?: React.CSSProperties
  // Texto extra ao lado do rótulo de cada sugestão (ex: aviso fiscal).
  sufixo?: (produto: ProdutoBusca) => string
}

const ATRASO_MS = 250

// Campo de busca de produto: filtra no servidor enquanto digita (parcial, sem
// diferenciar acento/caixa) no lugar do <select> com todos os produtos.
export function ProdutoCombobox({ selectedLabel, onSelect, placeholder, disabled, style, sufixo }: ProdutoComboboxProps) {
  const [aberto, setAberto] = useState(false)
  const [texto, setTexto] = useState('')
  const [resultados, setResultados] = useState<ProdutoBusca[]>([])
  // Texto da última consulta que já voltou do servidor. "Carregando" é derivado
  // (o que está digitado ainda não foi respondido), sem estado próprio.
  const [resolvido, setResolvido] = useState<string | null>(null)
  const [erro, setErro] = useState(false)
  const [ativo, setAtivo] = useState(0)
  const listaId = useId()
  const itensRef = useRef<Array<HTMLLIElement | null>>([])
  const consultandoCodigo = useRef(false)
  const consulta = texto.trim()
  const carregando = resolvido !== consulta

  useEffect(() => {
    if (!aberto) return
    let cancelado = false // resposta velha (já digitou outra coisa) é descartada
    const controller = new AbortController()
    const timer = setTimeout(async () => {
      try {
        const lista = await buscarProdutos(consulta, controller.signal)
        if (cancelado) return
        setResultados(lista)
        setErro(false)
        setAtivo(0)
        setResolvido(consulta)
      } catch {
        if (cancelado) return
        setResultados([])
        setErro(true)
        setResolvido(consulta)
      }
    }, consulta === '' ? 0 : ATRASO_MS)
    return () => { cancelado = true; clearTimeout(timer); controller.abort() }
  }, [consulta, aberto])

  useEffect(() => {
    itensRef.current[ativo]?.scrollIntoView({ block: 'nearest' })
  }, [ativo])

  function escolher(p: ProdutoBusca, via: ViaEscolha) {
    onSelect(p, via)
    setAberto(false)
    setTexto('')
  }

  // Enter: primeiro tenta o texto como CÓDIGO exato (SKU ou código de barras).
  // É a consulta direta ao servidor, sem esperar a lista de sugestões, porque
  // o leitor de código de barras digita o código e o Enter em milissegundos.
  async function confirmarEnter() {
    const digitado = texto.trim()
    // Enter em campo vazio não escolhe nada (não adiciona "a primeira peça" por engano).
    if (digitado === '' || consultandoCodigo.current) return

    // Lido ANTES do await: a lista só vale se já respondeu ao texto atual.
    const destaque = !carregando && !erro ? resultados[ativo] : undefined
    consultandoCodigo.current = true
    try {
      const r = await resolverCodigoProduto(digitado)
      if (r.tipo === 'ok') {
        escolher(r.produto, 'codigo')
      } else if (r.tipo === 'duplicado') {
        toast(`Mais de um produto com o código ${digitado}. Escolha na lista de sugestões.`, 'danger')
      } else if (destaque) {
        escolher(destaque, 'lista')
      } else {
        toast(`Nenhuma peça com o código ${digitado}. Para buscar pelo nome, aguarde a lista aparecer e escolha uma sugestão.`, 'danger')
      }
    } catch {
      toast('Erro ao buscar a peça pelo código. Tente de novo.', 'danger')
    } finally {
      consultandoCodigo.current = false
    }
  }

  function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Enter') {
      // Campo de busca nunca deve submeter o formulário da OS/NF.
      e.preventDefault()
      void confirmarEnter()
    } else if (e.key === 'ArrowDown') {
      e.preventDefault()
      if (!aberto) setAberto(true)
      else setAtivo(i => Math.min(i + 1, Math.max(resultados.length - 1, 0)))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setAtivo(i => Math.max(i - 1, 0))
    } else if (e.key === 'Escape') {
      setAberto(false)
    }
  }

  return (
    <div style={{ position: 'relative', width: '100%' }}>
      <input
        role="combobox"
        aria-expanded={aberto}
        aria-controls={listaId}
        aria-autocomplete="list"
        autoComplete="off"
        value={aberto ? texto : (selectedLabel ?? '')}
        onChange={e => { setTexto(e.target.value); if (!aberto) setAberto(true) }}
        onFocus={() => { setTexto(''); setResolvido(null); setAberto(true) }}
        onBlur={() => setAberto(false)}
        onKeyDown={handleKeyDown}
        placeholder={aberto && selectedLabel ? selectedLabel : (placeholder ?? 'Buscar peça pelo nome...')}
        disabled={disabled}
        style={{ ...style, width: '100%', boxSizing: 'border-box' }}
      />
      {aberto && (
        <ul
          id={listaId}
          role="listbox"
          style={{
            position: 'absolute', top: '100%', left: 0, right: 0, zIndex: 50, margin: '2px 0 0', padding: 0,
            listStyle: 'none', maxHeight: 240, overflowY: 'auto', background: 'var(--card)',
            border: '1px solid var(--border)', borderRadius: 6, boxShadow: '0 6px 18px rgba(0,0,0,0.4)',
          }}
        >
          {resultados.map((p, i) => (
            <li
              key={p.id}
              ref={el => { itensRef.current[i] = el }}
              role="option"
              aria-selected={i === ativo}
              // mouseDown (e não click) pra o blur do input não fechar a lista antes da escolha.
              onMouseDown={e => { e.preventDefault(); escolher(p, 'lista') }}
              onMouseEnter={() => setAtivo(i)}
              style={{
                padding: '7px 10px', cursor: 'pointer', fontSize: 13, color: 'var(--text)',
                background: i === ativo ? 'rgba(245,166,35,0.15)' : 'transparent',
              }}
            >
              {produtoLabel(p)}{sufixo ? ` ${sufixo(p)}` : ''}
            </li>
          ))}
          {carregando && resultados.length === 0 && (
            <li style={{ padding: '7px 10px', fontSize: 13, color: 'var(--muted)' }}>Buscando...</li>
          )}
          {!carregando && erro && (
            <li style={{ padding: '7px 10px', fontSize: 13, color: 'var(--danger)' }}>Erro ao buscar peças. Tente de novo.</li>
          )}
          {!carregando && !erro && resultados.length === 0 && (
            <li style={{ padding: '7px 10px', fontSize: 13, color: 'var(--muted)' }}>Nenhuma peça encontrada.</li>
          )}
        </ul>
      )}
    </div>
  )
}
