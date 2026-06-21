'use client'
import { useState, useEffect, useCallback, useRef } from 'react'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

interface WaConfig {
  evolution_url: string
  evolution_api_key: string | null
  instance_name: string
  instance_token: string | null
  ativo: boolean
}

interface InstanceStatus {
  status: string
  number: string | null
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

function SecretInput({ label, value, onChange, placeholder }: {
  label: string; value: string; onChange: (v: string) => void; placeholder?: string
}) {
  const [visible, setVisible] = useState(false)
  return (
    <div>
      <label style={lStyle}>{label}</label>
      <div style={{ position: 'relative' }}>
        <input
          type={visible ? 'text' : 'password'}
          value={value}
          onChange={e => onChange(e.target.value)}
          placeholder={placeholder ?? '••••••••••••'}
          style={{ ...iStyle, paddingRight: 40 }}
        />
        <button type="button" onClick={() => setVisible(v => !v)}
          style={{ position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)', background: 'none', border: 'none', cursor: 'pointer', color: 'var(--muted)', fontSize: 14 }}>
          {visible ? '🙈' : '👁'}
        </button>
      </div>
    </div>
  )
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
  return (
    <span style={{ fontSize: 13, fontWeight: 700, color: m.color }}>
      {m.label}
    </span>
  )
}

export default function WhatsAppConfigPage() {
  const [loading, setLoading]           = useState(true)
  const [saving, setSaving]             = useState(false)
  const [testing, setTesting]           = useState(false)
  const [disconnecting, setDisconnecting] = useState(false)
  const [status, setStatus]             = useState<InstanceStatus>({ status: 'unknown', number: null })
  const [qrCode, setQrCode]             = useState<string | null>(null)
  const [showQr, setShowQr]             = useState(false)
  const [testPhone, setTestPhone]       = useState('')
  const [sendingTest, setSendingTest]   = useState(false)
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null)

  const [form, setForm] = useState({
    evolution_url:     'http://192.168.0.115:8081',
    evolution_api_key: '',
    instance_name:     'mecanicapro',
    instance_token:    '',
    ativo:             false,
  })

  const fetchStatus = useCallback(async () => {
    try {
      const r = await api.get<InstanceStatus>('/whatsapp/status')
      setStatus(r.data)
      if (r.data.status === 'open' && showQr) {
        setShowQr(false)
        setQrCode(null)
        toast('WhatsApp conectado com sucesso!', 'success')
        if (pollRef.current) clearInterval(pollRef.current)
      }
    } catch { /* silent */ }
  }, [showQr])

  useEffect(() => {
    api.get<{ data: WaConfig | null }>('/whatsapp/config').then(r => {
      const d = r.data.data
      if (d) setForm({
        evolution_url:     d.evolution_url,
        evolution_api_key: d.evolution_api_key ?? '',
        instance_name:     d.instance_name,
        instance_token:    d.instance_token ?? '',
        ativo:             d.ativo,
      })
    }).catch(() => {}).finally(() => setLoading(false))

    fetchStatus()
  }, [fetchStatus])

  useEffect(() => {
    if (showQr) {
      pollRef.current = setInterval(fetchStatus, 4000)
    } else {
      if (pollRef.current) clearInterval(pollRef.current)
    }
    return () => { if (pollRef.current) clearInterval(pollRef.current) }
  }, [showQr, fetchStatus])

  const set = (k: string) => (v: string | boolean) => setForm(f => ({ ...f, [k]: v }))

  async function salvar() {
    setSaving(true)
    try {
      await api.post('/whatsapp/config', form)
      toast('Configuração salva!', 'success')
      fetchStatus()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast(msg ?? 'Erro ao salvar.', 'danger')
    } finally { setSaving(false) }
  }

  async function testar() {
    setTesting(true)
    try {
      const r = await api.post<{ ok: boolean; status?: string; error?: string }>('/whatsapp/testar', {
        evolution_url:     form.evolution_url,
        evolution_api_key: form.evolution_api_key,
        instance_name:     form.instance_name,
      })
      if (r.data.ok) {
        toast(`Conexão OK! Status: ${r.data.status ?? 'open'}`, 'success')
      } else {
        toast(`Falhou: ${r.data.error}`, 'danger')
      }
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast(msg ?? 'Erro ao testar.', 'danger')
    } finally { setTesting(false) }
  }

  async function verQrCode() {
    try {
      const r = await api.get<{ qrcode: string }>('/whatsapp/qrcode')
      setQrCode(r.data.qrcode)
      setShowQr(true)
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast(msg ?? 'Erro ao gerar o QR code.', 'danger')
    }
  }

  async function desconectar() {
    if (!window.confirm('Desconectar a sessão do WhatsApp? Será necessário escanear o QR code novamente para reconectar.')) return
    setDisconnecting(true)
    try {
      await api.post('/whatsapp/desconectar', {})
      toast('Sessão desconectada.', 'success')
      setStatus({ status: 'close', number: null })
      fetchStatus()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { error?: string } } })?.response?.data?.error
      toast(msg ?? 'Erro ao desconectar.', 'danger')
    } finally { setDisconnecting(false) }
  }

  async function enviarTeste() {
    const numero = testPhone.replace(/\D/g, '')
    if (numero.length < 10) {
      toast('Informe um número válido com DDD (ex: 11999998888).', 'danger')
      return
    }
    setSendingTest(true)
    try {
      await api.post('/whatsapp/enviar-teste', { telefone: testPhone })
      toast('Mensagem de teste enviada! Verifique o WhatsApp do número.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { error?: string } } })?.response?.data?.error
      toast(msg ?? 'Erro ao enviar a mensagem de teste.', 'danger')
    } finally { setSendingTest(false) }
  }

  if (loading) return <div style={{ padding: 40, color: 'var(--muted)' }}>Carregando...</div>

  return (
    <div style={{ maxWidth: 680, margin: '0 auto', color: 'var(--text)' }}>
      <div style={{ marginBottom: 28 }}>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, margin: 0 }}>
          Configuração WhatsApp
        </h1>
        <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
          Integração com Evolution API para envio de alertas via WhatsApp
        </p>
      </div>

      {/* Status card */}
      <div style={{
        background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10,
        padding: '16px 20px', marginBottom: 20, display: 'flex', alignItems: 'center', justifyContent: 'space-between',
      }}>
        <div>
          <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 4, textTransform: 'uppercase', letterSpacing: '0.05em' }}>Status da Instância</div>
          <StatusChip status={status.status} />
          {status.number && (
            <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4 }}>
              📱 {status.number.replace('@s.whatsapp.net', '')}
            </div>
          )}
        </div>
        <div style={{ display: 'flex', gap: 10 }}>
          {status.status !== 'open' && (
            <button onClick={verQrCode}
              style={{ padding: '8px 16px', borderRadius: 7, background: 'var(--accent)', color: '#000', border: 'none', fontSize: 13, fontWeight: 700, cursor: 'pointer' }}>
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
              Abra o WhatsApp → Dispositivos vinculados → Vincular um dispositivo
            </p>
            {qrCode ? (
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

      {/* Config Form */}
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
        <div style={{ padding: '14px 20px', borderBottom: '1px solid var(--border)' }}>
          <div style={{ fontSize: 15, fontWeight: 700 }}>Credenciais da Evolution API</div>
        </div>
        <div style={{ padding: 24, display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div>
            <label style={lStyle}>URL da Evolution API</label>
            <input value={form.evolution_url} onChange={e => set('evolution_url')(e.target.value)} style={iStyle}
              placeholder="http://192.168.0.115:8081" />
          </div>

          <SecretInput label="API Key (apikey)" value={form.evolution_api_key}
            onChange={set('evolution_api_key')} placeholder="620096bf1e66..." />

          <div>
            <label style={lStyle}>Nome da Instância</label>
            <input value={form.instance_name} onChange={e => set('instance_name')(e.target.value)} style={iStyle}
              placeholder="mecanicapro" />
          </div>

          <SecretInput label="Token da Instância (opcional)" value={form.instance_token}
            onChange={set('instance_token')} />

          <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}>
            <input
              type="checkbox"
              checked={form.ativo}
              onChange={e => set('ativo')(e.target.checked)}
              style={{ width: 16, height: 16, accentColor: 'var(--accent)', cursor: 'pointer' }}
            />
            <span style={{ fontSize: 14, fontWeight: 600 }}>Ativar envio de alertas via WhatsApp</span>
          </label>

          <div style={{ display: 'flex', gap: 12, paddingTop: 8, borderTop: '1px solid var(--border)' }}>
            <button onClick={testar} disabled={testing}
              style={{ padding: '9px 20px', borderRadius: 7, background: 'transparent', border: '1px solid var(--accent)', color: 'var(--accent)', fontSize: 14, fontWeight: 600, cursor: testing ? 'not-allowed' : 'pointer' }}>
              {testing ? '⟳ Testando...' : '🔌 Testar Conexão'}
            </button>
            <button onClick={salvar} disabled={saving}
              style={{ padding: '9px 24px', borderRadius: 7, background: saving ? 'var(--border)' : 'var(--accent)', color: saving ? 'var(--muted)' : '#000', border: 'none', fontSize: 14, fontWeight: 700, cursor: saving ? 'not-allowed' : 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
              {saving ? '⟳ Salvando...' : 'Salvar Configuração'}
            </button>
          </div>
        </div>
      </div>

      {/* Enviar mensagem de teste */}
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden', marginTop: 20 }}>
        <div style={{ padding: '14px 20px', borderBottom: '1px solid var(--border)' }}>
          <div style={{ fontSize: 15, fontWeight: 700 }}>Enviar mensagem de teste</div>
          <div style={{ fontSize: 13, color: 'var(--muted)', marginTop: 2 }}>
            Informe um número com DDD para confirmar que o envio está funcionando
          </div>
        </div>
        <div style={{ padding: 24 }}>
          {status.status !== 'open' && (
            <div style={{ marginBottom: 14, padding: '10px 14px', background: 'rgba(245,166,35,.08)', border: '1px solid rgba(245,166,35,.25)', borderRadius: 7, fontSize: 13, color: 'var(--accent)' }}>
              ⚠️ O WhatsApp precisa estar conectado para enviar mensagens. Escaneie o QR code primeiro.
            </div>
          )}
          <div style={{ display: 'flex', gap: 12, alignItems: 'flex-end' }}>
            <div style={{ flex: 1 }}>
              <label style={lStyle}>Número do WhatsApp (com DDD)</label>
              <input
                value={testPhone}
                onChange={e => setTestPhone(e.target.value)}
                onKeyDown={e => { if (e.key === 'Enter') enviarTeste() }}
                placeholder="11999998888"
                style={iStyle}
              />
            </div>
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

      <div style={{ marginTop: 16, padding: '12px 16px', background: 'rgba(30,136,229,.08)', border: '1px solid rgba(30,136,229,.2)', borderRadius: 8, fontSize: 13, color: 'var(--info)' }}>
        ℹ️ A Evolution API já está instalada neste servidor em <code style={{ fontFamily: 'monospace' }}>http://192.168.0.115:8081</code>. API Key e instância já estão configurados — insira os valores acima e teste a conexão.
      </div>
    </div>
  )
}
