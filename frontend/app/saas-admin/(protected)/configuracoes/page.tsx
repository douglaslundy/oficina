'use client'

import { useState, useEffect, useCallback } from 'react'
import saasApi from '@/lib/saas-api'

// ─── Types ────────────────────────────────────────────────────────────────────

interface SaasConfigData {
  gateway_preferido: string
  mp_ambiente: string
  asaas_api_key: string | null
  asaas_webhook_token: string | null
  mp_access_token: string | null
  mp_public_key: string | null
  mp_webhook_secret: string | null
}

type ToastType = 'success' | 'danger'

// ─── Toast ────────────────────────────────────────────────────────────────────

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

// ─── SecretInput ──────────────────────────────────────────────────────────────

function SecretInput({
  label, placeholder, value, onChange,
}: {
  label: string
  placeholder?: string
  value: string
  onChange: (v: string) => void
}) {
  const [visible, setVisible] = useState(false)

  return (
    <div style={{ marginBottom: 16 }}>
      <label style={{
        display: 'block', fontSize: 12, fontWeight: 600,
        color: 'var(--muted)', textTransform: 'uppercase',
        letterSpacing: '0.05em', marginBottom: 6,
      }}>
        {label}
      </label>
      <div style={{ position: 'relative' }}>
        <input
          type={visible ? 'text' : 'password'}
          value={value}
          onChange={e => onChange(e.target.value)}
          placeholder={placeholder ?? '••••••••••••'}
          style={{
            width: '100%', padding: '9px 40px 9px 12px', borderRadius: 7,
            border: '1px solid var(--border)', background: 'var(--card)',
            color: 'var(--text)', fontSize: 14, outline: 'none',
            boxSizing: 'border-box', fontFamily: value ? 'monospace' : 'inherit',
          }}
        />
        <button
          type="button"
          onClick={() => setVisible(v => !v)}
          style={{
            position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)',
            background: 'none', border: 'none', cursor: 'pointer',
            color: 'var(--muted)', fontSize: 14, padding: 2,
          }}
        >
          {visible ? '🙈' : '👁'}
        </button>
      </div>
    </div>
  )
}

// ─── SectionCard ──────────────────────────────────────────────────────────────

function SectionCard({ title, subtitle, children }: {
  title: string; subtitle?: string; children: React.ReactNode
}) {
  return (
    <div style={{
      background: 'var(--card)', border: '1px solid var(--border)',
      borderRadius: 10, overflow: 'hidden', marginBottom: 20,
    }}>
      <div style={{ padding: '16px 24px', borderBottom: '1px solid var(--border)' }}>
        <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--text)' }}>{title}</div>
        {subtitle && <div style={{ fontSize: 13, color: 'var(--muted)', marginTop: 2 }}>{subtitle}</div>}
      </div>
      <div style={{ padding: 24 }}>{children}</div>
    </div>
  )
}

// ─── SaveButton ───────────────────────────────────────────────────────────────

function SaveButton({ loading, onClick, label = 'Salvar' }: {
  loading: boolean; onClick: () => void; label?: string
}) {
  return (
    <button
      onClick={onClick}
      disabled={loading}
      style={{
        padding: '9px 24px', borderRadius: 7, border: 'none',
        background: loading ? 'var(--border)' : 'var(--accent)',
        color: loading ? 'var(--muted)' : '#000',
        fontSize: 14, fontWeight: 700, cursor: loading ? 'not-allowed' : 'pointer',
        fontFamily: "'Barlow Condensed', sans-serif",
        transition: 'background 0.15s',
      }}
    >
      {loading ? '⟳ Salvando...' : label}
    </button>
  )
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function SaasConfigPage() {
  const [loading, setLoading] = useState(true)
  const [toast, setToast] = useState<{ msg: string; type: ToastType } | null>(null)

  // Gateway
  const [gateway, setGateway] = useState('ASAAS')
  const [savingGateway, setSavingGateway] = useState(false)

  // Asaas
  const [asaasApiKey, setAsaasApiKey] = useState('')
  const [asaasWebhookToken, setAsaasWebhookToken] = useState('')
  const [savingAsaas, setSavingAsaas] = useState(false)

  // Mercado Pago
  const [mpAccessToken, setMpAccessToken] = useState('')
  const [mpPublicKey, setMpPublicKey] = useState('')
  const [mpWebhookSecret, setMpWebhookSecret] = useState('')
  const [mpAmbiente, setMpAmbiente] = useState('sandbox')
  const [savingMp, setSavingMp] = useState(false)

  const showToast = useCallback((msg: string, type: ToastType) => setToast({ msg, type }), [])

  useEffect(() => {
    saasApi.get<{ data: SaasConfigData }>('/saas/config')
      .then(r => {
        const d = r.data.data
        setGateway(d.gateway_preferido)
        setAsaasApiKey(d.asaas_api_key ?? '')
        setAsaasWebhookToken(d.asaas_webhook_token ?? '')
        setMpAccessToken(d.mp_access_token ?? '')
        setMpPublicKey(d.mp_public_key ?? '')
        setMpWebhookSecret(d.mp_webhook_secret ?? '')
        setMpAmbiente(d.mp_ambiente ?? 'sandbox')
      })
      .catch(() => showToast('Erro ao carregar configurações.', 'danger'))
      .finally(() => setLoading(false))
  }, [showToast])

  async function salvarGateway() {
    setSavingGateway(true)
    try {
      await saasApi.put('/saas/config/gateway', { gateway_preferido: gateway })
      showToast('Gateway atualizado.', 'success')
    } catch {
      showToast('Erro ao salvar gateway.', 'danger')
    } finally {
      setSavingGateway(false)
    }
  }

  async function salvarAsaas() {
    setSavingAsaas(true)
    try {
      await saasApi.put('/saas/config/asaas', {
        asaas_api_key: asaasApiKey,
        asaas_webhook_token: asaasWebhookToken,
      })
      showToast('Configurações Asaas salvas.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao salvar configurações Asaas.', 'danger')
    } finally {
      setSavingAsaas(false)
    }
  }

  async function salvarMercadoPago() {
    setSavingMp(true)
    try {
      await saasApi.put('/saas/config/mercadopago', {
        mp_access_token: mpAccessToken,
        mp_public_key: mpPublicKey,
        mp_webhook_secret: mpWebhookSecret,
        mp_ambiente: mpAmbiente,
      })
      showToast('Configurações Mercado Pago salvas.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao salvar configurações Mercado Pago.', 'danger')
    } finally {
      setSavingMp(false)
    }
  }

  if (loading) {
    return (
      <div style={{ padding: '40px 32px', color: 'var(--muted)', fontSize: 14 }}>
        Carregando configurações...
      </div>
    )
  }

  const selectStyle: React.CSSProperties = {
    width: '100%', padding: '9px 12px', borderRadius: 7,
    border: '1px solid var(--border)', background: 'var(--card)',
    color: 'var(--text)', fontSize: 14, outline: 'none', cursor: 'pointer',
    marginBottom: 16,
  }

  return (
    <div style={{ padding: '28px 32px', maxWidth: 720, margin: '0 auto', color: 'var(--text)' }}>
      {toast && <Toast msg={toast.msg} type={toast.type} onClose={() => setToast(null)} />}

      <div style={{ marginBottom: 24 }}>
        <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, margin: 0 }}>
          Configurações
        </h1>
        <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
          Gateway de pagamento e integrações SaaS
        </p>
      </div>

      {/* ── Seção 1 — Gateway ───────────────────────────────────────────── */}
      <SectionCard
        title="Gateway de Pagamento"
        subtitle="Escolha qual gateway será usado para cobranças de novas oficinas"
      >
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12, marginBottom: 20 }}>
          {[
            { value: 'ASAAS', label: 'Asaas', desc: 'Gateway nacional, boleto + PIX + cartão. Recomendado para o mercado brasileiro.' },
            { value: 'MERCADOPAGO', label: 'Mercado Pago', desc: 'Gateway da Mercado Livre, ampla adoção na América Latina, cartão + PIX.' },
          ].map(opt => (
            <label key={opt.value} style={{
              display: 'flex', alignItems: 'flex-start', gap: 12,
              padding: '14px 16px', borderRadius: 8, cursor: 'pointer',
              border: `1px solid ${gateway === opt.value ? 'var(--accent)' : 'var(--border)'}`,
              background: gateway === opt.value ? 'rgba(245,166,35,.06)' : 'transparent',
              transition: 'all 0.15s',
            }}>
              <input
                type="radio"
                name="gateway"
                value={opt.value}
                checked={gateway === opt.value}
                onChange={() => setGateway(opt.value)}
                style={{ marginTop: 2, accentColor: 'var(--accent)' }}
              />
              <div>
                <div style={{ fontSize: 14, fontWeight: 700 }}>{opt.label}</div>
                <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2 }}>{opt.desc}</div>
              </div>
            </label>
          ))}
        </div>
        <SaveButton loading={savingGateway} onClick={salvarGateway} label="Salvar Gateway" />
      </SectionCard>

      {/* ── Seção 2 — Asaas ─────────────────────────────────────────────── */}
      <SectionCard
        title="Configurações Asaas"
        subtitle="Credenciais para integração com a API Asaas"
      >
        <div style={{
          background: 'rgba(30,136,229,.08)', border: '1px solid rgba(30,136,229,.2)',
          borderRadius: 7, padding: '10px 14px', fontSize: 12, color: 'var(--info)',
          marginBottom: 20,
        }}>
          ℹ️ URL do webhook Asaas: <code style={{ fontFamily: 'monospace' }}>{typeof window !== 'undefined' ? `${window.location.origin.replace(':3000', ':8000')}/api/saas/webhooks/asaas` : '/api/saas/webhooks/asaas'}</code>
        </div>

        <SecretInput label="API Key" value={asaasApiKey} onChange={setAsaasApiKey} placeholder="$aact_..." />
        <SecretInput label="Webhook Token" value={asaasWebhookToken} onChange={setAsaasWebhookToken} />
        <SaveButton loading={savingAsaas} onClick={salvarAsaas} label="Salvar Configurações Asaas" />
      </SectionCard>

      {/* ── Seção 3 — Mercado Pago ──────────────────────────────────────── */}
      <SectionCard
        title="Configurações Mercado Pago"
        subtitle="Credenciais para integração com a API Mercado Pago"
      >
        <div style={{
          background: 'rgba(30,136,229,.08)', border: '1px solid rgba(30,136,229,.2)',
          borderRadius: 7, padding: '10px 14px', fontSize: 12, color: 'var(--info)',
          marginBottom: 20,
        }}>
          ℹ️ URL do webhook MP: <code style={{ fontFamily: 'monospace' }}>{typeof window !== 'undefined' ? `${window.location.origin.replace(':3000', ':8000')}/api/saas/webhooks/mercadopago` : '/api/saas/webhooks/mercadopago'}</code>
        </div>

        <div style={{ marginBottom: 16 }}>
          <label style={{
            display: 'block', fontSize: 12, fontWeight: 600,
            color: 'var(--muted)', textTransform: 'uppercase',
            letterSpacing: '0.05em', marginBottom: 6,
          }}>
            Ambiente
          </label>
          <select value={mpAmbiente} onChange={e => setMpAmbiente(e.target.value)} style={selectStyle}>
            <option value="sandbox">Sandbox (Testes)</option>
            <option value="producao">Produção</option>
          </select>
        </div>

        <SecretInput label="Access Token" value={mpAccessToken} onChange={setMpAccessToken} placeholder="APP_USR-..." />
        <SecretInput label="Public Key" value={mpPublicKey} onChange={setMpPublicKey} placeholder="APP_USR-..." />
        <SecretInput label="Webhook Secret" value={mpWebhookSecret} onChange={setMpWebhookSecret} />
        <SaveButton loading={savingMp} onClick={salvarMercadoPago} label="Salvar Configurações Mercado Pago" />
      </SectionCard>
    </div>
  )
}
