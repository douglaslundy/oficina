'use client'
import { useState, useEffect } from 'react'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

type FormState = Record<string, string>

export default function EmpresaPage() {
  const [form, setForm] = useState<FormState>({})
  const [saving, setSaving] = useState(false)
  const [temCertificado, setTemCertificado] = useState(false)
  const [certFile, setCertFile] = useState<File | null>(null)
  const [certSenha, setCertSenha] = useState('')
  const [certValidade, setCertValidade] = useState<string | null>(null)
  const [uploadingCert, setUploadingCert] = useState(false)
  const [ativando, setAtivando] = useState(false)

  useEffect(() => {
    api.get('/configuracoes').then(r => {
      setForm(r.data)
      setTemCertificado(r.data.tem_certificado ?? false)
      setCertValidade(r.data.certificado_validade ?? null)
    }).catch(() => {})
  }, [])

  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm(f => ({ ...f, [k]: e.target.value }))

  async function salvar() {
    setSaving(true)
    try {
      await api.put('/configuracoes', form)
      toast('Dados da empresa salvos!', 'success')
    } catch {
      toast('Erro ao salvar.', 'danger')
    } finally { setSaving(false) }
  }

  async function enviarCertificado() {
    if (!certFile) { toast('Selecione o arquivo .pfx do certificado.', 'danger'); return }
    if (!certSenha) { toast('Informe a senha do certificado.', 'danger'); return }
    setUploadingCert(true)
    try {
      const fd = new FormData()
      fd.append('certificado', certFile)
      fd.append('senha', certSenha)
      const r = await api.post('/configuracoes/certificado', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      setTemCertificado(true)
      setCertValidade(r.data.validade ?? null)
      setCertSenha('')
      setCertFile(null)
      toast('Certificado enviado com sucesso!', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast(msg ?? 'Erro ao enviar certificado.', 'danger')
    } finally {
      setUploadingCert(false)
    }
  }

  async function ativarEmissao() {
    setAtivando(true)
    try {
      const r = await api.post('/configuracoes/ativar-emissao')
      toast(r.data.message ?? 'Emissão ativada!', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast(msg ?? 'Erro ao ativar emissão.', 'danger')
    } finally {
      setAtivando(false)
    }
  }

  const iStyle: React.CSSProperties = {
    width: '100%', padding: '9px 12px', borderRadius: 8,
    background: 'var(--bg)', border: '1px solid var(--border)',
    color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' as const,
  }
  const lStyle: React.CSSProperties = { color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }

  const fields: Array<[string, string, string]> = [
    ['razao_social', 'Razão Social', '1 / -1'],
    ['nome_fantasia', 'Nome Fantasia', ''],
    ['cnpj', 'CNPJ', ''],
    ['inscricao_estadual', 'Inscrição Estadual', ''],
    ['inscricao_municipal', 'Inscrição Municipal', ''],
    ['regime_tributario', 'Regime Tributário', ''],
    ['telefone', 'Telefone', ''],
    ['email', 'E-mail', ''],
    ['cep', 'CEP', ''],
    ['endereco', 'Endereço', '1 / -1'],
    ['cidade', 'Cidade', ''],
    ['uf', 'UF', ''],
  ]

  return (
    <div style={{ maxWidth: 800, margin: '0 auto' }}>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Dados da Empresa</h1>
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 28 }}>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          {fields.map(([key, label, col]) => (
            <div key={key} style={col ? { gridColumn: col } : {}}>
              <label style={lStyle}>{label}</label>
              <input value={form[key] ?? ''} onChange={set(key)} style={iStyle} />
            </div>
          ))}

          <p style={{ gridColumn: '1 / -1', color: 'var(--muted)', fontSize: 12, fontWeight: 600, textTransform: 'uppercase' as const, letterSpacing: '0.06em', margin: '8px 0 -4px' }}>
            Configurações Fiscais
          </p>

          <div>
            <label style={lStyle}>Ambiente</label>
            <select value={form.ambiente_fiscal ?? 'HOMOLOGACAO'} onChange={set('ambiente_fiscal')} style={iStyle}>
              <option value="HOMOLOGACAO">Homologação</option>
              <option value="PRODUCAO">Produção</option>
            </select>
          </div>
          <div>
            <label style={lStyle}>Série NF</label>
            <input value={form.serie_nf ?? '001'} onChange={set('serie_nf')} style={iStyle} />
          </div>
          <div>
            <label style={lStyle}>Alíquota ISS (%)</label>
            <input type="number" step="0.01" value={form.aliquota_iss ?? '5'} onChange={set('aliquota_iss')} style={iStyle} />
          </div>
          <div>
            <label style={lStyle}>CNAE Principal</label>
            <input value={form.cnae ?? ''} onChange={set('cnae')} style={iStyle} placeholder="4520001" />
          </div>

          <div style={{ gridColumn: '1 / -1' }}>
            <label style={lStyle}>Certificado Digital A1 (.pfx)</label>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' as const, marginBottom: 10 }}>
              {temCertificado && <span style={{ color: 'var(--success)', fontSize: 13 }}>✓ Certificado carregado</span>}
              {certValidade && <span style={{ color: 'var(--muted)', fontSize: 12 }}>Válido até {certValidade.split('-').reverse().join('/')}</span>}
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr auto', gap: 12, alignItems: 'end' }}>
              <div>
                <label style={{ ...lStyle, fontSize: 12 }}>Arquivo .pfx</label>
                <input type="file" accept=".pfx,.p12"
                  onChange={e => setCertFile(e.target.files?.[0] ?? null)}
                  style={{ color: 'var(--muted)', fontSize: 14 }} />
              </div>
              <div>
                <label style={{ ...lStyle, fontSize: 12 }}>Senha do certificado</label>
                <input type="password" value={certSenha} onChange={e => setCertSenha(e.target.value)} style={iStyle} />
              </div>
              <button type="button" onClick={enviarCertificado} disabled={uploadingCert} className="font-display"
                style={{ padding: '10px 20px', background: uploadingCert ? 'var(--muted)' : 'var(--accent)', color: '#000', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 14, cursor: uploadingCert ? 'not-allowed' : 'pointer', whiteSpace: 'nowrap' }}>
                {uploadingCert ? 'Enviando…' : 'Enviar certificado'}
              </button>
            </div>
            <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 6 }}>
              O certificado é validado e armazenado com criptografia AES-256. A senha é guardada cifrada.
            </p>

            <div style={{ marginTop: 16, paddingTop: 16, borderTop: '1px solid var(--border)' }}>
              <button type="button" onClick={ativarEmissao} disabled={ativando || !temCertificado} className="font-display"
                style={{ padding: '10px 24px', background: (ativando || !temCertificado) ? 'var(--border)' : 'var(--success)', color: (ativando || !temCertificado) ? 'var(--muted)' : '#fff', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 15, cursor: (ativando || !temCertificado) ? 'not-allowed' : 'pointer' }}>
                {ativando ? 'Ativando…' : '⚡ Ativar emissão'}
              </button>
              <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 6 }}>
                Registra esta oficina como emissora no provedor fiscal (usa o ambiente configurado acima). Salve os dados da empresa e envie o certificado antes de ativar.
              </p>
            </div>
          </div>
        </div>

        <button onClick={salvar} disabled={saving} className="font-display"
          style={{ marginTop: 24, padding: '10px 28px', background: saving ? 'var(--muted)' : 'var(--accent)', color: '#000', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 16, cursor: saving ? 'not-allowed' : 'pointer' }}>
          {saving ? 'Salvando...' : 'Salvar Empresa'}
        </button>
      </div>
    </div>
  )
}
