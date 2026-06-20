'use client'

import { useState, useEffect } from 'react'
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

export default function VendaDetalhe() {
  const { id } = useParams<{ id: string }>()
  const router = useRouter()
  const [venda, setVenda] = useState<Venda | null>(null)
  const [loading, setLoading] = useState(true)
  const [erro, setErro] = useState<string | null>(null)

  useEffect(() => {
    api.get<{ data: Venda }>(`/os/${id}`)
      .then(r => setVenda(r.data.data))
      .catch(() => setErro('Erro ao carregar venda.'))
      .finally(() => setLoading(false))
  }, [id])

  const labelStyle: React.CSSProperties = {
    fontSize: 11, fontWeight: 600, color: 'var(--muted)',
    textTransform: 'uppercase', letterSpacing: '0.05em',
  }

  if (loading) return (
    <div style={{ padding: '40px 32px', color: 'var(--muted)', fontSize: 14 }}>Carregando…</div>
  )

  if (erro || !venda) return (
    <div style={{ padding: '40px 32px', color: 'var(--danger)', fontSize: 14 }}>{erro ?? 'Venda não encontrada.'}</div>
  )

  const vencida = venda.data_vencimento_pagamento
    ? (() => {
        const [d, m, y] = venda.data_vencimento_pagamento.split('/')
        return new Date(Number(y), Number(m) - 1, Number(d)) < new Date()
      })()
    : false

  return (
    <div style={{ padding: '28px 32px', maxWidth: 860, margin: '0 auto', color: 'var(--text)' }}>
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
          {venda.venda_a_prazo && venda.saldo_devedor > 0 && (
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
            background: venda.saldo_devedor <= 0 ? 'rgba(67,160,71,.15)' : 'rgba(245,166,35,.15)',
            color: venda.saldo_devedor <= 0 ? 'var(--success)' : 'var(--accent)',
          }}>
            {venda.saldo_devedor <= 0 ? 'PAGO' : 'PENDENTE'}
          </span>
        </div>
      </div>

      {/* Totais */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12, marginBottom: 20 }}>
        {[
          { label: 'Total', valor: venda.valor_total, cor: 'var(--text)' },
          { label: 'Pago', valor: venda.valor_pago, cor: 'var(--success)' },
          { label: 'Saldo Devedor', valor: venda.saldo_devedor, cor: venda.saldo_devedor > 0 ? (vencida ? 'var(--danger)' : 'var(--accent)') : 'var(--muted)' },
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
      {venda.pagamentos.length > 0 && (
        <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
          <div style={{ padding: '12px 18px', borderBottom: '1px solid var(--border)', fontSize: 13, fontWeight: 600 }}>
            Pagamentos recebidos
          </div>
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
        </div>
      )}
    </div>
  )
}
