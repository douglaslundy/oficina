'use client'
import { useState, useEffect } from 'react'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

export default function ConfiguracoesPage() {
  const [form, setForm] = useState({
    estoque_limite_padrao: 5,
    alertas_email: true,
    email_alertas: '',
  })
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api.get('/configuracoes').then(r => {
      const d = r.data
      setForm({
        estoque_limite_padrao: d.estoque_limite_padrao ?? 5,
        alertas_email: d.alertas_email ?? true,
        email_alertas: d.email_alertas ?? '',
      })
    }).catch(() => {})
  }, [])

  async function salvar() {
    setSaving(true)
    try {
      await api.put('/configuracoes', form)
      toast('Configurações salvas!', 'success')
    } catch {
      toast('Erro ao salvar.', 'danger')
    } finally { setSaving(false) }
  }

  const iStyle: React.CSSProperties = {
    padding: '9px 12px', borderRadius: 8, background: 'var(--bg)',
    border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, outline: 'none',
  }
  const lStyle: React.CSSProperties = { color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }

  return (
    <div style={{ maxWidth: 560, margin: '0 auto' }}>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Configurações</h1>
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 28 }}>

        <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 16 }}>Estoque</h3>
        <div style={{ marginBottom: 24 }}>
          <label style={lStyle}>Limite padrão de alerta (unidades)</label>
          <input type="number" min={0} value={form.estoque_limite_padrao}
            onChange={e => setForm(f => ({ ...f, estoque_limite_padrao: +e.target.value }))}
            style={{ ...iStyle, width: 120 }} />
          <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 4 }}>
            Produtos com estoque abaixo deste valor receberão alerta.
          </p>
        </div>

        <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 16, marginTop: 24 }}>Notificações</h3>
        <div style={{ marginBottom: 16 }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}>
            <input type="checkbox" checked={form.alertas_email}
              onChange={e => setForm(f => ({ ...f, alertas_email: e.target.checked }))} />
            <span style={{ color: 'var(--text)', fontSize: 14 }}>Enviar alertas de estoque por e-mail</span>
          </label>
        </div>
        {form.alertas_email && (
          <div style={{ marginBottom: 20 }}>
            <label style={lStyle}>E-mail para alertas</label>
            <input type="email" value={form.email_alertas}
              onChange={e => setForm(f => ({ ...f, email_alertas: e.target.value }))}
              style={{ ...iStyle, width: '100%', boxSizing: 'border-box' as const }}
              placeholder="alertas@suaoficina.com.br" />
          </div>
        )}

        <button onClick={salvar} disabled={saving} className="font-display"
          style={{ marginTop: 8, padding: '10px 28px', background: saving ? 'var(--muted)' : 'var(--accent)', color: '#000', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 16, cursor: saving ? 'not-allowed' : 'pointer' }}>
          {saving ? 'Salvando...' : 'Salvar Configurações'}
        </button>
      </div>
    </div>
  )
}
