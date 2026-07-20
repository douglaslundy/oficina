'use client'
import { useEffect, useState } from 'react'
import api from '@/lib/api'
import { useAuth } from '@/hooks/useAuth'
import { PagamentoTransparenteModal } from './PagamentoTransparenteModal'

interface AlertaAssinatura {
  show: boolean
  fase?: 'DISPONIVEL' | 'VENCIDA'
  mensagem?: string
  cobranca_id?: string
  gateway?: 'ASAAS' | 'MERCADOPAGO'
  valor?: string
  vencimento?: string
  link_pagamento?: string | null
  ciclo_atual?: 'MENSAL' | 'ANUAL'
  desconto_anual_pct?: number
}

function fmtBRL(v: string): string {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v))
}

export function AssinaturaAlertaModal() {
  const { getUser } = useAuth()
  const [alerta, setAlerta] = useState<AlertaAssinatura | null>(null)
  const [visible, setVisible] = useState(false)
  const [mudandoCiclo, setMudandoCiclo] = useState(false)
  const [showPagamento, setShowPagamento] = useState(false)

  useEffect(() => {
    api.get<AlertaAssinatura>('/assinatura/alerta')
      .then(r => {
        if (r.data.show) {
          setAlerta(r.data)
          setVisible(true)
        }
      })
      .catch(() => { /* silencioso */ })
  }, [])

  async function trocarParaAnual() {
    if (!confirm(`Trocar sua assinatura para anual com ${alerta?.desconto_anual_pct}% de desconto?`)) return
    setMudandoCiclo(true)
    try {
      await api.post('/assinatura/mudar-ciclo', { ciclo: 'ANUAL' })
      setVisible(false)
    } catch {
      alert('Não foi possível trocar o ciclo agora. Tente novamente mais tarde.')
    } finally {
      setMudandoCiclo(false)
    }
  }

  if (!visible || !alerta?.show) return null

  const vencida = alerta.fase === 'VENCIDA'
  const isAdmin = getUser()?.role === 'ADMIN'
  const mostrarUpsell = isAdmin && alerta.ciclo_atual === 'MENSAL' && !!alerta.desconto_anual_pct

  if (showPagamento && alerta.cobranca_id) {
    return (
      <PagamentoTransparenteModal
        cobrancaId={alerta.cobranca_id}
        valor={Number(alerta.valor)}
        descricao={vencida ? 'Fatura vencida — MecânicaPro' : 'Mensalidade/Anuidade — MecânicaPro'}
        onClose={() => setShowPagamento(false)}
        onSuccess={() => setVisible(false)}
      />
    )
  }

  return (
    <div style={{
      position: 'fixed', inset: 0, background: 'rgba(0,0,0,.7)', zIndex: 2000,
      display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20,
    }}>
      <div style={{
        background: 'var(--card)', border: `1px solid ${vencida ? 'var(--danger)' : 'var(--border)'}`,
        borderRadius: 14, width: '100%', maxWidth: 480, padding: 32, position: 'relative',
      }}>
        <button
          onClick={() => setVisible(false)}
          style={{ position: 'absolute', top: 16, right: 16, background: 'none', border: 'none', color: 'var(--muted)', fontSize: 20, cursor: 'pointer', lineHeight: 1 }}
        >
          ✕
        </button>

        <div style={{ fontSize: 32, marginBottom: 12 }}>{vencida ? '⚠️' : '💳'}</div>
        <h2 className="font-display" style={{ fontSize: 22, fontWeight: 800, color: vencida ? 'var(--danger)' : 'var(--text)', margin: '0 0 12px' }}>
          {vencida ? 'Fatura vencida' : 'Pagamento disponível'}
        </h2>
        <p style={{ fontSize: 14, color: 'var(--text)', lineHeight: 1.6, margin: '0 0 8px' }}>
          {alerta.mensagem}
        </p>
        {alerta.valor && (
          <p style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 20, fontWeight: 700, color: 'var(--accent)', margin: '12px 0 20px' }}>
            {fmtBRL(alerta.valor)}
          </p>
        )}

        <div style={{ display: 'flex', gap: 10, marginBottom: mostrarUpsell ? 24 : 0 }}>
          {alerta.gateway === 'MERCADOPAGO' && alerta.cobranca_id ? (
            <button
              onClick={() => setShowPagamento(true)}
              style={{
                flex: 1, textAlign: 'center', padding: '11px', background: 'var(--accent)', color: '#000',
                borderRadius: 8, fontWeight: 700, fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif",
                border: 'none', cursor: 'pointer',
              }}
            >
              Pagar agora
            </button>
          ) : (
            <>
              <a
                href={alerta.link_pagamento ?? '#'}
                target="_blank"
                rel="noopener noreferrer"
                style={{
                  flex: 1, textAlign: 'center', padding: '11px', background: 'var(--accent)', color: '#000',
                  borderRadius: 8, fontWeight: 700, fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif",
                  textDecoration: 'none', opacity: alerta.link_pagamento ? 1 : 0.5,
                  pointerEvents: alerta.link_pagamento ? 'auto' : 'none',
                }}
              >
                Pagar com PIX
              </a>
              <a
                href={alerta.link_pagamento ?? '#'}
                target="_blank"
                rel="noopener noreferrer"
                style={{
                  flex: 1, textAlign: 'center', padding: '11px', background: 'transparent', border: '1px solid var(--accent)',
                  color: 'var(--accent)', borderRadius: 8, fontWeight: 700, fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif",
                  textDecoration: 'none', opacity: alerta.link_pagamento ? 1 : 0.5,
                  pointerEvents: alerta.link_pagamento ? 'auto' : 'none',
                }}
              >
                Pagar com Cartão
              </a>
            </>
          )}
        </div>

        {mostrarUpsell && (() => {
          const valorMensal = Number(alerta.valor)
          const pct = alerta.desconto_anual_pct ?? 0
          const valorAnualTotal = valorMensal * 12
          const valorAnualComDesconto = valorAnualTotal * (1 - pct / 100)
          const equivalenteMensalAnual = valorAnualComDesconto / 12
          const economia = valorAnualTotal - valorAnualComDesconto
          return (
          <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
            <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 12 }}>
              Economize trocando para o plano anual
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginBottom: 8 }}>
              <div style={{ border: '1px solid var(--border)', borderRadius: 10, padding: 14, textAlign: 'center' }}>
                <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 6 }}>Mensal (plano atual)</div>
                <div style={{ fontSize: 20, color: 'var(--text)', fontWeight: 800, fontFamily: "'JetBrains Mono', monospace" }}>{fmtBRL(String(valorMensal))}</div>
                <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2 }}>por mês</div>
                <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 6, borderTop: '1px solid var(--border)', paddingTop: 6 }}>
                  {fmtBRL(String(valorAnualTotal))}/ano
                </div>
              </div>
              <div style={{ border: '1px solid var(--accent)', background: 'rgba(245,166,35,.08)', borderRadius: 10, padding: 14, textAlign: 'center' }}>
                <div style={{ fontSize: 12, color: 'var(--accent)', marginBottom: 6, fontWeight: 700 }}>Anual (-{pct}%)</div>
                <div style={{ fontSize: 20, color: 'var(--accent)', fontWeight: 800, fontFamily: "'JetBrains Mono', monospace" }}>{fmtBRL(String(valorAnualComDesconto))}</div>
                <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2 }}>por ano</div>
                <div style={{ fontSize: 11, color: 'var(--accent)', marginTop: 6, borderTop: '1px solid rgba(245,166,35,.3)', paddingTop: 6 }}>
                  equivale a {fmtBRL(String(equivalenteMensalAnual))}/mês
                </div>
              </div>
            </div>
            <div style={{ textAlign: 'center', fontSize: 12, color: 'var(--success)', fontWeight: 700, marginBottom: 14 }}>
              Você economiza {fmtBRL(String(economia))} por ano
            </div>
            <button
              onClick={trocarParaAnual}
              disabled={mudandoCiclo}
              style={{
                width: '100%', padding: '10px', background: 'none', border: '1px solid var(--accent)',
                color: 'var(--accent)', borderRadius: 8, fontWeight: 700, fontSize: 13,
                cursor: mudandoCiclo ? 'not-allowed' : 'pointer', fontFamily: "'Barlow Condensed', sans-serif",
              }}
            >
              {mudandoCiclo ? 'Trocando…' : `Trocar para anual e economizar ${alerta.desconto_anual_pct}%`}
            </button>
          </div>
          )
        })()}
      </div>
    </div>
  )
}
