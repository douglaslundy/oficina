'use client'
import { useEffect, useState } from 'react'
import { useRouter } from 'next/navigation'
import api from '@/lib/api'
import { useAuth } from '@/hooks/useAuth'
import { PagamentoTransparenteModal } from '@/components/PagamentoTransparenteModal'

interface StatusBloqueio {
  suspensa: boolean
  fase?: 'DISPONIVEL' | 'VENCIDA'
  mensagem?: string
  cobranca_id?: string
  gateway?: 'ASAAS' | 'MERCADOPAGO'
  valor?: string
  vencimento?: string
  link_pagamento?: string | null
  voto_confianca_disponivel: boolean
}

function fmtBRL(v: string): string {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v))
}

export default function BloqueadoPage() {
  const router = useRouter()
  const { getUser } = useAuth()
  const [status, setStatus] = useState<StatusBloqueio | null>(null)
  const [loading, setLoading] = useState(true)
  const [liberando, setLiberando] = useState(false)
  const [liberado, setLiberado] = useState<string | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [showPagamento, setShowPagamento] = useState(false)

  useEffect(() => {
    api.get<StatusBloqueio>('/assinatura/status-bloqueio')
      .then(r => {
        if (!r.data.suspensa) {
          router.push('/')
          return
        }
        setStatus(r.data)
      })
      .catch(() => router.push('/'))
      .finally(() => setLoading(false))
  }, [router])

  async function liberarVotoConfianca() {
    if (!confirm('Deseja liberar seu acesso em voto de confiança?')) return
    setLiberando(true)
    setErro(null)
    try {
      const res = await api.post<{ message: string }>('/assinatura/voto-confianca')
      setLiberado(res.data.message)
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      setErro(msg ?? 'Não foi possível liberar o acesso agora.')
    } finally {
      setLiberando(false)
    }
  }

  if (loading || !status) {
    return (
      <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'var(--bg)', color: 'var(--muted)' }}>
        Carregando...
      </div>
    )
  }

  const isAdmin = getUser()?.role === 'ADMIN'

  return (
    <>
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'var(--bg)', padding: 20 }}>
      <div style={{ background: 'var(--card)', border: '1px solid var(--danger)', borderRadius: 14, width: '100%', maxWidth: 480, padding: 36, textAlign: 'center' }}>
        <div style={{ fontSize: 40, marginBottom: 16 }}>🔒</div>
        <h1 className="font-display" style={{ fontSize: 24, fontWeight: 800, color: 'var(--danger)', margin: '0 0 12px' }}>
          Acesso Bloqueado
        </h1>
        <p style={{ fontSize: 14, color: 'var(--text)', lineHeight: 1.6, margin: '0 0 8px' }}>
          {status.mensagem}
        </p>
        {status.valor && (
          <p style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 22, fontWeight: 700, color: 'var(--accent)', margin: '12px 0 24px' }}>
            {fmtBRL(status.valor)}
          </p>
        )}

        {!liberado && status.gateway === 'MERCADOPAGO' && status.cobranca_id && (
          <div style={{ marginBottom: 24 }}>
            <button onClick={() => setShowPagamento(true)}
              style={{ width: '100%', padding: '12px', background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 8, fontWeight: 700, fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif", cursor: 'pointer' }}>
              Pagar agora
            </button>
          </div>
        )}
        {!liberado && status.gateway !== 'MERCADOPAGO' && (
          <div style={{ display: 'flex', gap: 10, marginBottom: 24 }}>
            <a href={status.link_pagamento ?? '#'} target="_blank" rel="noopener noreferrer"
              style={{ flex: 1, textAlign: 'center', padding: '12px', background: 'var(--accent)', color: '#000', borderRadius: 8, fontWeight: 700, fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif", textDecoration: 'none', opacity: status.link_pagamento ? 1 : 0.5, pointerEvents: status.link_pagamento ? 'auto' : 'none' }}>
              Pagar com PIX
            </a>
            <a href={status.link_pagamento ?? '#'} target="_blank" rel="noopener noreferrer"
              style={{ flex: 1, textAlign: 'center', padding: '12px', background: 'transparent', border: '1px solid var(--accent)', color: 'var(--accent)', borderRadius: 8, fontWeight: 700, fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif", textDecoration: 'none', opacity: status.link_pagamento ? 1 : 0.5, pointerEvents: status.link_pagamento ? 'auto' : 'none' }}>
              Pagar com Cartão
            </a>
          </div>
        )}

        {erro && (
          <p style={{ fontSize: 13, color: 'var(--danger)', marginBottom: 16 }}>{erro}</p>
        )}

        {liberado ? (
          <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
            <p style={{ fontSize: 14, color: 'var(--success)', fontWeight: 600, marginBottom: 16 }}>{liberado}</p>
            <button onClick={() => router.push('/')}
              style={{ width: '100%', padding: '11px', background: 'var(--success)', color: '#000', border: 'none', borderRadius: 8, fontWeight: 700, fontSize: 14, cursor: 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
              Voltar ao sistema
            </button>
          </div>
        ) : isAdmin && status.voto_confianca_disponivel ? (
          <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
            <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 12 }}>
              Deseja liberar seu acesso em voto de confiança enquanto regulariza o pagamento?
            </p>
            <button onClick={liberarVotoConfianca} disabled={liberando}
              style={{ width: '100%', padding: '11px', background: 'none', border: '1px solid var(--accent)', color: 'var(--accent)', borderRadius: 8, fontWeight: 700, fontSize: 14, cursor: liberando ? 'not-allowed' : 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
              {liberando ? 'Liberando…' : 'Liberar em voto de confiança'}
            </button>
          </div>
        ) : isAdmin ? (
          <p style={{ fontSize: 13, color: 'var(--muted)', borderTop: '1px solid var(--border)', paddingTop: 20 }}>
            Voto de confiança já utilizado para esta fatura.
          </p>
        ) : null}
      </div>
    </div>

    {showPagamento && status.cobranca_id && (
      <PagamentoTransparenteModal
        cobrancaId={status.cobranca_id}
        valor={Number(status.valor)}
        descricao="Regularização de fatura — MecânicaPro"
        onClose={() => setShowPagamento(false)}
        onSuccess={() => router.push('/')}
      />
    )}
    </>
  )
}
