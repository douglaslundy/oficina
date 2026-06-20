'use client'
import { useState, useEffect } from 'react'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'
import { formatarMoeda } from '@/lib/formatters'

interface ItemNF {
  descricao: string
  quantidade: number
  valor_unitario: number
}

interface ClienteOpt {
  id: string
  nome: string
  cpf_cnpj: string
  cidade?: string
  uf?: string
}

interface Empresa {
  nome_fantasia?: string
  razao_social?: string
  cnpj?: string
  cidade?: string
  uf?: string
  ambiente_fiscal?: string
  aliquota_iss?: number
}

interface PlanInfo {
  atual: number
  limite: number
  percent: number
  preco_excedente: number
}

const iStyle: React.CSSProperties = {
  width: '100%',
  padding: '9px 12px',
  borderRadius: 8,
  background: 'var(--bg)',
  border: '1px solid var(--border)',
  color: 'var(--text)',
  fontSize: 14,
  outline: 'none',
  boxSizing: 'border-box',
}

const lStyle: React.CSSProperties = {
  color: 'var(--muted)',
  fontSize: 13,
  display: 'block',
  marginBottom: 4,
}

export function NotaFiscalForm() {
  const [clientes, setClientes] = useState<ClienteOpt[]>([])
  const [clienteId, setClienteId] = useState('')
  const [natureza, setNatureza] = useState('Prestação de Serviços')
  const [formaPgto, setFormaPgto] = useState('')
  const [itens, setItens] = useState<ItemNF[]>([{ descricao: '', quantidade: 1, valor_unitario: 0 }])
  const [desconto, setDesconto] = useState(0)
  const [aliquota, setAliquota] = useState(5)
  const [obs, setObs] = useState('')
  const [loading, setLoading] = useState(false)
  const [empresa, setEmpresa] = useState<Empresa | null>(null)
  const [planInfo, setPlanInfo] = useState<PlanInfo | null>(null)

  useEffect(() => {
    Promise.all([
      api.get('/clientes?per_page=200'),
      api.get('/configuracoes'),
      api.get('/plano/limites'),
    ]).then(([c, e, p]) => {
      setClientes(c.data.data ?? [])
      setEmpresa(e.data)
      setAliquota(Number(e.data?.aliquota_iss ?? 5))
      setPlanInfo(p.data?.notas_mes ?? null)
    }).catch(() => {})
  }, [])

  const clienteSelecionado = clientes.find(c => c.id === clienteId)
  const subtotal = itens.reduce((acc, i) => acc + i.quantidade * i.valor_unitario, 0)
  const valorIss = ((subtotal - desconto) * aliquota) / 100
  const total = subtotal - desconto + valorIss

  function updateItem(idx: number, field: keyof ItemNF, value: string | number) {
    setItens(prev => prev.map((item, j) => j === idx ? { ...item, [field]: value } : item))
  }

  async function emitir() {
    if (!clienteId) { toast('Selecione um cliente.', 'danger'); return }
    if (itens.every(i => !i.descricao)) { toast('Adicione pelo menos um item.', 'danger'); return }
    setLoading(true)
    try {
      const nf = await api.post('/notas-fiscais', {
        cliente_id: clienteId,
        natureza_operacao: natureza,
        forma_pagamento: formaPgto || undefined,
        subtotal,
        desconto,
        aliquota_iss: aliquota,
        observacoes: obs || undefined,
      })
      const resultado = await api.post(`/notas-fiscais/${nf.data.data.id}/emitir`)
      toast(`NF #${resultado.data.data.numero} emitida com sucesso!`, 'success')
      setClienteId('')
      setItens([{ descricao: '', quantidade: 1, valor_unitario: 0 }])
      setDesconto(0)
      setObs('')
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao emitir NF.', 'danger')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 320px', gap: 24, alignItems: 'start' }}>
      {/* Formulário esquerdo */}
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 28 }}>
        <h2 className="font-display" style={{ fontSize: 22, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>
          Emitir Nota Fiscal
        </h2>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 20 }}>
          <div style={{ gridColumn: '1 / -1' }}>
            <label style={lStyle}>Cliente</label>
            <select value={clienteId} onChange={e => setClienteId(e.target.value)} style={iStyle}>
              <option value="">Selecionar cliente...</option>
              {clientes.map(c => <option key={c.id} value={c.id}>{c.nome}</option>)}
            </select>
          </div>
          <div>
            <label style={lStyle}>Natureza da operação</label>
            <select value={natureza} onChange={e => setNatureza(e.target.value)} style={iStyle}>
              <option>Prestação de Serviços</option>
              <option>Venda de Mercadoria</option>
              <option>Misto</option>
            </select>
          </div>
          <div>
            <label style={lStyle}>Forma de pagamento</label>
            <select value={formaPgto} onChange={e => setFormaPgto(e.target.value)} style={iStyle}>
              <option value="">Selecionar...</option>
              {['Dinheiro', 'PIX', 'Cartão de Crédito', 'Cartão de Débito', 'Boleto'].map(p => (
                <option key={p}>{p}</option>
              ))}
            </select>
          </div>
        </div>

        {/* Itens */}
        <div style={{ marginBottom: 20 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 10, alignItems: 'center' }}>
            <label style={lStyle}>Itens da nota</label>
            <button
              type="button"
              onClick={() => setItens(prev => [...prev, { descricao: '', quantidade: 1, valor_unitario: 0 }])}
              style={{
                padding: '4px 12px',
                background: 'rgba(245,166,35,0.15)',
                border: '1px solid var(--accent)',
                color: 'var(--accent)',
                borderRadius: 6,
                cursor: 'pointer',
                fontSize: 13,
              }}
            >
              + Adicionar
            </button>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '3fr 1fr 1fr auto', gap: 6, marginBottom: 6 }}>
            {['Descrição', 'Qtd', 'Valor unit.', ''].map(h => (
              <span key={h} style={{ color: 'var(--muted)', fontSize: 11, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                {h}
              </span>
            ))}
          </div>
          {itens.map((item, idx) => (
            <div key={idx} style={{ display: 'grid', gridTemplateColumns: '3fr 1fr 1fr auto', gap: 6, marginBottom: 6 }}>
              <input
                value={item.descricao}
                onChange={e => updateItem(idx, 'descricao', e.target.value)}
                placeholder="Descrição"
                style={iStyle}
              />
              <input
                type="number"
                min={0.01}
                step="0.01"
                value={item.quantidade}
                onChange={e => updateItem(idx, 'quantidade', +e.target.value)}
                style={iStyle}
              />
              <input
                type="number"
                min={0}
                step="0.01"
                value={item.valor_unitario}
                onChange={e => updateItem(idx, 'valor_unitario', +e.target.value)}
                style={iStyle}
              />
              {itens.length > 1 ? (
                <button
                  type="button"
                  onClick={() => setItens(prev => prev.filter((_, j) => j !== idx))}
                  style={{ padding: '0 12px', background: 'transparent', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 18 }}
                >
                  ×
                </button>
              ) : <div />}
            </div>
          ))}
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 20 }}>
          <div>
            <label style={lStyle}>Desconto (R$)</label>
            <input type="number" min={0} step="0.01" value={desconto} onChange={e => setDesconto(+e.target.value)} style={iStyle} />
          </div>
          <div>
            <label style={lStyle}>Alíquota ISS (%)</label>
            <input type="number" min={0} max={100} step="0.01" value={aliquota} onChange={e => setAliquota(+e.target.value)} style={iStyle} />
          </div>
          <div style={{ gridColumn: '1 / -1' }}>
            <label style={lStyle}>Observações</label>
            <textarea value={obs} onChange={e => setObs(e.target.value)} rows={3} style={{ ...iStyle, resize: 'vertical' }} />
          </div>
        </div>

        {/* Resumo */}
        <div style={{ background: 'var(--bg)', borderRadius: 8, padding: 16, fontFamily: 'JetBrains Mono, monospace', fontSize: 14 }}>
          {([
            ['Subtotal', formatarMoeda(subtotal)],
            ['Desconto', `-${formatarMoeda(desconto)}`],
            [`ISS (${aliquota}%)`, formatarMoeda(valorIss)],
          ] as [string, string][]).map(([l, v]) => (
            <div key={l} style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--muted)', marginBottom: 6 }}>
              <span>{l}</span><span>{v}</span>
            </div>
          ))}
          <div style={{
            display: 'flex',
            justifyContent: 'space-between',
            color: 'var(--text)',
            fontWeight: 700,
            fontSize: 18,
            borderTop: '1px solid var(--border)',
            paddingTop: 10,
            marginTop: 6,
          }}>
            <span>TOTAL</span>
            <span style={{ color: 'var(--accent)' }}>{formatarMoeda(total)}</span>
          </div>
        </div>
      </div>

      {/* Painel lateral direito */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        {empresa && (
          <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 20 }}>
            <h4 className="font-display" style={{ fontSize: 14, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 12 }}>
              Emitente
            </h4>
            <p style={{ color: 'var(--text)', fontSize: 14, fontWeight: 600, margin: '0 0 4px' }}>
              {empresa.nome_fantasia || empresa.razao_social}
            </p>
            <p className="font-mono" style={{ color: 'var(--muted)', fontSize: 13, margin: 0 }}>{empresa.cnpj}</p>
            <p style={{ color: 'var(--muted)', fontSize: 13, margin: '4px 0 0' }}>{empresa.cidade} — {empresa.uf}</p>
            <div style={{
              marginTop: 12,
              padding: '6px 10px',
              borderRadius: 6,
              background: empresa.ambiente_fiscal === 'PRODUCAO' ? 'rgba(67,160,71,0.1)' : 'rgba(245,166,35,0.1)',
            }}>
              <span style={{ fontSize: 12, fontWeight: 600, color: empresa.ambiente_fiscal === 'PRODUCAO' ? 'var(--success)' : 'var(--accent)' }}>
                {empresa.ambiente_fiscal === 'PRODUCAO' ? '● Produção' : '● Homologação'}
              </span>
            </div>
          </div>
        )}

        {clienteSelecionado && (
          <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 20 }}>
            <h4 className="font-display" style={{ fontSize: 14, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 12 }}>
              Destinatário
            </h4>
            <p style={{ color: 'var(--text)', fontSize: 14, fontWeight: 600, margin: '0 0 4px' }}>{clienteSelecionado.nome}</p>
            <p className="font-mono" style={{ color: 'var(--muted)', fontSize: 13, margin: 0 }}>{clienteSelecionado.cpf_cnpj}</p>
            <p style={{ color: 'var(--muted)', fontSize: 13, margin: '4px 0 0' }}>
              {clienteSelecionado.cidade} — {clienteSelecionado.uf}
            </p>
          </div>
        )}

        {planInfo && (
          <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 20 }}>
            <h4 className="font-display" style={{ fontSize: 14, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 12 }}>
              Cota de Notas
            </h4>
            {planInfo.limite === -1 ? (
              <p style={{ color: 'var(--success)', fontSize: 14, fontWeight: 600, margin: 0 }}>Ilimitado</p>
            ) : (
              <>
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8 }}>
                  <span style={{ color: 'var(--muted)', fontSize: 13 }}>Notas este mês</span>
                  <span className="font-mono" style={{ fontSize: 13, fontWeight: 700, color: planInfo.atual >= planInfo.limite ? 'var(--danger)' : 'var(--text)' }}>
                    {planInfo.atual} / {planInfo.limite}
                  </span>
                </div>
                <div style={{ height: 4, borderRadius: 999, background: 'var(--border)', overflow: 'hidden', marginBottom: 10 }}>
                  <div style={{
                    height: '100%',
                    borderRadius: 999,
                    width: `${Math.min(100, planInfo.percent)}%`,
                    background: planInfo.percent >= 100 ? 'var(--danger)' : planInfo.percent >= 75 ? 'var(--accent)' : 'var(--success)',
                    transition: 'width 0.4s',
                  }} />
                </div>
                {planInfo.preco_excedente > 0 && (
                  <p style={{ color: 'var(--muted)', fontSize: 12, margin: '0 0 6px' }}>
                    Nota excedente: <span className="font-mono" style={{ color: 'var(--accent)' }}>
                      {new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(planInfo.preco_excedente)}
                    </span> / nota
                  </p>
                )}
                {planInfo.atual > planInfo.limite && planInfo.preco_excedente > 0 && (
                  <div style={{ marginTop: 8, padding: '8px 12px', borderRadius: 8, background: 'rgba(229,57,53,.1)', border: '1px solid var(--danger)' }}>
                    <span style={{ color: 'var(--danger)', fontSize: 12, fontWeight: 600 }}>Saldo excedente a pagar: </span>
                    <span className="font-mono" style={{ color: 'var(--danger)', fontSize: 13, fontWeight: 700 }}>
                      {new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format((planInfo.atual - planInfo.limite) * planInfo.preco_excedente)}
                    </span>
                  </div>
                )}
              </>
            )}
          </div>
        )}

        <button
          onClick={emitir}
          disabled={loading}
          className="font-display"
          style={{
            width: '100%',
            padding: 14,
            borderRadius: 10,
            background: loading ? 'var(--muted)' : 'var(--success)',
            color: '#fff',
            border: 'none',
            fontWeight: 800,
            fontSize: 18,
            cursor: loading ? 'not-allowed' : 'pointer',
          }}
        >
          {loading ? '⟳ Processando...' : 'EMITIR NOTA FISCAL'}
        </button>
      </div>
    </div>
  )
}
