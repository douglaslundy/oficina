'use client'

import { useState, useEffect, useRef, useCallback } from 'react'
import { Payment, initMercadoPago } from '@mercadopago/sdk-react'
import api from '@/lib/api'
import { useAuth } from '@/hooks/useAuth'
import { formatarMoeda } from '@/lib/formatters'

interface Props {
  cobrancaId: string
  valor: number
  descricao: string
  onClose: () => void
  onSuccess: () => void
}

interface FormDataBrick {
  payment_method_id?: string
  token?: string
  issuer_id?: string
  installments?: number
  payer?: { email?: string; identification?: { type?: string; number?: string } }
}

let mpInicializado = false

type Tela = 'carregando' | 'form' | 'aguardando' | 'sucesso' | 'erro-config'

export function PagamentoTransparenteModal({ cobrancaId, valor, descricao, onClose, onSuccess }: Props) {
  const { getUser } = useAuth()
  const [tela, setTela] = useState<Tela>('carregando')
  const [publicKey, setPublicKey] = useState<string | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [pixData, setPixData] = useState<{ qrCode: string; qrCodeBase64: string } | null>(null)
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null)

  useEffect(() => {
    api.get<{ public_key: string }>('/pagamento/mercadopago/chave-publica')
      .then(res => {
        if (!mpInicializado) {
          initMercadoPago(res.data.public_key, { locale: 'pt-BR' })
          mpInicializado = true
        }
        setPublicKey(res.data.public_key)
        setTela('form')
      })
      .catch((e: unknown) => {
        const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Mercado Pago não está configurado nesta plataforma.'
        setErro(msg)
        setTela('erro-config')
      })
  }, [])

  useEffect(() => () => { if (pollRef.current) clearInterval(pollRef.current) }, [])

  const iniciarPolling = useCallback(() => {
    if (pollRef.current) clearInterval(pollRef.current)
    pollRef.current = setInterval(async () => {
      try {
        const res = await api.get<{ status: string }>(`/pagamento/faturas/${cobrancaId}/status`)
        if (res.data.status === 'PAGA') {
          if (pollRef.current) clearInterval(pollRef.current)
          setTela('sucesso')
          onSuccess()
        }
      } catch {
        // tenta de novo no próximo tick
      }
    }, 5000)
  }, [cobrancaId, onSuccess])

  async function handleSubmit(param: { formData: FormDataBrick }): Promise<void> {
    setErro(null)
    const formData = param.formData

    try {
      const res = await api.post<{ status: string; status_detail?: string; qr_code?: string; qr_code_base64?: string }>(
        '/pagamento/mercadopago',
        { cobranca_id: cobrancaId, ...formData },
      )
      const { status, qr_code, qr_code_base64, status_detail } = res.data

      if (status === 'approved') {
        setTela('sucesso')
        onSuccess()
        return
      }

      if (formData.payment_method_id === 'pix' && qr_code_base64) {
        setPixData({ qrCode: qr_code ?? '', qrCodeBase64: qr_code_base64 })
        setTela('aguardando')
        iniciarPolling()
        return
      }

      if (status === 'pending' || status === 'in_process') {
        setPixData(null)
        setTela('aguardando')
        iniciarPolling()
        return
      }

      throw new Error(status_detail ? `Pagamento recusado (${status_detail}).` : 'Pagamento recusado. Tente outro cartão ou meio de pagamento.')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } }; message?: string })?.response?.data?.message
        ?? (e as Error)?.message
        ?? 'Falha ao processar pagamento.'
      setErro(msg)
      throw e
    }
  }

  function copiarPix() {
    if (pixData?.qrCode) {
      navigator.clipboard?.writeText(pixData.qrCode).catch(() => {})
    }
  }

  const podeFecharClicandoFora = tela !== 'form'

  return (
    <div
      onClick={e => { if (e.target === e.currentTarget && podeFecharClicandoFora) onClose() }}
      style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.7)', zIndex: 2000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}
    >
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 14, width: '100%', maxWidth: 480, maxHeight: 'calc(100vh - 48px)', overflowY: 'auto', padding: 28, position: 'relative' }}>
        <button onClick={onClose} style={{ position: 'absolute', top: 16, right: 16, background: 'none', border: 'none', color: 'var(--muted)', fontSize: 20, cursor: 'pointer', lineHeight: 1 }}>
          ✕
        </button>

        <h2 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: '0 0 4px' }}>Pagamento</h2>
        <p style={{ fontSize: 13, color: 'var(--muted)', margin: '0 0 20px' }}>{descricao} · {formatarMoeda(valor)}</p>

        {erro && tela === 'form' && (
          <div style={{ background: 'rgba(229,57,53,.1)', border: '1px solid var(--danger)', borderRadius: 8, padding: '10px 14px', color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>
            {erro}
          </div>
        )}

        {tela === 'carregando' && (
          <p style={{ color: 'var(--muted)', fontSize: 14, textAlign: 'center', padding: '32px 0' }}>Carregando…</p>
        )}

        {tela === 'erro-config' && (
          <p style={{ color: 'var(--danger)', fontSize: 14, textAlign: 'center', padding: '32px 0' }}>{erro}</p>
        )}

        {tela === 'form' && publicKey && (
          <Payment
            initialization={{ amount: valor, payer: { email: getUser()?.email } }}
            customization={{
              paymentMethods: {
                creditCard: 'all',
                debitCard: 'all',
                bankTransfer: 'all',
              } as never,
            }}
            onSubmit={handleSubmit}
            onError={(e) => setErro(e?.message ?? 'Erro no formulário de pagamento.')}
          />
        )}

        {tela === 'aguardando' && pixData && (
          <div style={{ textAlign: 'center' }}>
            <p style={{ fontSize: 13, color: 'var(--text)', marginBottom: 14 }}>Escaneie o QR Code com o app do seu banco:</p>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={`data:image/png;base64,${pixData.qrCodeBase64}`} alt="QR Code PIX" style={{ width: 220, height: 220, margin: '0 auto', borderRadius: 8, background: '#fff', padding: 8 }} />
            <button onClick={copiarPix} style={{ marginTop: 14, padding: '8px 18px', background: 'none', border: '1px solid var(--accent)', color: 'var(--accent)', borderRadius: 8, fontSize: 13, fontWeight: 700, cursor: 'pointer' }}>
              Copiar código PIX
            </button>
            <p style={{ fontSize: 12, color: 'var(--muted)', marginTop: 14 }}>Aguardando confirmação do pagamento…</p>
          </div>
        )}

        {tela === 'aguardando' && !pixData && (
          <p style={{ color: 'var(--muted)', fontSize: 14, textAlign: 'center', padding: '32px 0' }}>Pagamento em processamento. Aguardando confirmação…</p>
        )}

        {tela === 'sucesso' && (
          <div style={{ textAlign: 'center', padding: '24px 0' }}>
            <div style={{ fontSize: 40, marginBottom: 12 }}>✅</div>
            <p style={{ fontSize: 16, fontWeight: 700, color: 'var(--success)', margin: 0 }}>Pagamento confirmado!</p>
            <button onClick={onClose} style={{ marginTop: 20, padding: '9px 24px', background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 8, fontWeight: 700, fontSize: 14, cursor: 'pointer' }}>
              Fechar
            </button>
          </div>
        )}
      </div>
    </div>
  )
}
