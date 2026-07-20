'use client'

import { useState, useEffect, useRef, useCallback } from 'react'
import {
  initMercadoPago,
  CardNumber,
  ExpirationDate,
  SecurityCode,
  createCardToken,
  getPaymentMethods,
  getInstallments,
} from '@mercadopago/sdk-react'
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

interface PagamentoPayload {
  payment_method_id: string
  token?: string
  issuer_id?: string
  installments?: number
  payer: { email: string; identification: { type: string; number: string } }
}

interface Parcela {
  installments: number
  recommended_message: string
}

let mpInicializado = false

type Tela = 'carregando' | 'form' | 'aguardando' | 'sucesso' | 'erro-config'
type Metodo = 'cartao' | 'pix'

// Campos de cartão/CVV/validade são iframes de outra origem (secure fields
// da MP) — o navegador não tem como oferecer autofill/salvar esses dados
// porque eles nunca entram no DOM da nossa página. Nome e CPF são inputs
// nossos; autoComplete="off" + nomes não-convencionais evitam sugestão do
// navegador nesses.
const fieldStyle = {
  color: '#e8eaf0',
  fontSize: '14px',
  'placeholder-color': '#5a6070',
} as const

const secureFieldWrapStyle: React.CSSProperties = {
  background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8,
  padding: '9px 12px', height: 20,
}

const inputStyle: React.CSSProperties = {
  width: '100%', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8,
  color: 'var(--text)', fontSize: 14, padding: '9px 12px', outline: 'none', boxSizing: 'border-box',
}

const labelStyle: React.CSSProperties = {
  fontSize: 12, fontWeight: 600, color: 'var(--muted)', display: 'block', marginBottom: 6,
}

export function PagamentoTransparenteModal({ cobrancaId, valor, descricao, onClose, onSuccess }: Props) {
  const { getUser } = useAuth()
  const [tela, setTela] = useState<Tela>('carregando')
  const [metodo, setMetodo] = useState<Metodo>('cartao')
  const [erro, setErro] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)
  const [pixData, setPixData] = useState<{ qrCode: string; qrCodeBase64: string } | null>(null)
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null)

  const [nome, setNome] = useState('')
  const [cpf, setCpf] = useState('')
  const [paymentMethodId, setPaymentMethodId] = useState<string | null>(null)
  const [issuerId, setIssuerId] = useState<string | null>(null)
  const [parcelas, setParcelas] = useState<Parcela[]>([])
  const [parcelaEscolhida, setParcelaEscolhida] = useState<number>(1)

  useEffect(() => {
    api.get<{ public_key: string }>('/pagamento/mercadopago/chave-publica')
      .then(res => {
        if (!mpInicializado) {
          initMercadoPago(res.data.public_key, { locale: 'pt-BR' })
          mpInicializado = true
        }
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

  async function handleBinChange(arg: { bin?: string }) {
    const bin = arg.bin ?? null
    setParcelas([])
    setIssuerId(null)
    setPaymentMethodId(null)
    if (!bin) return

    try {
      const metodos = await getPaymentMethods({ bin })
      const encontrado = metodos?.results?.[0]
      if (!encontrado) return
      setPaymentMethodId(encontrado.id)
      setIssuerId(String(encontrado.issuer?.id ?? ''))

      const installments = await getInstallments({ amount: String(valor), bin, paymentMethodId: encontrado.id })
      const opcoes = installments?.[0]?.payer_costs ?? []
      setParcelas(opcoes.map(p => ({ installments: p.installments, recommended_message: p.recommended_message })))
      setParcelaEscolhida(1)
    } catch {
      // silencioso — se a bandeira não for detectada, o backend ainda valida no envio
    }
  }

  function extrairErro(e: unknown, fallback: string): string {
    return (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      ?? (e as Error)?.message
      ?? fallback
  }

  async function enviarPagamento(dados: PagamentoPayload) {
    const res = await api.post<{ status: string; status_detail?: string; qr_code?: string; qr_code_base64?: string }>(
      '/pagamento/mercadopago',
      { cobranca_id: cobrancaId, ...dados },
    )
    const { status, qr_code, qr_code_base64, status_detail } = res.data

    if (status === 'approved') {
      setTela('sucesso')
      onSuccess()
      return
    }

    if (dados.payment_method_id === 'pix' && qr_code_base64) {
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

    throw new Error(status_detail ? `Pagamento recusado (${status_detail}).` : 'Pagamento recusado. Confira os dados ou tente outro cartão.')
  }

  async function pagarComCartao() {
    setErro(null)
    if (!nome.trim()) { setErro('Informe o nome do titular do cartão.'); return }
    const cpfDigits = cpf.replace(/\D/g, '')
    if (cpfDigits.length !== 11) { setErro('Informe um CPF válido do titular.'); return }
    if (!paymentMethodId) { setErro('Não foi possível identificar a bandeira do cartão. Confira o número.'); return }

    setEnviando(true)
    try {
      const token = await createCardToken({
        cardholderName: nome.trim(),
        identificationType: 'CPF',
        identificationNumber: cpfDigits,
      })
      if (!token?.id) throw new Error('Não foi possível validar o cartão. Confira os dados e tente novamente.')

      await enviarPagamento({
        token: token.id,
        payment_method_id: paymentMethodId,
        issuer_id: issuerId ?? undefined,
        installments: parcelaEscolhida,
        payer: { email: getUser()?.email ?? '', identification: { type: 'CPF', number: cpfDigits } },
      })
    } catch (e: unknown) {
      setErro(extrairErro(e, 'Falha ao processar o cartão.'))
    } finally {
      setEnviando(false)
    }
  }

  async function pagarComPix() {
    setErro(null)
    const cpfDigits = cpf.replace(/\D/g, '')
    if (cpfDigits.length !== 11) { setErro('Informe um CPF válido para gerar o PIX.'); return }

    setEnviando(true)
    try {
      await enviarPagamento({
        payment_method_id: 'pix',
        payer: { email: getUser()?.email ?? '', identification: { type: 'CPF', number: cpfDigits } },
      })
    } catch (e: unknown) {
      setErro(extrairErro(e, 'Falha ao gerar o PIX.'))
    } finally {
      setEnviando(false)
    }
  }

  function copiarPix() {
    if (pixData?.qrCode) navigator.clipboard?.writeText(pixData.qrCode).catch(() => {})
  }

  const podeFecharClicandoFora = tela !== 'form'

  return (
    <div
      onClick={e => { if (e.target === e.currentTarget && podeFecharClicandoFora) onClose() }}
      style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.7)', zIndex: 2000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}
    >
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 14, width: '100%', maxWidth: 440, maxHeight: 'calc(100vh - 48px)', overflowY: 'auto', padding: 28, position: 'relative' }}>
        <button onClick={onClose} style={{ position: 'absolute', top: 16, right: 16, background: 'none', border: 'none', color: 'var(--muted)', fontSize: 20, cursor: 'pointer', lineHeight: 1 }}>
          ✕
        </button>

        <h2 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: '0 0 4px' }}>Pagamento</h2>
        <p style={{ fontSize: 13, color: 'var(--muted)', margin: '0 0 20px' }}>{descricao} · {formatarMoeda(valor)}</p>

        {tela === 'carregando' && (
          <p style={{ color: 'var(--muted)', fontSize: 14, textAlign: 'center', padding: '32px 0' }}>Carregando…</p>
        )}

        {tela === 'erro-config' && (
          <p style={{ color: 'var(--danger)', fontSize: 14, textAlign: 'center', padding: '32px 0' }}>{erro}</p>
        )}

        {tela === 'form' && (
          <>
            <div style={{ display: 'flex', gap: 0, border: '1px solid var(--border)', borderRadius: 8, overflow: 'hidden', marginBottom: 20 }}>
              {(['cartao', 'pix'] as Metodo[]).map(m => (
                <button key={m} type="button" onClick={() => { setMetodo(m); setErro(null) }}
                  style={{
                    flex: 1, padding: '9px', fontSize: 13, fontWeight: 700, cursor: 'pointer', border: 'none',
                    background: metodo === m ? 'var(--accent)' : 'transparent',
                    color: metodo === m ? '#000' : 'var(--muted)',
                    fontFamily: "'Barlow Condensed', sans-serif",
                  }}>
                  {m === 'cartao' ? 'Cartão de Crédito' : 'PIX'}
                </button>
              ))}
            </div>

            {erro && (
              <div style={{ background: 'rgba(229,57,53,.1)', border: '1px solid var(--danger)', borderRadius: 8, padding: '10px 14px', color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>
                {erro}
              </div>
            )}

            <div style={{ marginBottom: 14 }}>
              <label style={labelStyle}>CPF do titular</label>
              <input
                value={cpf}
                onChange={e => setCpf(e.target.value)}
                placeholder="000.000.000-00"
                autoComplete="off"
                autoCorrect="off"
                spellCheck={false}
                name="mp-titular-cpf"
                style={inputStyle}
              />
            </div>

            {metodo === 'cartao' && (
              <>
                <div style={{ marginBottom: 14 }}>
                  <label style={labelStyle}>Nome do titular</label>
                  <input
                    value={nome}
                    onChange={e => setNome(e.target.value.toUpperCase())}
                    placeholder="COMO ESTÁ NO CARTÃO"
                    autoComplete="off"
                    autoCorrect="off"
                    spellCheck={false}
                    name="mp-titular-nome"
                    style={inputStyle}
                  />
                </div>

                <div style={{ marginBottom: 14 }}>
                  <label style={labelStyle}>Número do cartão</label>
                  <div style={secureFieldWrapStyle}>
                    <CardNumber placeholder="•••• •••• •••• ••••" style={fieldStyle} onBinChange={handleBinChange} />
                  </div>
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 14 }}>
                  <div>
                    <label style={labelStyle}>Validade</label>
                    <div style={secureFieldWrapStyle}>
                      <ExpirationDate placeholder="MM/AA" style={fieldStyle} />
                    </div>
                  </div>
                  <div>
                    <label style={labelStyle}>CVV</label>
                    <div style={secureFieldWrapStyle}>
                      <SecurityCode placeholder="•••" style={fieldStyle} />
                    </div>
                  </div>
                </div>

                {parcelas.length > 0 && (
                  <div style={{ marginBottom: 14 }}>
                    <label style={labelStyle}>Parcelas</label>
                    <select value={parcelaEscolhida} onChange={e => setParcelaEscolhida(Number(e.target.value))} style={inputStyle}>
                      {parcelas.map(p => (
                        <option key={p.installments} value={p.installments}>{p.recommended_message}</option>
                      ))}
                    </select>
                  </div>
                )}

                <button
                  onClick={pagarComCartao}
                  disabled={enviando}
                  style={{ width: '100%', padding: '11px', background: enviando ? 'var(--border)' : 'var(--accent)', color: enviando ? 'var(--muted)' : '#000', border: 'none', borderRadius: 8, fontWeight: 700, fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif", cursor: enviando ? 'not-allowed' : 'pointer' }}
                >
                  {enviando ? 'Processando…' : `Pagar ${formatarMoeda(valor)}`}
                </button>
              </>
            )}

            {metodo === 'pix' && (
              <button
                onClick={pagarComPix}
                disabled={enviando}
                style={{ width: '100%', padding: '11px', background: enviando ? 'var(--border)' : 'var(--accent)', color: enviando ? 'var(--muted)' : '#000', border: 'none', borderRadius: 8, fontWeight: 700, fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif", cursor: enviando ? 'not-allowed' : 'pointer' }}
              >
                {enviando ? 'Gerando…' : 'Gerar QR Code PIX'}
              </button>
            )}
          </>
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
