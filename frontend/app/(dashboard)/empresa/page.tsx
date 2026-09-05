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

  const [inutSerie, setInutSerie] = useState('')
  const [inutInicial, setInutInicial] = useState('')
  const [inutFinal, setInutFinal] = useState('')
  const [inutJustificativa, setInutJustificativa] = useState('')
  const [inutilizando, setInutilizando] = useState(false)
  // Finding 6 do fix wave pós-revisão da Etapa C2 (2026-08-11): inutilização
  // é irreversível junto à SEFAZ (fecha a faixa de números pra sempre) —
  // mesma classe de ação do cancelamento de NF em fiscal/historico/page.tsx,
  // que já usa um modal de confirmação em vez de disparar a chamada direto
  // no clique do botão. Replicado aqui: `inutModal` só guarda um booleano
  // "há uma confirmação pendente" (os valores em si já vivem nos estados
  // inutSerie/inutInicial/inutFinal/inutJustificativa acima, únicos também
  // usados na chamada real).
  const [inutModal, setInutModal] = useState(false)

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

  // Valida os campos e abre o modal de confirmação — a chamada real fica em
  // confirmarInutilizacao(), disparada só pelo botão "Confirmar" do modal.
  function inutilizarNumeracao() {
    if (!inutSerie || !inutInicial || !inutFinal) { toast('Preencha série, número inicial e número final.', 'danger'); return }
    if (Number(inutFinal) < Number(inutInicial)) { toast('Número final não pode ser menor que o inicial.', 'danger'); return }
    if (inutJustificativa.trim().length < 15) { toast('Justificativa precisa ter pelo menos 15 caracteres.', 'danger'); return }

    setInutModal(true)
  }

  async function confirmarInutilizacao() {
    setInutilizando(true)
    try {
      const r = await api.post('/notas-fiscais/inutilizar-numeracao', {
        serie: Number(inutSerie),
        numero_inicial: Number(inutInicial),
        numero_final: Number(inutFinal),
        justificativa: inutJustificativa,
      })
      toast(r.data.message ?? 'Faixa de numeração inutilizada com sucesso!', 'success')
      setInutModal(false)
      setInutSerie('')
      setInutInicial('')
      setInutFinal('')
      setInutJustificativa('')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast(msg ?? 'Erro ao inutilizar numeração.', 'danger')
    } finally {
      setInutilizando(false)
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
    ['logradouro', 'Logradouro (rua/av.)', ''],
    ['numero', 'Número', ''],
    ['bairro', 'Bairro', ''],
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
            <label style={lStyle}>Cálculo da tributação (CFOP / CST / ICMS / ISS)</label>
            <select value={form.calculo_tributario_modo ?? 'MANUAL'} onChange={set('calculo_tributario_modo')} style={iStyle}>
              <option value="MANUAL">Manual — o sistema calcula</option>
              <option value="AUTOMATICO_PROVEDOR">Automático pelo provedor (Spedy)</option>
            </select>
            {form.calculo_tributario_modo === 'AUTOMATICO_PROVEDOR' && (
              <p style={{ color: 'var(--accent)', fontSize: 12, marginTop: 6, lineHeight: 1.5 }}>
                A Spedy calcula CFOP/CST/ICMS/ISS a partir da configuração fiscal da sua empresa no
                painel dela. Exige: certificado A1 enviado à Spedy + regime tributário e grupos de
                tributação configurados no painel web da Spedy. Funciona melhor para catálogos
                fiscais simples. A Focus ainda não suporta este modo.
              </p>
            )}
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
                <label
                  htmlFor="cert-file-input"
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 10,
                    background: 'var(--bg)',
                    border: `1px dashed ${certFile ? 'var(--success)' : 'var(--border)'}`,
                    borderRadius: 8,
                    padding: '9px 12px',
                    cursor: 'pointer',
                    transition: 'border-color 0.15s',
                    minHeight: 42,
                  }}
                  onMouseEnter={e => (e.currentTarget.style.borderColor = 'var(--accent)')}
                  onMouseLeave={e => (e.currentTarget.style.borderColor = certFile ? 'var(--success)' : 'var(--border)')}
                >
                  <span style={{ fontSize: 18, lineHeight: 1 }}>{certFile ? '✓' : '📎'}</span>
                  <span style={{
                    fontSize: 13,
                    color: certFile ? 'var(--success)' : 'var(--muted)',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                    maxWidth: 180,
                  }}>
                    {certFile ? certFile.name : 'Selecionar arquivo .pfx'}
                  </span>
                </label>
                <input
                  id="cert-file-input"
                  type="file"
                  accept=".pfx,.p12"
                  onChange={e => setCertFile(e.target.files?.[0] ?? null)}
                  style={{ display: 'none' }}
                />
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

      {/* Ação administrativa pontual, uso raro: fecha uma faixa de numeração
          de NF-e que ficou sem uso (ex.: queda de processo entre alocar o
          número e transmitir). Deliberadamente separada do card principal —
          não é dado cadastral da empresa. */}
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 28, marginTop: 24 }}>
        <p style={{ color: 'var(--muted)', fontSize: 12, fontWeight: 600, textTransform: 'uppercase' as const, letterSpacing: '0.06em', margin: '0 0 4px' }}>
          Ação administrativa · NF-e
        </p>
        <h2 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: '0 0 6px' }}>
          Inutilização de Numeração
        </h2>
        <p style={{ color: 'var(--muted)', fontSize: 13, margin: '0 0 16px' }}>
          Fecha junto à SEFAZ uma faixa de números de NF-e que nunca chegou a ser transmitida (ex.: falha do sistema entre alocar o número e enviar). Use apenas quando tiver certeza de que os números da faixa não serão reaproveitados.
        </p>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 16 }}>
          <div>
            <label style={lStyle}>Série</label>
            <input type="number" min={1} value={inutSerie} onChange={e => setInutSerie(e.target.value)} style={iStyle} placeholder="1" />
          </div>
          <div>
            <label style={lStyle}>Número inicial</label>
            <input type="number" min={1} value={inutInicial} onChange={e => setInutInicial(e.target.value)} style={iStyle} />
          </div>
          <div>
            <label style={lStyle}>Número final</label>
            <input type="number" min={1} value={inutFinal} onChange={e => setInutFinal(e.target.value)} style={iStyle} />
          </div>
          <div style={{ gridColumn: '1 / -1' }}>
            <label style={lStyle}>Justificativa (mínimo 15 caracteres)</label>
            <textarea value={inutJustificativa} onChange={e => setInutJustificativa(e.target.value)} style={{ ...iStyle, minHeight: 70, resize: 'vertical' as const, fontFamily: 'inherit' }} />
          </div>
        </div>
        <button type="button" onClick={inutilizarNumeracao} disabled={inutilizando} className="font-display"
          style={{ marginTop: 16, padding: '10px 24px', background: inutilizando ? 'var(--muted)' : 'var(--danger)', color: '#fff', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 14, cursor: inutilizando ? 'not-allowed' : 'pointer' }}>
          {inutilizando ? 'Inutilizando…' : 'Inutilizar faixa'}
        </button>
      </div>

      {/* Modal de confirmação de inutilização — mesmo padrão do modal de
          cancelamento de NF em fiscal/historico/page.tsx (Finding 6 do fix
          wave): ação irreversível junto à SEFAZ, não dispara direto no
          clique do botão. */}
      {inutModal && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.6)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, padding: 32, width: 440, maxWidth: '90vw' }}>
            <h3 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', marginBottom: 8 }}>
              Confirmar Inutilização de Numeração
            </h3>
            <p style={{ color: 'var(--muted)', fontSize: 14, marginBottom: 20 }}>
              Esta ação fecha a faixa junto à SEFAZ e não pode ser desfeita. Confira os dados antes de continuar.
            </p>
            <div style={{ background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, padding: '12px 16px', marginBottom: 20 }}>
              <p style={{ color: 'var(--text)', fontSize: 14, margin: '0 0 6px' }}>
                Série <strong>{inutSerie}</strong> · Números <strong>{inutInicial}</strong> a <strong>{inutFinal}</strong>
              </p>
              <p style={{ color: 'var(--muted)', fontSize: 13, margin: 0 }}>
                {inutJustificativa}
              </p>
            </div>
            <div style={{ display: 'flex', gap: 12, marginTop: 24, justifyContent: 'flex-end' }}>
              <button
                onClick={() => setInutModal(false)}
                disabled={inutilizando}
                style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 8, padding: '8px 20px', cursor: 'pointer', fontSize: 14 }}
              >
                Voltar
              </button>
              <button
                onClick={confirmarInutilizacao}
                disabled={inutilizando}
                style={{
                  background: 'var(--danger)', color: '#fff', border: 'none', borderRadius: 8,
                  padding: '8px 20px', fontSize: 14,
                  cursor: inutilizando ? 'not-allowed' : 'pointer',
                  opacity: inutilizando ? 0.6 : 1,
                }}
              >
                {inutilizando ? 'Inutilizando...' : 'Confirmar Inutilização'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
