'use client'

import { useState, useEffect, useCallback, useRef } from 'react'
import Link from 'next/link'
import saasApi from '@/lib/saas-api'

interface AdminWaConfig {
  numero: string | null
  ativo: boolean
  instance_name: string
}

interface InstanceStatus {
  status: string
  number: string | null
}

type ToastType = 'success' | 'danger'

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

const iStyle: React.CSSProperties = {
  width: '100%', padding: '9px 12px', borderRadius: 8,
  background: 'var(--bg)', border: '1px solid var(--border)',
  color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box',
}
const lStyle: React.CSSProperties = {
  display: 'block', fontSize: 12, fontWeight: 600,
  color: 'var(--muted)', textTransform: 'uppercase' as const,
  letterSpacing: '0.05em', marginBottom: 6,
}

function StatusChip({ status }: { status: string }) {
  const map: Record<string, { color: string; label: string }> = {
    open:         { color: 'var(--success)', label: '● Conectado' },
    connecting:   { color: 'var(--accent)',  label: '◌ Conectando...' },
    close:        { color: 'var(--danger)',  label: '● Desconectado' },
    disconnected: { color: 'var(--danger)',  label: '● Desconectado' },
    unknown:      { color: 'var(--muted)',   label: '? Desconhecido' },
  }
  const m = map[status] ?? map.unknown
  return <span style={{ fontSize: 13, fontWeight: 700, color: m.color }}>{m.label}</span>
}

export default function AdminWhatsAppPage() {
  const [loading, setLoading]             = useState(true)
  const [saving, setSaving]               = useState(false)
  const [disconnecting, setDisconnecting] = useState(false)
  const [status, setStatus]               = useState<InstanceStatus>({ status: 'unknown', number: null })
  const [qrCode, setQrCode]               = useState<string | null>(null)
  const [showQr, setShowQr]               = useState(false)
  const [sendingTest, setSendingTest]     = useState(false)
  const [credenciaisOk, setCredenciaisOk] = useState(false)
  const [config, setConfig]               = useState<AdminWaConfig | null>(null)
  const [ativo, setAtivo]                 = useState(false)
  const [numero, setNumero]               = useState('')
  const [toast, setToast]                 = useState<{ msg: string; type: ToastType } | null>(null)
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null)

  const showToast = useCallback((msg: string, type: ToastType) => setToast({ msg, type }), [])

  const fetchStatus = useCallback(async () => {
    try {
      const r = await saasApi.get<InstanceStatus>('/saas/config/whatsapp-admin/status')
      setStatus(r.data)
      if (r.data.status === 'open' && showQr) {
        setShowQr(false)
        setQrCode(null)
        showToast('WhatsApp do admin conectado com sucesso!', 'success')
        if (pollRef.current) clearInterval(pollRef.current)
      }
    } catch { /* silencioso */ }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [showQr])

  useEffect(() => {
    saasApi.get<{ data: AdminWaConfig; credenciais_ok: boolean }>('/saas/config/whatsapp-admin').then(r => {
      setCredenciaisOk(r.data.credenciais_ok)
      setConfig(r.data.data)
      setAtivo(r.data.data.ativo)
      setNumero(r.data.data.numero ?? '')
    }).catch(() => {}).finally(() => setLoading(false))

    fetchStatus()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    if (showQr) {
      pollRef.current = setInterval(fetchStatus, 4000)
    } else if (pollRef.current) {
      clearInterval(pollRef.current)
    }
    return () => { if (pollRef.current) clearInterval(pollRef.current) }
  }, [showQr, fetchStatus])

  async function salvar() {
    setSaving(true)
    try {
      await saasApi.put('/saas/config/whatsapp-admin', { numero, ativo })
      setConfig(c => c ? { ...c, numero, ativo } : c)
      showToast('Configuração salva!', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao salvar.', 'danger')
    } finally { setSaving(false) }
  }

  async function verQrCode() {
    if (!credenciaisOk) {
      showToast('A Evolution API ainda não foi configurada. Configure em Configurações → Evolution API primeiro.', 'danger')
      return
    }
    try {
      const r = await saasApi.get<{ qrcode: string }>('/saas/config/whatsapp-admin/qrcode')
      setQrCode(r.data.qrcode)
      setShowQr(true)
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao gerar o QR code.', 'danger')
    }
  }

  async function desconectar() {
    if (!window.confirm('Desconectar a sessão do WhatsApp do admin? Será necessário escanear o QR code novamente para reconectar.')) return
    setDisconnecting(true)
    try {
      await saasApi.post('/saas/config/whatsapp-admin/desconectar', {})
      showToast('Sessão desconectada.', 'success')
      setStatus({ status: 'close', number: null })
      fetchStatus()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { error?: string } } })?.response?.data?.error
      showToast(msg ?? 'Erro ao desconectar.', 'danger')
    } finally { setDisconnecting(false) }
  }

  async function enviarTeste() {
    if (!numero || numero.replace(/\D/g, '').length < 10) {
      showToast('Informe e salve um número válido com DDD antes de testar.', 'danger')
      return
    }
    setSendingTest(true)
    try {
      await saasApi.post('/saas/config/whatsapp-admin/enviar-teste', {})
      showToast('Mensagem de teste enviada! Verifique o WhatsApp do número configurado.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { error?: string } } })?.response?.data?.error
      showToast(msg ?? 'Erro ao enviar a mensagem de teste.', 'danger')
    } finally { setSendingTest(false) }
  }

  if (loading) return <div style={{ padding: 40, color: 'var(--muted)' }}>Carregando...</div>

  return (
    <div style={{ maxWidth: 680, margin: '32px auto', padding: '0 24px', color: 'var(--text)' }}>
      {toast && <Toast msg={toast.msg} type={toast.type} onClose={() => setToast(null)} />}

      <div style={{ marginBottom: 8 }}>
        <Link href="/saas-admin/configuracoes" style={{ fontSize: 13, color: 'var(--muted)', textDecoration: 'none' }}>
          ← Configurações
        </Link>
      </div>
      <div style={{ marginBottom: 28 }}>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, margin: 0 }}>
          WhatsApp do Admin
        </h1>
        <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
          Instância própria da plataforma — usada só pra notificar você (ex: pagamento recebido). Não é a instância de nenhuma oficina.
        </p>
      </div>

      {!credenciaisOk && (
        <div style={{ marginBottom: 20, padding: '12px 16px', background: 'rgba(229,57,53,.08)', border: '1px solid rgba(229,57,53,.25)', borderRadius: 8, fontSize: 13, color: 'var(--danger)' }}>
          ⚠️ A Evolution API ainda não foi configurada. Configure URL e API Key em <Link href="/saas-admin/configuracoes" style={{ color: 'var(--danger)' }}>Configurações → Evolution API</Link> primeiro.
        </div>
      )}

      {/* Status card */}
      <div style={{
        background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10,
        padding: '16px 20px', marginBottom: 20, display: 'flex', alignItems: 'center', justifyContent: 'space-between',
        flexWrap: 'wrap', gap: 12,
      }}>
        <div>
          <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 4, textTransform: 'uppercase', letterSpacing: '0.05em' }}>Status da Instância</div>
          <StatusChip status={status.status} />
          {status.number && (
            <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4 }}>
              📱 {status.number.replace('@s.whatsapp.net', '')}
            </div>
          )}
          {config?.instance_name && (
            <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2 }}>
              Instância: <code style={{ fontFamily: 'monospace' }}>{config.instance_name}</code>
            </div>
          )}
        </div>
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
          {status.status !== 'open' && (
            <button onClick={verQrCode} disabled={!credenciaisOk}
              style={{ padding: '8px 16px', borderRadius: 7, background: credenciaisOk ? 'var(--accent)' : 'var(--border)', color: credenciaisOk ? '#000' : 'var(--muted)', border: 'none', fontSize: 13, fontWeight: 700, cursor: credenciaisOk ? 'pointer' : 'not-allowed' }}>
              📷 Escanear QR Code
            </button>
          )}
          {status.status === 'open' && (
            <button onClick={desconectar} disabled={disconnecting}
              style={{ padding: '8px 16px', borderRadius: 7, background: 'transparent', border: '1px solid var(--danger)', color: 'var(--danger)', fontSize: 13, fontWeight: 700, cursor: disconnecting ? 'not-allowed' : 'pointer' }}>
              {disconnecting ? '⟳ Desconectando...' : '⏻ Desconectar'}
            </button>
          )}
          <button onClick={fetchStatus}
            style={{ padding: '8px 14px', borderRadius: 7, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', fontSize: 13, cursor: 'pointer' }}>
            ↻ Atualizar
          </button>
        </div>
      </div>

      {/* QR Code Modal */}
      {showQr && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.7)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 14, padding: 32, textAlign: 'center', maxWidth: 380 }}>
            <h3 className="font-display" style={{ fontSize: 20, fontWeight: 800, marginBottom: 8 }}>Escanear QR Code</h3>
            <p style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 20 }}>
              Abra o WhatsApp do número que vai enviar as notificações → Dispositivos vinculados → Vincular um dispositivo
            </p>
            {qrCode ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={qrCode.startsWith('data:') ? qrCode : `data:image/png;base64,${qrCode}`}
                alt="QR Code WhatsApp"
                style={{ width: 240, height: 240, borderRadius: 8, background: '#fff', padding: 8 }}
              />
            ) : (
              <div style={{ width: 240, height: 240, margin: '0 auto', background: 'var(--bg)', borderRadius: 8, display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--muted)' }}>
                Carregando QR...
              </div>
            )}
            <p style={{ fontSize: 12, color: 'var(--muted)', marginTop: 12 }}>
              ◌ Aguardando conexão... (atualiza automaticamente)
            </p>
            <button onClick={() => { setShowQr(false); setQrCode(null) }}
              style={{ marginTop: 16, padding: '8px 20px', borderRadius: 7, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer', fontSize: 13 }}>
              Fechar
            </button>
          </div>
        </div>
      )}

      {/* Config: número de destino + ativo */}
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
        <div style={{ padding: '14px 20px', borderBottom: '1px solid var(--border)' }}>
          <div style={{ fontSize: 15, fontWeight: 700 }}>Notificações</div>
          <div style={{ fontSize: 13, color: 'var(--muted)', marginTop: 2 }}>
            Número (com DDD) que vai RECEBER as notificações, enviadas pelo número conectado acima
          </div>
        </div>
        <div style={{ padding: 24, display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div>
            <label style={lStyle}>Número de destino</label>
            <input
              value={numero}
              onChange={e => setNumero(e.target.value)}
              placeholder="11999998888"
              style={iStyle}
            />
          </div>

          <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}>
            <input
              type="checkbox"
              checked={ativo}
              onChange={e => setAtivo(e.target.checked)}
              style={{ width: 16, height: 16, accentColor: 'var(--accent)', cursor: 'pointer' }}
            />
            <span style={{ fontSize: 14, fontWeight: 600 }}>Ativar notificações de pagamento via WhatsApp</span>
          </label>

          <div style={{ display: 'flex', justifyContent: 'flex-end', paddingTop: 8, borderTop: '1px solid var(--border)' }}>
            <button onClick={salvar} disabled={saving}
              style={{ padding: '9px 24px', borderRadius: 7, background: saving ? 'var(--border)' : 'var(--accent)', color: saving ? 'var(--muted)' : '#000', border: 'none', fontSize: 14, fontWeight: 700, cursor: saving ? 'not-allowed' : 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
              {saving ? '⟳ Salvando...' : 'Salvar'}
            </button>
          </div>
        </div>
      </div>

      {/* Enviar mensagem de teste */}
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden', marginTop: 20 }}>
        <div style={{ padding: '14px 20px', borderBottom: '1px solid var(--border)' }}>
          <div style={{ fontSize: 15, fontWeight: 700 }}>Enviar mensagem de teste</div>
          <div style={{ fontSize: 13, color: 'var(--muted)', marginTop: 2 }}>
            Envia para o número salvo acima — confirme que já clicou em "Salvar"
          </div>
        </div>
        <div style={{ padding: 24 }}>
          {status.status !== 'open' && (
            <div style={{ marginBottom: 14, padding: '10px 14px', background: 'rgba(245,166,35,.08)', border: '1px solid rgba(245,166,35,.25)', borderRadius: 7, fontSize: 13, color: 'var(--accent)' }}>
              ⚠️ O WhatsApp precisa estar conectado para enviar mensagens. Escaneie o QR code primeiro.
            </div>
          )}
          <button onClick={enviarTeste} disabled={sendingTest || status.status !== 'open'}
            style={{ padding: '9px 22px', borderRadius: 7, border: 'none', whiteSpace: 'nowrap',
              background: (sendingTest || status.status !== 'open') ? 'var(--border)' : 'var(--success)',
              color: (sendingTest || status.status !== 'open') ? 'var(--muted)' : '#fff',
              fontSize: 14, fontWeight: 700, cursor: (sendingTest || status.status !== 'open') ? 'not-allowed' : 'pointer' }}>
            {sendingTest ? '⟳ Enviando...' : '📨 Enviar teste'}
          </button>
        </div>
      </div>
    </div>
  )
}
