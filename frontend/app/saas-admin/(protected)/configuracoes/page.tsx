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
  evolution_url: string | null
  evolution_api_key: string | null
  smtp_host: string | null
  smtp_port: number | null
  smtp_username: string | null
  smtp_password: string | null
  smtp_encryption: string | null
  smtp_from_address: string | null
  smtp_from_name: string | null
  smtp_ativo: boolean
  provedor_fiscal_padrao: string
  emissao_fiscal_modo_padrao: string
  spedy_master_key_sandbox: string | null
  spedy_master_key_producao: string | null
  focus_master_token_homologacao: string | null
  focus_master_token_producao: string | null
  cobranca_dias_antecedencia_padrao: number
  cobranca_dias_suspensao_padrao: number
  desconto_anual_pct: number
  alerta_cobranca_vezes_dia: number
  alerta_cobranca_dias_exibicao: number
  voto_confianca_dias: number
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

  // SMTP
  const [smtpHost, setSmtpHost] = useState('')
  const [smtpPort, setSmtpPort] = useState('587')
  const [smtpUsername, setSmtpUsername] = useState('')
  const [smtpPassword, setSmtpPassword] = useState('')
  const [smtpEncryption, setSmtpEncryption] = useState('tls')
  const [smtpFromAddress, setSmtpFromAddress] = useState('')
  const [smtpFromName, setSmtpFromName] = useState('MecânicaPro')
  const [smtpAtivo, setSmtpAtivo] = useState(false)
  const [savingSmtp, setSavingSmtp] = useState(false)
  const [smtpTestTo, setSmtpTestTo] = useState('')
  const [testingSmtp, setTestingSmtp] = useState(false)

  // Evolution API
  const [evolutionUrl, setEvolutionUrl] = useState('')
  const [evolutionApiKey, setEvolutionApiKey] = useState('')
  const [savingEvolution, setSavingEvolution] = useState(false)
  const [testingEvolution, setTestingEvolution] = useState(false)

  // Fiscal
  const [provedorFiscal, setProvedorFiscal] = useState('SPEDY')
  const [modoEmissao, setModoEmissao] = useState('MANUAL')
  const [savingFiscal, setSavingFiscal] = useState(false)
  const [spedySandbox, setSpedySandbox] = useState('')
  const [spedyProducao, setSpedyProducao] = useState('')
  const [savingSpedy, setSavingSpedy] = useState(false)
  const [focusHomolog, setFocusHomolog] = useState('')
  const [focusProducao, setFocusProducao] = useState('')
  const [savingFocus, setSavingFocus] = useState(false)

  // Cobrança
  const [diasAntecedencia, setDiasAntecedencia] = useState('5')
  const [diasSuspensao, setDiasSuspensao] = useState('10')
  const [descontoAnual, setDescontoAnual] = useState('0')
  const [savingCobranca, setSavingCobranca] = useState(false)
  const [alertaVezesDia, setAlertaVezesDia] = useState('1')
  const [alertaDiasExibicao, setAlertaDiasExibicao] = useState('30')
  const [votoConfiancaDias, setVotoConfiancaDias] = useState('3')

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
        setSmtpHost(d.smtp_host ?? '')
        setSmtpPort(d.smtp_port ? String(d.smtp_port) : '587')
        setSmtpUsername(d.smtp_username ?? '')
        setSmtpPassword(d.smtp_password ?? '')
        setSmtpEncryption(d.smtp_encryption ?? 'tls')
        setSmtpFromAddress(d.smtp_from_address ?? '')
        setEvolutionUrl(d.evolution_url ?? '')
        setEvolutionApiKey(d.evolution_api_key ?? '')
        setSmtpFromName(d.smtp_from_name ?? 'MecânicaPro')
        setSmtpAtivo(d.smtp_ativo ?? false)
        setProvedorFiscal(d.provedor_fiscal_padrao ?? 'SPEDY')
        setModoEmissao(d.emissao_fiscal_modo_padrao ?? 'MANUAL')
        setSpedySandbox(d.spedy_master_key_sandbox ?? '')
        setSpedyProducao(d.spedy_master_key_producao ?? '')
        setFocusHomolog(d.focus_master_token_homologacao ?? '')
        setFocusProducao(d.focus_master_token_producao ?? '')
        setDiasAntecedencia(String(d.cobranca_dias_antecedencia_padrao ?? 5))
        setDiasSuspensao(String(d.cobranca_dias_suspensao_padrao ?? 10))
        setDescontoAnual(String(d.desconto_anual_pct ?? 0))
        setAlertaVezesDia(String(d.alerta_cobranca_vezes_dia ?? 1))
        setAlertaDiasExibicao(String(d.alerta_cobranca_dias_exibicao ?? 30))
        setVotoConfiancaDias(String(d.voto_confianca_dias ?? 3))
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

  async function salvarEvolution() {
    setSavingEvolution(true)
    try {
      await saasApi.put('/saas/config/evolution', {
        evolution_url:     evolutionUrl,
        evolution_api_key: evolutionApiKey,
      })
      showToast('Configurações Evolution API salvas.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao salvar Evolution API.', 'danger')
    } finally {
      setSavingEvolution(false)
    }
  }

  async function testarEvolution() {
    setTestingEvolution(true)
    try {
      const r = await saasApi.post<{ ok: boolean; status?: string; error?: string }>('/saas/config/evolution/testar', {})
      if (r.data.ok) {
        showToast(`Conexão OK! Evolution API respondeu.`, 'success')
      } else {
        showToast(`Falhou: ${r.data.error}`, 'danger')
      }
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { error?: string; message?: string } } })?.response?.data?.error
        ?? (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao testar conexão.', 'danger')
    } finally {
      setTestingEvolution(false)
    }
  }

  async function salvarSmtp() {
    setSavingSmtp(true)
    try {
      await saasApi.put('/saas/config/smtp', {
        smtp_host: smtpHost,
        smtp_port: parseInt(smtpPort, 10) || 587,
        smtp_username: smtpUsername,
        smtp_password: smtpPassword,
        smtp_encryption: smtpEncryption || null,
        smtp_from_address: smtpFromAddress,
        smtp_from_name: smtpFromName,
        smtp_ativo: smtpAtivo,
      })
      showToast('Configurações SMTP salvas.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao salvar SMTP.', 'danger')
    } finally {
      setSavingSmtp(false)
    }
  }

  async function salvarProvedorFiscal() {
    setSavingFiscal(true)
    try {
      await saasApi.put('/saas/config/fiscal', {
        provedor_fiscal_padrao: provedorFiscal,
        emissao_fiscal_modo_padrao: modoEmissao,
      })
      showToast('Provedor fiscal padrão salvo.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao salvar provedor fiscal.', 'danger')
    } finally {
      setSavingFiscal(false)
    }
  }

  async function salvarSpedy() {
    setSavingSpedy(true)
    try {
      await saasApi.put('/saas/config/fiscal/spedy', {
        spedy_master_key_sandbox: spedySandbox,
        spedy_master_key_producao: spedyProducao,
      })
      showToast('Credenciais Spedy salvas.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao salvar credenciais Spedy.', 'danger')
    } finally {
      setSavingSpedy(false)
    }
  }

  async function salvarFocus() {
    setSavingFocus(true)
    try {
      await saasApi.put('/saas/config/fiscal/focus', {
        focus_master_token_homologacao: focusHomolog,
        focus_master_token_producao: focusProducao,
      })
      showToast('Credenciais Focus NFe salvas.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao salvar credenciais Focus.', 'danger')
    } finally {
      setSavingFocus(false)
    }
  }

  async function salvarCobranca() {
    setSavingCobranca(true)
    try {
      await saasApi.put('/saas/config/cobranca', {
        cobranca_dias_antecedencia_padrao: parseInt(diasAntecedencia, 10) || 5,
        cobranca_dias_suspensao_padrao: parseInt(diasSuspensao, 10) || 10,
        desconto_anual_pct: parseFloat(descontoAnual) || 0,
        alerta_cobranca_vezes_dia: parseInt(alertaVezesDia, 10) || 1,
        alerta_cobranca_dias_exibicao: parseInt(alertaDiasExibicao, 10) || 30,
        voto_confianca_dias: parseInt(votoConfiancaDias, 10) || 3,
      })
      showToast('Configurações de cobrança salvas.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao salvar configurações de cobrança.', 'danger')
    } finally {
      setSavingCobranca(false)
    }
  }

  async function testarSmtp() {
    if (!smtpTestTo.trim()) {
      showToast('Informe um e-mail para receber o teste.', 'danger')
      return
    }
    setTestingSmtp(true)
    try {
      await saasApi.post('/saas/config/smtp/testar', { destinatario: smtpTestTo })
      showToast('E-mail de teste enviado! Verifique a caixa de entrada.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { error?: string } } })?.response?.data?.error
      showToast(msg ?? 'Falha ao enviar e-mail de teste.', 'danger')
    } finally {
      setTestingSmtp(false)
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

      {/* ── Seção 4 — SMTP (E-mail) ─────────────────────────────────────── */}
      <SectionCard
        title="Servidor de E-mail (SMTP)"
        subtitle="Credenciais usadas para enviar os alertas por e-mail das oficinas"
      >
        <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 12 }}>
          <div style={{ marginBottom: 16 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
              Host SMTP
            </label>
            <input value={smtpHost} onChange={e => setSmtpHost(e.target.value)} placeholder="smtp.gmail.com"
              style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
          </div>
          <div style={{ marginBottom: 16 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
              Porta
            </label>
            <input value={smtpPort} onChange={e => setSmtpPort(e.target.value)} placeholder="587" type="number"
              style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
          </div>
        </div>

        <div style={{ marginBottom: 16 }}>
          <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
            Usuário
          </label>
          <input value={smtpUsername} onChange={e => setSmtpUsername(e.target.value)} placeholder="usuario@dominio.com"
            style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
        </div>

        <SecretInput label="Senha" value={smtpPassword} onChange={setSmtpPassword} />

        <div style={{ marginBottom: 16 }}>
          <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
            Criptografia
          </label>
          <select value={smtpEncryption} onChange={e => setSmtpEncryption(e.target.value)} style={selectStyle}>
            <option value="tls">TLS</option>
            <option value="ssl">SSL</option>
          </select>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <div style={{ marginBottom: 16 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
              E-mail remetente (From)
            </label>
            <input value={smtpFromAddress} onChange={e => setSmtpFromAddress(e.target.value)} placeholder="naoresponda@dominio.com"
              style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
          </div>
          <div style={{ marginBottom: 16 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
              Nome remetente
            </label>
            <input value={smtpFromName} onChange={e => setSmtpFromName(e.target.value)} placeholder="MecânicaPro"
              style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
          </div>
        </div>

        <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', marginBottom: 16 }}>
          <input type="checkbox" checked={smtpAtivo} onChange={e => setSmtpAtivo(e.target.checked)}
            style={{ width: 16, height: 16, accentColor: 'var(--accent)' }} />
          <span style={{ fontSize: 14, fontWeight: 600, color: 'var(--text)' }}>Ativar envio de e-mails</span>
        </label>

        <div style={{ display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <SaveButton loading={savingSmtp} onClick={salvarSmtp} label="Salvar SMTP" />
          <div style={{ flex: 1, minWidth: 200 }}>
            <input value={smtpTestTo} onChange={e => setSmtpTestTo(e.target.value)} placeholder="email@para-testar.com" type="email"
              style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
          </div>
          <button onClick={testarSmtp} disabled={testingSmtp}
            style={{ padding: '9px 18px', borderRadius: 7, background: 'transparent', border: '1px solid var(--accent)', color: 'var(--accent)', fontSize: 14, fontWeight: 600, cursor: testingSmtp ? 'not-allowed' : 'pointer', whiteSpace: 'nowrap' }}>
            {testingSmtp ? '⟳ Enviando...' : '📨 Enviar teste'}
          </button>
        </div>
      </SectionCard>

      {/* ── Seção 5 — Emissão de Notas Fiscais ──────────────────────────── */}
      <SectionCard
        title="Emissão de Notas Fiscais"
        subtitle="Provedor padrão da plataforma e credenciais das contas-parceiras (Spedy / Focus NFe)"
      >
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12, marginBottom: 20 }}>
          {[
            { value: 'SPEDY', label: 'Spedy', desc: 'Emissão via API Spedy (NFS-e/NF-e). Sandbox e produção.' },
            { value: 'FOCUS', label: 'Focus NFe', desc: 'Emissão via API Focus NFe (assíncrona). Homologação e produção.' },
          ].map(opt => (
            <label key={opt.value} style={{
              display: 'flex', alignItems: 'flex-start', gap: 12,
              padding: '14px 16px', borderRadius: 8, cursor: 'pointer',
              border: `1px solid ${provedorFiscal === opt.value ? 'var(--accent)' : 'var(--border)'}`,
              background: provedorFiscal === opt.value ? 'rgba(245,166,35,.06)' : 'transparent',
            }}>
              <input type="radio" name="provedorFiscal" value={opt.value}
                checked={provedorFiscal === opt.value}
                onChange={() => setProvedorFiscal(opt.value)}
                style={{ marginTop: 2, accentColor: 'var(--accent)' }} />
              <div>
                <div style={{ fontSize: 14, fontWeight: 700 }}>{opt.label}</div>
                <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2 }}>{opt.desc}</div>
              </div>
            </label>
          ))}
        </div>

        <div style={{ marginBottom: 16 }}>
          <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
            Modo de emissão padrão
          </label>
          <select value={modoEmissao} onChange={e => setModoEmissao(e.target.value)} style={selectStyle}>
            <option value="MANUAL">Manual (emite por botão)</option>
            <option value="AUTOMATICO">Automático (emite ao concluir a OS)</option>
          </select>
        </div>
        <SaveButton loading={savingFiscal} onClick={salvarProvedorFiscal} label="Salvar Provedor Padrão" />

        <div style={{ height: 1, background: 'var(--border)', margin: '20px 0' }} />

        <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text)', marginBottom: 12 }}>Credenciais Spedy (conta-parceira)</div>
        <SecretInput label="X-API-Key Sandbox" value={spedySandbox} onChange={setSpedySandbox} />
        <SecretInput label="X-API-Key Produção" value={spedyProducao} onChange={setSpedyProducao} />
        <SaveButton loading={savingSpedy} onClick={salvarSpedy} label="Salvar Credenciais Spedy" />

        <div style={{ height: 1, background: 'var(--border)', margin: '20px 0' }} />

        <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text)', marginBottom: 12 }}>Credenciais Focus NFe (conta-parceira)</div>
        <SecretInput label="Token Homologação" value={focusHomolog} onChange={setFocusHomolog} />
        <SecretInput label="Token Produção" value={focusProducao} onChange={setFocusProducao} />
        <SaveButton loading={savingFocus} onClick={salvarFocus} label="Salvar Credenciais Focus NFe" />
      </SectionCard>

      {/* ── Seção 5.5 — Cobrança Recorrente ─────────────────────────────── */}
      <SectionCard
        title="Cobrança Recorrente"
        subtitle="Regras padrão de geração de cobrança, suspensão por atraso e desconto anual — cada oficina pode sobrescrever antecedência e suspensão individualmente"
      >
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <div style={{ marginBottom: 16 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
              Dias de antecedência p/ gerar cobrança
            </label>
            <input value={diasAntecedencia} onChange={e => setDiasAntecedencia(e.target.value)} type="number" min={1} max={60}
              style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
          </div>
          <div style={{ marginBottom: 16 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
              Dias para suspensão após vencimento
            </label>
            <input value={diasSuspensao} onChange={e => setDiasSuspensao(e.target.value)} type="number" min={1} max={90}
              style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
          </div>
        </div>
        <div style={{ marginBottom: 16, maxWidth: 260 }}>
          <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
            Desconto pagamento anual (%)
          </label>
          <input value={descontoAnual} onChange={e => setDescontoAnual(e.target.value)} type="number" min={0} max={90} step="0.5"
            style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <div style={{ marginBottom: 16 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
              Alerta de cobrança: vezes/dia
            </label>
            <input value={alertaVezesDia} onChange={e => setAlertaVezesDia(e.target.value)} type="number" min={1} max={10}
              style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
          </div>
          <div style={{ marginBottom: 16 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
              Dias de exibição (antes do vencimento)
            </label>
            <input value={alertaDiasExibicao} onChange={e => setAlertaDiasExibicao(e.target.value)} type="number" min={1} max={90}
              style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
          </div>
        </div>
        <div style={{ marginBottom: 16, maxWidth: 260 }}>
          <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
            Voto de confiança (dias de liberação)
          </label>
          <input value={votoConfiancaDias} onChange={e => setVotoConfiancaDias(e.target.value)} type="number" min={1} max={30}
            style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
        </div>
        <SaveButton loading={savingCobranca} onClick={salvarCobranca} label="Salvar Cobrança" />
      </SectionCard>

      {/* ── Seção 6 — Evolution API (WhatsApp) ──────────────────────────── */}
      <SectionCard
        title="Evolution API — WhatsApp"
        subtitle="Credenciais globais da Evolution API. Cada oficina cria sua própria instância ao escanear o QR code."
      >
        <div style={{ marginBottom: 16 }}>
          <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
            URL da Evolution API
          </label>
          <input
            type="url"
            value={evolutionUrl}
            onChange={e => setEvolutionUrl(e.target.value)}
            placeholder="https://evolution.seudominio.com"
            style={{
              width: '100%', padding: '9px 12px', borderRadius: 7,
              border: '1px solid var(--border)', background: 'var(--card)',
              color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box',
            }}
          />
        </div>

        <SecretInput label="API Key (apikey)" value={evolutionApiKey} onChange={setEvolutionApiKey} placeholder="sua-api-key-global" />

        <div style={{ display: 'flex', gap: 12, marginTop: 8 }}>
          <button
            onClick={testarEvolution}
            disabled={testingEvolution || !evolutionUrl}
            style={{
              padding: '9px 20px', borderRadius: 7, border: '1px solid var(--accent)',
              background: 'transparent', color: 'var(--accent)', fontSize: 14, fontWeight: 600,
              cursor: (testingEvolution || !evolutionUrl) ? 'not-allowed' : 'pointer',
              opacity: (testingEvolution || !evolutionUrl) ? 0.6 : 1,
            }}
          >
            {testingEvolution ? '⟳ Testando...' : '🔌 Testar Conexão'}
          </button>
          <SaveButton loading={savingEvolution} onClick={salvarEvolution} label="Salvar Credenciais" />
        </div>

        <div style={{ marginTop: 16, padding: '10px 14px', background: 'rgba(30,136,229,.06)', border: '1px solid rgba(30,136,229,.2)', borderRadius: 7, fontSize: 12, color: 'var(--info)' }}>
          ℹ️ Após salvar, cada oficina poderá conectar seu próprio WhatsApp em <strong>Config WhatsApp</strong> clicando em "Escanear QR Code". A instância é criada automaticamente na primeira vez.
        </div>
      </SectionCard>
    </div>
  )
}
