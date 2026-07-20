'use client'

import { useState, useEffect, useCallback } from 'react'
import api from '@/lib/api'
import { formatarMoeda, formatarDataUTC, formatarDataHora } from '@/lib/formatters'
import { StatusPill } from '@/components/ui/StatusPill'
import { PagamentoTransparenteModal } from '@/components/PagamentoTransparenteModal'

interface Fatura {
  id: string
  tipo: 'ASSINATURA' | 'AVULSA'
  tipo_label: string
  descricao: string
  valor: string
  status: 'PENDENTE' | 'VENCIDA' | 'PAGA' | 'CANCELADA'
  vencimento: string | null
  pago_em: string | null
  link_pagamento: string | null
  gateway: 'ASAAS' | 'MERCADOPAGO' | null
  id_pagamento: string | null
}

function Sk({ w, h }: { w?: string | number; h?: number }) {
  return <div style={{ width: w ?? '100%', height: h ?? 14, borderRadius: 6, background: 'var(--border)', animation: 'pulse 1.4s ease-in-out infinite' }} />
}

const labelStyle: React.CSSProperties = {
  fontSize: 11, fontWeight: 600, color: 'var(--muted)',
  textTransform: 'uppercase', letterSpacing: '0.05em',
}

export default function MinhasFaturasPage() {
  const [faturas, setFaturas] = useState<Fatura[]>([])
  const [loading, setLoading] = useState(true)
  const [semPermissao, setSemPermissao] = useState(false)
  const [erro, setErro] = useState<string | null>(null)
  const [detalhe, setDetalhe] = useState<Fatura | null>(null)
  const [pagamento, setPagamento] = useState<Fatura | null>(null)

  const fetchFaturas = useCallback(async () => {
    setLoading(true)
    setErro(null)
    setSemPermissao(false)
    try {
      const res = await api.get<{ data: Fatura[] }>('/assinatura/faturas')
      setFaturas(res.data.data ?? [])
    } catch (e: unknown) {
      const status = (e as { response?: { status?: number } })?.response?.status
      if (status === 403) {
        setSemPermissao(true)
      } else {
        setErro('Não foi possível carregar suas faturas. Tente novamente.')
      }
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { fetchFaturas() }, [fetchFaturas])

  const totalPendente = faturas
    .filter(f => f.status === 'PENDENTE' || f.status === 'VENCIDA')
    .reduce((s, f) => s + Number(f.valor), 0)
  const totalPago = faturas
    .filter(f => f.status === 'PAGA')
    .reduce((s, f) => s + Number(f.valor), 0)
  const qtdVencidas = faturas.filter(f => f.status === 'VENCIDA').length

  return (
    <>
      <style>{`@keyframes pulse{0%,100%{opacity:1}50%{opacity:.45}}`}</style>

      <div style={{ padding: '28px 32px', maxWidth: 1060, margin: '0 auto', color: 'var(--text)' }}>
        <div style={{ marginBottom: 20 }}>
          <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, margin: 0 }}>Minhas Faturas</h1>
          <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
            Mensalidade/anuidade e cobranças avulsas da sua oficina no MecânicaPro
          </p>
        </div>

        {semPermissao ? (
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: 48, textAlign: 'center' }}>
            <p style={{ fontSize: 15, color: 'var(--muted)', margin: 0 }}>
              Você não tem permissão para ver as faturas da oficina. Fale com o administrador.
            </p>
          </div>
        ) : (
          <>
            {/* KPIs */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 14, marginBottom: 20 }}>
              <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderTop: '2px solid var(--accent)', borderRadius: 10, padding: '14px 18px' }}>
                <p style={{ ...labelStyle, margin: '0 0 5px' }}>Em Aberto</p>
                {loading ? <Sk w={100} h={22} /> : (
                  <p className="font-mono" style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--accent)' }}>{formatarMoeda(totalPendente)}</p>
                )}
              </div>
              <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderTop: '2px solid var(--danger)', borderRadius: 10, padding: '14px 18px' }}>
                <p style={{ ...labelStyle, margin: '0 0 5px' }}>Vencidas</p>
                {loading ? <Sk w={40} h={22} /> : (
                  <p className="font-mono" style={{ margin: 0, fontSize: 20, fontWeight: 800, color: qtdVencidas > 0 ? 'var(--danger)' : 'var(--text)' }}>{qtdVencidas}</p>
                )}
              </div>
              <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderTop: '2px solid var(--success)', borderRadius: 10, padding: '14px 18px' }}>
                <p style={{ ...labelStyle, margin: '0 0 5px' }}>Total Pago</p>
                {loading ? <Sk w={100} h={22} /> : (
                  <p className="font-mono" style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--success)' }}>{formatarMoeda(totalPago)}</p>
                )}
              </div>
            </div>

            {erro && (
              <div style={{ background: 'rgba(229,57,53,.1)', border: '1px solid var(--danger)', borderRadius: 8, padding: '10px 14px', color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>
                {erro}
              </div>
            )}

            <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
              <button onClick={fetchFaturas} style={{ padding: '7px 14px', borderRadius: 6, fontSize: 13, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer' }}>
                ↻ Atualizar
              </button>
            </div>

            {/* Tabela */}
            <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
              <div style={{ overflowX: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                  <thead>
                    <tr>
                      {['Descrição', 'Tipo', 'Vencimento', 'Valor', 'Status', 'Ação'].map(h => (
                        <th key={h} style={{
                          padding: '10px 14px', textAlign: 'left', fontSize: 11, fontWeight: 600,
                          color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em',
                          borderBottom: '1px solid var(--border)', background: 'var(--surface)', whiteSpace: 'nowrap',
                        }}>{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {loading ? (
                      Array.from({ length: 5 }).map((_, i) => (
                        <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                          {Array.from({ length: 6 }).map((_, j) => (
                            <td key={j} style={{ padding: '12px 14px' }}><Sk w={60} h={12} /></td>
                          ))}
                        </tr>
                      ))
                    ) : faturas.length === 0 ? (
                      <tr>
                        <td colSpan={6} style={{ padding: 48, textAlign: 'center', color: 'var(--muted)', fontSize: 14 }}>
                          Nenhuma fatura encontrada.
                        </td>
                      </tr>
                    ) : faturas.map((f, idx) => {
                      const vencida = f.status === 'VENCIDA'
                      return (
                        <tr key={f.id} style={{
                          borderBottom: idx < faturas.length - 1 ? '1px solid var(--border)' : 'none',
                          background: vencida ? 'rgba(229,57,53,.06)' : 'transparent',
                        }}>
                          <td style={{ padding: '11px 14px', fontSize: 13 }}>{f.descricao}</td>
                          <td style={{ padding: '11px 14px' }}>
                            <span style={{
                              fontSize: 11, padding: '2px 8px', borderRadius: 4, fontWeight: 700,
                              background: f.tipo === 'ASSINATURA' ? 'rgba(245,166,35,.15)' : 'rgba(30,136,229,.15)',
                              color: f.tipo === 'ASSINATURA' ? 'var(--accent)' : 'var(--info)',
                            }}>
                              {f.tipo_label}
                            </span>
                          </td>
                          <td style={{ padding: '11px 14px', fontFamily: "'JetBrains Mono', monospace", fontSize: 12, color: 'var(--text)' }}>
                            {formatarDataUTC(f.vencimento)}
                          </td>
                          <td style={{ padding: '11px 14px', fontFamily: "'JetBrains Mono', monospace", fontSize: 13, fontWeight: 600, color: 'var(--text)' }}>
                            {formatarMoeda(Number(f.valor))}
                          </td>
                          <td style={{ padding: '11px 14px' }}><StatusPill status={f.status} /></td>
                          <td style={{ padding: '11px 14px' }}>
                            {(f.status === 'PENDENTE' || f.status === 'VENCIDA') && f.gateway === 'MERCADOPAGO' && (
                              <button
                                onClick={() => setPagamento(f)}
                                style={{
                                  padding: '5px 14px', borderRadius: 6, fontSize: 12, fontWeight: 700,
                                  background: 'var(--accent)', color: '#000', border: 'none', cursor: 'pointer',
                                  fontFamily: "'Barlow Condensed', sans-serif",
                                }}
                              >
                                Pagar
                              </button>
                            )}
                            {(f.status === 'PENDENTE' || f.status === 'VENCIDA') && f.gateway !== 'MERCADOPAGO' && (
                              <a
                                href={f.link_pagamento ?? '#'}
                                target="_blank"
                                rel="noopener noreferrer"
                                style={{
                                  display: 'inline-block', padding: '5px 14px', borderRadius: 6, fontSize: 12, fontWeight: 700,
                                  background: 'var(--accent)', color: '#000', textDecoration: 'none',
                                  opacity: f.link_pagamento ? 1 : 0.4,
                                  pointerEvents: f.link_pagamento ? 'auto' : 'none',
                                  fontFamily: "'Barlow Condensed', sans-serif",
                                }}
                                title={f.link_pagamento ? undefined : 'Link de pagamento indisponível — fale com o suporte'}
                              >
                                Pagar
                              </a>
                            )}
                            {f.status === 'PAGA' && (
                              <button
                                onClick={() => setDetalhe(f)}
                                style={{ padding: '5px 14px', borderRadius: 6, fontSize: 12, fontWeight: 700, background: 'none', border: '1px solid var(--success)', color: 'var(--success)', cursor: 'pointer' }}
                              >
                                Ver Detalhes
                              </button>
                            )}
                            {f.status === 'CANCELADA' && (
                              <span style={{ fontSize: 12, color: 'var(--muted)' }}>—</span>
                            )}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          </>
        )}
      </div>

      {/* Modal de detalhes do pagamento */}
      {detalhe && (
        <div
          onClick={e => { if (e.target === e.currentTarget) setDetalhe(null) }}
          style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.6)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000, padding: 20 }}
        >
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, padding: 28, width: '100%', maxWidth: 420 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 20 }}>
              <h3 className="font-display" style={{ fontSize: 18, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
                Detalhes do Pagamento
              </h3>
              <button onClick={() => setDetalhe(null)} style={{ background: 'none', border: 'none', color: 'var(--muted)', fontSize: 20, cursor: 'pointer', padding: 4, lineHeight: 1 }}>
                ✕
              </button>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
              {[
                ['Descrição', detalhe.descricao],
                ['Tipo', detalhe.tipo_label],
                ['Valor', formatarMoeda(Number(detalhe.valor))],
                ['Vencimento', formatarDataUTC(detalhe.vencimento)],
                ['Pago em', formatarDataHora(detalhe.pago_em)],
                ['Gateway', detalhe.gateway === 'MERCADOPAGO' ? 'Mercado Pago' : detalhe.gateway === 'ASAAS' ? 'Asaas' : '—'],
                ['ID do Pagamento', detalhe.id_pagamento ?? '—'],
              ].map(([label, value]) => (
                <div key={label} style={{ display: 'flex', justifyContent: 'space-between', padding: '8px 0', borderBottom: '1px solid var(--border)' }}>
                  <span style={{ fontSize: 13, color: 'var(--muted)' }}>{label}</span>
                  <span style={{ fontSize: 13, color: 'var(--text)', fontWeight: 500, textAlign: 'right', maxWidth: '60%', fontFamily: label === 'ID do Pagamento' ? "'JetBrains Mono', monospace" : undefined }}>
                    {value}
                  </span>
                </div>
              ))}
            </div>

            <button
              onClick={() => setDetalhe(null)}
              style={{ marginTop: 20, width: '100%', padding: '9px', background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 8, fontSize: 14, cursor: 'pointer' }}
            >
              Fechar
            </button>
          </div>
        </div>
      )}

      {pagamento && (
        <PagamentoTransparenteModal
          cobrancaId={pagamento.id}
          valor={Number(pagamento.valor)}
          descricao={pagamento.descricao}
          onClose={() => setPagamento(null)}
          onSuccess={() => { fetchFaturas() }}
        />
      )}
    </>
  )
}
