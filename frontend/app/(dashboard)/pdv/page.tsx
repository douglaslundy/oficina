'use client'

import { useState, useEffect, useCallback, useRef } from 'react'
import api from '@/lib/api'
import { formatarMoeda } from '@/lib/formatters'

// ─── Types ────────────────────────────────────────────────────────────────────

interface Produto {
  id: string
  nome: string
  sku: string
  preco_venda: number
  qty_atual: number
  unidade: string
}

interface Cliente {
  id: string
  nome: string
  cpf_cnpj: string
}

interface ItemVenda {
  produto_id: string
  nome: string
  quantidade: number
  valor_unitario: number
}

type ToastType = 'success' | 'danger'

// ─── Toast ────────────────────────────────────────────────────────────────────

function Toast({ msg, type, onClose }: { msg: string; type: ToastType; onClose: () => void }) {
  useEffect(() => {
    const t = setTimeout(onClose, 3500)
    return () => clearTimeout(t)
  }, [onClose])

  return (
    <div style={{
      position: 'fixed', bottom: 24, right: 24, zIndex: 9999,
      background: type === 'success' ? 'var(--success)' : 'var(--danger)',
      color: '#fff', padding: '12px 20px', borderRadius: 8,
      fontSize: 14, fontWeight: 600, boxShadow: '0 4px 20px rgba(0,0,0,.35)',
      display: 'flex', alignItems: 'center', gap: 10,
    }}>
      <span>{type === 'success' ? '✓' : '✕'}</span>
      {msg}
    </div>
  )
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function PdvPage() {
  const [produtoBusca, setProdutoBusca] = useState('')
  const [produtos, setProdutos] = useState<Produto[]>([])
  const [buscandoProdutos, setBuscandoProdutos] = useState(false)
  const [showSugestoes, setShowSugestoes] = useState(false)

  const [clienteBusca, setClienteBusca] = useState('')
  const [clientes, setClientes] = useState<Cliente[]>([])
  const [buscandoClientes, setBuscandoClientes] = useState(false)
  const [showClienteSugestoes, setShowClienteSugestoes] = useState(false)
  const [clienteSelecionado, setClienteSelecionado] = useState<Cliente | null>(null)

  const [itens, setItens] = useState<ItemVenda[]>([])
  const [formaPagamento, setFormaPagamento] = useState('DINHEIRO')
  const [salvando, setSalvando] = useState(false)
  const [toast, setToast] = useState<{ msg: string; type: ToastType } | null>(null)

  const produtoTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const clienteTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const showToast = useCallback((msg: string, type: ToastType) => setToast({ msg, type }), [])

  // ─── Busca de produtos ────────────────────────────────────────────────────

  useEffect(() => {
    if (produtoBusca.length < 2) { setProdutos([]); return }
    if (produtoTimer.current) clearTimeout(produtoTimer.current)
    produtoTimer.current = setTimeout(async () => {
      setBuscandoProdutos(true)
      try {
        const r = await api.get<{ data: Produto[] }>('/produtos', {
          params: { search: produtoBusca, per_page: 8, ativo: 1 },
        })
        setProdutos(r.data.data ?? [])
        setShowSugestoes(true)
      } catch {
        setProdutos([])
      } finally {
        setBuscandoProdutos(false)
      }
    }, 280)
  }, [produtoBusca])

  // ─── Busca de clientes ────────────────────────────────────────────────────

  useEffect(() => {
    if (clienteBusca.length < 2) { setClientes([]); return }
    if (clienteTimer.current) clearTimeout(clienteTimer.current)
    clienteTimer.current = setTimeout(async () => {
      setBuscandoClientes(true)
      try {
        const r = await api.get<{ data: Cliente[] }>('/clientes', {
          params: { search: clienteBusca, per_page: 6 },
        })
        setClientes(r.data.data ?? [])
        setShowClienteSugestoes(true)
      } catch {
        setClientes([])
      } finally {
        setBuscandoClientes(false)
      }
    }, 280)
  }, [clienteBusca])

  // ─── Adicionar produto ────────────────────────────────────────────────────

  function adicionarProduto(p: Produto) {
    setItens(prev => {
      const idx = prev.findIndex(i => i.produto_id === p.id)
      if (idx >= 0) {
        const next = [...prev]
        next[idx] = { ...next[idx], quantidade: next[idx].quantidade + 1 }
        return next
      }
      return [...prev, {
        produto_id: p.id,
        nome: p.nome,
        quantidade: 1,
        valor_unitario: p.preco_venda,
      }]
    })
    setProdutoBusca('')
    setShowSugestoes(false)
  }

  function removerItem(idx: number) {
    setItens(prev => prev.filter((_, i) => i !== idx))
  }

  function atualizarQtd(idx: number, qty: number) {
    if (qty <= 0) { removerItem(idx); return }
    setItens(prev => {
      const next = [...prev]
      next[idx] = { ...next[idx], quantidade: qty }
      return next
    })
  }

  function atualizarPreco(idx: number, preco: number) {
    setItens(prev => {
      const next = [...prev]
      next[idx] = { ...next[idx], valor_unitario: preco }
      return next
    })
  }

  const total = itens.reduce((s, i) => s + i.quantidade * i.valor_unitario, 0)

  // ─── Finalizar venda ──────────────────────────────────────────────────────

  async function finalizarVenda() {
    if (itens.length === 0) {
      showToast('Adicione pelo menos um produto.', 'danger')
      return
    }
    setSalvando(true)
    try {
      await api.post('/os', {
        tipo: 'VENDA_BALCAO',
        cliente_id: clienteSelecionado?.id ?? null,
        forma_pagamento: formaPagamento,
        valor_pago: total,
        itens: itens.map(i => ({
          tipo: 'PECA',
          produto_id: i.produto_id,
          descricao: i.nome,
          quantidade: i.quantidade,
          valor_unitario: i.valor_unitario,
        })),
      })
      showToast('Venda finalizada com sucesso!', 'success')
      setItens([])
      setClienteSelecionado(null)
      setClienteBusca('')
      setFormaPagamento('DINHEIRO')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao finalizar venda.', 'danger')
    } finally {
      setSalvando(false)
    }
  }

  // ─── Render ───────────────────────────────────────────────────────────────

  const inputStyle: React.CSSProperties = {
    width: '100%', padding: '9px 12px', borderRadius: 7,
    border: '1px solid var(--border)', background: 'var(--card)',
    color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box',
  }

  const labelStyle: React.CSSProperties = {
    fontSize: 12, fontWeight: 600, color: 'var(--muted)',
    textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6, display: 'block',
  }

  return (
    <div style={{ padding: '28px 32px', maxWidth: 1100, margin: '0 auto', color: 'var(--text)' }}>
      {toast && <Toast msg={toast.msg} type={toast.type} onClose={() => setToast(null)} />}

      {/* Header */}
      <div style={{ marginBottom: 24 }}>
        <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, margin: 0 }}>
          Venda Balcão
        </h1>
        <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
          Venda de peças e produtos sem abertura de O.S.
        </p>
      </div>

      <div style={{ display: 'flex', gap: 20, alignItems: 'flex-start', flexWrap: 'wrap' }}>

        {/* ── Coluna esquerda — itens ─────────────────────────────────────── */}
        <div style={{ flex: '1 1 540px', minWidth: 0 }}>

          {/* Busca de produto */}
          <div style={{
            background: 'var(--card)', border: '1px solid var(--border)',
            borderRadius: 10, padding: 20, marginBottom: 16,
          }}>
            <label style={labelStyle}>Buscar produto</label>
            <div style={{ position: 'relative' }}>
              <input
                style={inputStyle}
                placeholder="Nome ou SKU do produto..."
                value={produtoBusca}
                onChange={e => { setProdutoBusca(e.target.value); setShowSugestoes(true) }}
                onBlur={() => setTimeout(() => setShowSugestoes(false), 180)}
              />
              {buscandoProdutos && (
                <span style={{ position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--muted)', fontSize: 12 }}>
                  ⟳
                </span>
              )}
              {showSugestoes && produtos.length > 0 && (
                <div style={{
                  position: 'absolute', top: '100%', left: 0, right: 0, zIndex: 50,
                  background: 'var(--surface)', border: '1px solid var(--border)',
                  borderRadius: 8, boxShadow: '0 8px 24px rgba(0,0,0,.4)', overflow: 'hidden',
                  marginTop: 4,
                }}>
                  {produtos.map(p => (
                    <div key={p.id}
                      onMouseDown={() => adicionarProduto(p)}
                      style={{
                        padding: '10px 14px', cursor: 'pointer', display: 'flex',
                        alignItems: 'center', justifyContent: 'space-between',
                        borderBottom: '1px solid var(--border)',
                      }}
                      onMouseEnter={e => (e.currentTarget.style.background = 'rgba(245,166,35,.06)')}
                      onMouseLeave={e => (e.currentTarget.style.background = '')}
                    >
                      <div>
                        <div style={{ fontSize: 13, fontWeight: 600 }}>{p.nome}</div>
                        <div style={{ fontSize: 11, color: 'var(--muted)', fontFamily: 'monospace' }}>
                          {p.sku} · Estoque: {p.qty_atual} {p.unidade}
                        </div>
                      </div>
                      <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--accent)', fontFamily: 'monospace' }}>
                        {formatarMoeda(p.preco_venda)}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>

          {/* Tabela de itens */}
          <div style={{
            background: 'var(--card)', border: '1px solid var(--border)',
            borderRadius: 10, overflow: 'hidden',
          }}>
            <div style={{ padding: '14px 20px', borderBottom: '1px solid var(--border)', fontSize: 13, fontWeight: 600 }}>
              Itens da venda
            </div>

            {itens.length === 0 ? (
              <div style={{ padding: 40, textAlign: 'center', color: 'var(--muted)', fontSize: 14 }}>
                Nenhum produto adicionado
              </div>
            ) : (
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr>
                    {['Produto', 'Qtd', 'Preço Unit.', 'Subtotal', ''].map(h => (
                      <th key={h} style={{
                        padding: '9px 14px', textAlign: 'left', fontSize: 11,
                        fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase',
                        letterSpacing: '0.05em', borderBottom: '1px solid var(--border)',
                      }}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {itens.map((item, idx) => (
                    <tr key={item.produto_id} style={{ borderBottom: idx < itens.length - 1 ? '1px solid var(--border)' : 'none' }}>
                      <td style={{ padding: '10px 14px', fontSize: 13 }}>{item.nome}</td>
                      <td style={{ padding: '10px 14px', width: 80 }}>
                        <input
                          type="number" min={1} step={1}
                          value={item.quantidade}
                          onChange={e => atualizarQtd(idx, parseFloat(e.target.value))}
                          style={{ ...inputStyle, width: 64, padding: '6px 8px', textAlign: 'center' }}
                        />
                      </td>
                      <td style={{ padding: '10px 14px', width: 130 }}>
                        <input
                          type="number" min={0} step={0.01}
                          value={item.valor_unitario}
                          onChange={e => atualizarPreco(idx, parseFloat(e.target.value))}
                          style={{ ...inputStyle, width: 110, padding: '6px 8px', textAlign: 'right', fontFamily: 'monospace' }}
                        />
                      </td>
                      <td style={{ padding: '10px 14px', fontFamily: 'monospace', fontSize: 13, fontWeight: 700, color: 'var(--accent)' }}>
                        {formatarMoeda(item.quantidade * item.valor_unitario)}
                      </td>
                      <td style={{ padding: '10px 14px', textAlign: 'right' }}>
                        <button onClick={() => removerItem(idx)} style={{
                          background: 'none', border: 'none', color: 'var(--danger)',
                          cursor: 'pointer', fontSize: 16, padding: '2px 6px', borderRadius: 4,
                        }}>✕</button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </div>

        {/* ── Coluna direita — resumo + pagamento ────────────────────────── */}
        <div style={{ flex: '0 0 300px', display: 'flex', flexDirection: 'column', gap: 16 }}>

          {/* Cliente (opcional) */}
          <div style={{
            background: 'var(--card)', border: '1px solid var(--border)',
            borderRadius: 10, padding: 20,
          }}>
            <label style={labelStyle}>Cliente (opcional)</label>
            {clienteSelecionado ? (
              <div style={{
                display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                padding: '9px 12px', background: 'rgba(245,166,35,.08)',
                border: '1px solid var(--accent)', borderRadius: 7,
              }}>
                <div>
                  <div style={{ fontSize: 13, fontWeight: 600 }}>{clienteSelecionado.nome}</div>
                  <div style={{ fontSize: 11, color: 'var(--muted)', fontFamily: 'monospace' }}>{clienteSelecionado.cpf_cnpj}</div>
                </div>
                <button onClick={() => { setClienteSelecionado(null); setClienteBusca('') }}
                  style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>
                  ✕
                </button>
              </div>
            ) : (
              <div style={{ position: 'relative' }}>
                <input
                  style={inputStyle}
                  placeholder="Buscar cliente..."
                  value={clienteBusca}
                  onChange={e => { setClienteBusca(e.target.value); setShowClienteSugestoes(true) }}
                  onBlur={() => setTimeout(() => setShowClienteSugestoes(false), 180)}
                />
                {buscandoClientes && (
                  <span style={{ position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--muted)', fontSize: 12 }}>⟳</span>
                )}
                {showClienteSugestoes && clientes.length > 0 && (
                  <div style={{
                    position: 'absolute', top: '100%', left: 0, right: 0, zIndex: 50,
                    background: 'var(--surface)', border: '1px solid var(--border)',
                    borderRadius: 8, boxShadow: '0 8px 24px rgba(0,0,0,.4)', overflow: 'hidden', marginTop: 4,
                  }}>
                    {clientes.map(c => (
                      <div key={c.id}
                        onMouseDown={() => { setClienteSelecionado(c); setClienteBusca(''); setShowClienteSugestoes(false) }}
                        style={{ padding: '10px 14px', cursor: 'pointer', borderBottom: '1px solid var(--border)' }}
                        onMouseEnter={e => (e.currentTarget.style.background = 'rgba(245,166,35,.06)')}
                        onMouseLeave={e => (e.currentTarget.style.background = '')}
                      >
                        <div style={{ fontSize: 13, fontWeight: 600 }}>{c.nome}</div>
                        <div style={{ fontSize: 11, color: 'var(--muted)', fontFamily: 'monospace' }}>{c.cpf_cnpj}</div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}
          </div>

          {/* Forma de pagamento */}
          <div style={{
            background: 'var(--card)', border: '1px solid var(--border)',
            borderRadius: 10, padding: 20,
          }}>
            <label style={labelStyle}>Forma de pagamento</label>
            <select
              value={formaPagamento}
              onChange={e => setFormaPagamento(e.target.value)}
              style={{ ...inputStyle }}
            >
              {['DINHEIRO', 'PIX', 'CARTAO_DEBITO', 'CARTAO_CREDITO', 'BOLETO', 'TRANSFERENCIA'].map(f => (
                <option key={f} value={f}>{f.replace(/_/g, ' ')}</option>
              ))}
            </select>
          </div>

          {/* Total + botão */}
          <div style={{
            background: 'var(--card)', border: '1px solid var(--border)',
            borderRadius: 10, padding: 20,
          }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
              <span style={{ fontSize: 13, color: 'var(--muted)' }}>Itens</span>
              <span style={{ fontSize: 13, fontFamily: 'monospace' }}>{itens.length}</span>
            </div>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18, borderTop: '1px solid var(--border)', paddingTop: 12 }}>
              <span style={{ fontSize: 15, fontWeight: 700 }}>Total</span>
              <span style={{ fontSize: 22, fontWeight: 800, fontFamily: 'monospace', color: 'var(--accent)' }}>
                {formatarMoeda(total)}
              </span>
            </div>

            <button
              onClick={finalizarVenda}
              disabled={salvando || itens.length === 0}
              style={{
                width: '100%', padding: '12px 0',
                background: salvando || itens.length === 0 ? 'var(--border)' : 'var(--accent)',
                color: salvando || itens.length === 0 ? 'var(--muted)' : '#000',
                border: 'none', borderRadius: 8, fontSize: 15,
                fontWeight: 800, cursor: salvando || itens.length === 0 ? 'not-allowed' : 'pointer',
                fontFamily: "'Barlow Condensed', sans-serif", letterSpacing: '0.02em',
                transition: 'background 0.15s',
              }}
            >
              {salvando ? '⟳ Finalizando...' : '✓ Finalizar Venda'}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
