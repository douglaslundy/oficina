'use client'

import { useState, useEffect, useCallback } from 'react'
import { useParams, useRouter } from 'next/navigation'
import api from '@/lib/api'
import { formatarMoeda, formatarData } from '@/lib/formatters'

interface Pagamento {
  id: string
  forma_pagamento: string
  valor: number
  criado_em: string
}

interface Item {
  id: string
  descricao: string
  quantidade: number
  valor_unitario: number
  valor_total: number
}

interface Venda {
  id: string
  numero: number
  tipo: string
  status: string
  cliente?: { id: string; nome: string } | null
  valor_total: number
  valor_pago: number
  saldo_devedor: number
  venda_a_prazo: boolean
  prazo_pagamento_dias?: number
  data_vencimento_pagamento?: string
  itens: Item[]
  pagamentos: Pagamento[]
  criado_em: string
}

const FORMAS_PAG = ['DINHEIRO', 'PIX', 'CARTAO_DEBITO', 'CARTAO_CREDITO', 'BOLETO', 'TRANSFERENCIA']

function Toast({ msg, type, onClose }: { msg: string; type: 'success' | 'danger'; onClose: () => void }) {
  useEffect(() => { const t = setTimeout(onClose, 3000); return () => clearTimeout(t) }, [onClose])
  return (
    <div style={{
      position: 'fixed', bottom: 24, right: 24, zIndex: 9999,
      background: type === 'success' ? 'var(--success)' : 'var(--danger)',
      color: '#fff', padding: '12px 20px', borderRadius: 8,
      fontSize: 14, fontWeight: 600, boxShadow: '0 4px 20px rgba(0,0,0,.35)',
    }}>
      {type === 'success' ? '✓' : '✕'} {msg}
    </div>
  )
}

export default function VendaDetalhe() {
  const { id } = useParams<{ id: string }>()
  const router = useRouter()
  const [venda, setVenda] = useState<Venda | null>(null)
  const [loading, setLoading] = useState(true)
  const [erro, setErro] = useState<string | null>(null)
  const [toast, setToast] = useState<{ msg: string; type: 'success' | 'danger' } | null>(null)

  // Modal de pagamento multiplo
  const [showPagModal, setShowPagModal] = useState(false)
  const [entradas, setEntradas] = useState<{ forma: string; valor: string }[]>([{ forma: 'DINHEIRO', valor: '' }])
  const [salvandoPag, setSalvandoPag] = useState(false)

  const load = useCallback(() => {
    api.get<{ data: Venda }>(`/os/${id}`)
      .then(r => { setVenda(r.data.data); setErro(null) })
      .catch(() => setErro('Erro ao carregar venda.'))
      .finally(() => setLoading(false))
  }, [id])

  useEffect(() => { load() }, [load])

  const totalEntradas = entradas.reduce((s, e) => {
    const v = parseFloat(e.valor); return s + (isNaN(v) ? 0 : v)
  }, 0)

  function abrirModal() {
    setEntradas([{ forma: 'DINHEIRO', valor: '' }])
    setShowPagModal(true)
  }

  function fecharModal() {
    setShowPagModal(false)
    setEntradas([{ forma: 'DINHEIRO', valor: '' }])
  }

  function addEntrada() {
    setEntradas(prev => [...prev, { forma: 'DINHEIRO', valor: '' }])
  }

  function removeEntrada(idx: number) {
    setEntradas(prev => prev.filter((_, i) => i !== idx))
  }

  function updateEntrada(idx: number, field: 'forma' | 'valor', val: string) {
    setEntradas(prev => prev.map((e, i) => i === idx ? { ...e, [field]: val } : e))
  }

  async function registrarPagamentos() {
    const validas = entradas.filter(e => parseFloat(e.valor) > 0)
    if (validas.length === 0) {
      setToast({ msg: 'Informe pelo menos um valor.', type: 'danger' }); return
    }
    setSalvandoPag(true)
    try {
      for (const e of validas) {
        await api.post(`/os/${id}/pagamentos`, { forma_pagamento: e.forma, valor: parseFloat(e.valor) })
      }
      setToast({ msg: `${validas.length > 1 ? validas.length + ' pagamentos registrados!' : 'Pagamento registrado!'}`, type: 'success' })
      fecharModal()
      load()
    } catch {
      setToast({ msg: 'Erro ao registrar pagamento.', type: 'danger' })
    } finally {
      setSalvandoPag(false)
    }
  }

  const inputStyle: React.CSSProperties = {
    width: '100%', padding: '9px 12px', borderRadius: 7,
    border: '1px solid var(--border)', background: 'var(--card)',
    color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box',
  }

  const labelStyle: React.CSSProperties = {
    fontSize: 11, fontWeight: 600, color: 'var(--muted)',
    textTransform: 'uppercase', letterSpacing: '0.05em',
  }

  if (loading) return <div style={{ padding: '40px 32px', color: 'var(--muted)', fontSize: 14 }}>Carregando…</div>
  if (erro || !venda) return <div style={{ padding: '40px 32px', color: 'var(--danger)', fontSize: 14 }}>{erro ?? 'Venda não encontrada.'}</div>

  const vencida = venda.data_vencimento_pagamento
    ? (() => {
        const [d, m, y] = venda.data_vencimento_pagamento.split('/')
        return new Date(Number(y), Number(m) - 1, Number(d)) < new Date()
      })()
    : false

  const temSaldo = venda.saldo_devedor > 0

  return (
    <div style={{ padding: '28px 32px', maxWidth: 860, margin: '0 auto', color: 'var(--text)' }}>
      {toast && <Toast msg={toast.msg} type={toast.type} onClose={() => setToast(null)} />}

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: 24 }}>
        <div>
          <button onClick={() => router.back()} style={{
            background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer',
            fontSize: 13, padding: 0, marginBottom: 8, display: 'flex', alignItems: 'center', gap: 4,
          }}>← Voltar</button>
          <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, margin: 0 }}>
            Venda Balcão #{venda.numero}
          </h1>
          <p style={{ color: 'var(--muted)', fontSize: 13, margin: '4px 0 0' }}>
            {formatarData(venda.criado_em)}
            {venda.cliente && <> · <span style={{ color: 'var(--text)' }}>{venda.cliente.nome}</span></>}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          {venda.venda_a_prazo && temSaldo && (
            <span style={{
              padding: '4px 12px', borderRadius: 999, fontSize: 11, fontWeight: 700,
              background: vencida ? 'rgba(229,57,53,.15)' : 'rgba(245,166,35,.15)',
              color: vencida ? 'var(--danger)' : 'var(--accent)',
            }}>
              {vencida ? 'VENCIDA' : 'A PRAZO'}
            </span>
          )}
          <span style={{
            padding: '4px 12px', borderRadius: 999, fontSize: 11, fontWeight: 700,
            background: !temSaldo ? 'rgba(67,160,71,.15)' : 'rgba(245,166,35,.15)',
            color: !temSaldo ? 'var(--success)' : 'var(--accent)',
          }}>
            {!temSaldo ? 'PAGO' : 'PENDENTE'}
          </span>
          {temSaldo && (
            <button
              onClick={abrirModal}
              style={{
                padding: '7px 18px', borderRadius: 7, fontWeight: 700, fontSize: 13,
                background: 'var(--accent)', color: '#000', border: 'none', cursor: 'pointer',
                fontFamily: "'Barlow Condensed', sans-serif",
              }}
            >
              + Registrar Pagamento
            </button>
          )}
        </div>
      </div>

      {/* Totais */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12, marginBottom: 20 }}>
        {[
          { label: 'Total', valor: venda.valor_total, cor: 'var(--text)' },
          { label: 'Pago', valor: venda.valor_pago, cor: 'var(--success)' },
          { label: 'Saldo Devedor', valor: venda.saldo_devedor, cor: temSaldo ? (vencida ? 'var(--danger)' : 'var(--accent)') : 'var(--muted)' },
        ].map(s => (
          <div key={s.label} style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: '14px 18px' }}>
            <p style={{ ...labelStyle, margin: '0 0 6px' }}>{s.label}</p>
            <p className="font-mono" style={{ margin: 0, fontSize: 20, fontWeight: 800, color: s.cor }}>
              {formatarMoeda(s.valor)}
            </p>
          </div>
        ))}
      </div>

      {/* Vencimento */}
      {venda.venda_a_prazo && venda.data_vencimento_pagamento && (
        <div style={{
          padding: '10px 16px', borderRadius: 8, marginBottom: 20,
          background: vencida ? 'rgba(229,57,53,.08)' : 'rgba(245,166,35,.08)',
          border: `1px solid ${vencida ? 'var(--danger)' : 'var(--accent)'}`,
          fontSize: 13, color: vencida ? 'var(--danger)' : 'var(--accent)', fontWeight: 600,
        }}>
          {vencida ? '⚠ Vencimento: ' : '📅 Vencimento: '}{venda.data_vencimento_pagamento}
          {venda.prazo_pagamento_dias && <span style={{ fontWeight: 400, marginLeft: 8 }}>({venda.prazo_pagamento_dias} dias)</span>}
        </div>
      )}

      {/* Itens */}
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden', marginBottom: 20 }}>
        <div style={{ padding: '12px 18px', borderBottom: '1px solid var(--border)', fontSize: 13, fontWeight: 600 }}>
          Produtos
        </div>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead>
            <tr>
              {['Descrição', 'Qtd', 'Preço Unit.', 'Subtotal'].map(h => (
                <th key={h} style={{
                  padding: '9px 14px', textAlign: 'left', fontSize: 11, fontWeight: 600,
                  color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em',
                  borderBottom: '1px solid var(--border)', background: 'var(--surface)',
                }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {venda.itens.length === 0 ? (
              <tr><td colSpan={4} style={{ padding: '24px 14px', textAlign: 'center', color: 'var(--muted)', fontSize: 13 }}>Sem itens</td></tr>
            ) : venda.itens.map((item, idx) => (
              <tr key={item.id} style={{ borderBottom: idx < venda.itens.length - 1 ? '1px solid var(--border)' : 'none' }}>
                <td style={{ padding: '10px 14px', fontSize: 13 }}>{item.descricao}</td>
                <td style={{ padding: '10px 14px', fontSize: 13, fontFamily: 'monospace' }}>{item.quantidade}</td>
                <td style={{ padding: '10px 14px', fontSize: 13, fontFamily: 'monospace' }}>{formatarMoeda(item.valor_unitario)}</td>
                <td style={{ padding: '10px 14px', fontSize: 13, fontFamily: 'monospace', fontWeight: 700, color: 'var(--accent)' }}>
                  {formatarMoeda(item.valor_total)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Pagamentos */}
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
        <div style={{ padding: '12px 18px', borderBottom: '1px solid var(--border)', fontSize: 13, fontWeight: 600 }}>
          Pagamentos recebidos {venda.pagamentos.length === 0 && <span style={{ color: 'var(--muted)', fontWeight: 400 }}>— nenhum ainda</span>}
        </div>
        {venda.pagamentos.length > 0 && (
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr>
                {['Data', 'Forma', 'Valor'].map(h => (
                  <th key={h} style={{
                    padding: '9px 14px', textAlign: 'left', fontSize: 11, fontWeight: 600,
                    color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em',
                    borderBottom: '1px solid var(--border)', background: 'var(--surface)',
                  }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {venda.pagamentos.map((pag, idx) => (
                <tr key={pag.id} style={{ borderBottom: idx < venda.pagamentos.length - 1 ? '1px solid var(--border)' : 'none' }}>
                  <td style={{ padding: '10px 14px', fontSize: 12, color: 'var(--muted)' }}>{pag.criado_em}</td>
                  <td style={{ padding: '10px 14px', fontSize: 13 }}>{pag.forma_pagamento.replace(/_/g, ' ')}</td>
                  <td style={{ padding: '10px 14px', fontSize: 13, fontFamily: 'monospace', fontWeight: 700, color: 'var(--success)' }}>
                    {formatarMoeda(pag.valor)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {temSaldo && (
          <div style={{ padding: '14px 18px', borderTop: venda.pagamentos.length > 0 ? '1px solid var(--border)' : 'none' }}>
            <button
              onClick={abrirModal}
              style={{
                padding: '9px 20px', borderRadius: 7, fontWeight: 700, fontSize: 13,
                background: 'var(--accent)', color: '#000', border: 'none', cursor: 'pointer',
                fontFamily: "'Barlow Condensed', sans-serif",
              }}
            >
              + Registrar Pagamento
            </button>
          </div>
        )}
      </div>

      {/* Modal de multipagamento */}
      {showPagModal && (
        <div
          onClick={e => { if (e.target === e.currentTarget) fecharModal() }}
          style={{
            position: 'fixed', inset: 0, background: 'rgba(0,0,0,.55)',
            display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000,
          }}
        >
          <div style={{
            background: 'var(--card)', border: '1px solid var(--border)',
            borderRadius: 12, padding: 28, width: 420, boxShadow: '0 16px 48px rgba(0,0,0,.4)',
          }}>
            <h3 className="font-display" style={{ fontSize: 18, fontWeight: 800, margin: '0 0 4px' }}>
              Registrar Pagamento
            </h3>
            <p style={{ color: 'var(--muted)', fontSize: 13, margin: '0 0 20px' }}>
              Saldo em aberto: <span className="font-mono" style={{ color: 'var(--accent)', fontWeight: 700 }}>{formatarMoeda(venda.saldo_devedor)}</span>
            </p>

            {/* Entradas */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginBottom: 12 }}>
              {entradas.map((e, idx) => (
                <div key={idx} style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                  <select
                    value={e.forma}
                    onChange={ev => updateEntrada(idx, 'forma', ev.target.value)}
                    style={{ ...inputStyle, flex: '0 0 148px', padding: '8px 10px' }}
                  >
                    {FORMAS_PAG.map(f => <option key={f} value={f}>{f.replace(/_/g, ' ')}</option>)}
                  </select>
                  <input
                    type="number" min={0.01} step={0.01} placeholder="Valor"
                    value={e.valor}
                    onChange={ev => updateEntrada(idx, 'valor', ev.target.value)}
                    style={{ ...inputStyle, flex: 1, padding: '8px 10px', textAlign: 'right', fontFamily: 'monospace' }}
                    autoFocus={idx === 0}
                  />
                  {entradas.length > 1 && (
                    <button onClick={() => removeEntrada(idx)} style={{
                      background: 'none', border: 'none', color: 'var(--danger)',
                      cursor: 'pointer', fontSize: 18, padding: '0 4px', flexShrink: 0,
                    }}>✕</button>
                  )}
                </div>
              ))}
            </div>

            {/* Adicionar meio */}
            <button onClick={addEntrada} style={{
              width: '100%', padding: '7px 0', borderRadius: 6, fontSize: 13,
              background: 'none', border: '1px dashed var(--border)', color: 'var(--muted)',
              cursor: 'pointer', marginBottom: 18,
            }}>
              + Adicionar meio de pagamento
            </button>

            {/* Totalizador */}
            {entradas.length > 1 && (
              <div style={{
                display: 'flex', justifyContent: 'space-between', padding: '10px 14px',
                borderRadius: 8, marginBottom: 18,
                background: totalEntradas > venda.saldo_devedor
                  ? 'rgba(67,160,71,.08)' : 'rgba(245,166,35,.08)',
                border: `1px solid ${totalEntradas > venda.saldo_devedor ? 'var(--success)' : 'var(--border)'}`,
              }}>
                <span style={{ fontSize: 13, color: 'var(--muted)' }}>Total a pagar</span>
                <span className="font-mono" style={{
                  fontSize: 14, fontWeight: 700,
                  color: totalEntradas >= venda.saldo_devedor ? 'var(--success)' : 'var(--accent)',
                }}>
                  {formatarMoeda(totalEntradas)}
                </span>
              </div>
            )}

            <div style={{ display: 'flex', gap: 10 }}>
              <button
                onClick={registrarPagamentos}
                disabled={salvandoPag || totalEntradas <= 0}
                style={{
                  flex: 1, padding: '10px 0', borderRadius: 7, fontWeight: 800,
                  background: salvandoPag || totalEntradas <= 0 ? 'var(--border)' : 'var(--accent)',
                  color: salvandoPag || totalEntradas <= 0 ? 'var(--muted)' : '#000',
                  border: 'none', cursor: salvandoPag || totalEntradas <= 0 ? 'not-allowed' : 'pointer',
                  fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif",
                }}
              >
                {salvandoPag ? '⟳ Salvando…' : '✓ Confirmar Pagamento'}
              </button>
              <button onClick={fecharModal} style={{
                padding: '10px 18px', borderRadius: 7, background: 'transparent',
                border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer', fontSize: 14,
              }}>
                Cancelar
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
