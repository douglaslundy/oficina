'use client'
import { useState, useEffect } from 'react'
import Link from 'next/link'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

export default function ConfiguracoesPage() {
  const [form, setForm] = useState({
    estoque_limite_padrao: 5,
    alertas_email: true,
    email_alertas: '',
    markup_padrao_entrada_nf: 40,
    atualizar_custo_entrada_nf: true,
    impressora_cupom: '80MM',
    tipo_cupom: 'FISCAL',
    imprimir_automaticamente: false,
    percentual_tributos_aproximados: '' as string | number,
  })
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api.get('/configuracoes').then(r => {
      const d = r.data
      setForm({
        estoque_limite_padrao: d.estoque_limite_padrao ?? 5,
        alertas_email: d.alertas_email ?? true,
        email_alertas: d.email_alertas ?? '',
        markup_padrao_entrada_nf: d.markup_padrao_entrada_nf ?? 40,
        atualizar_custo_entrada_nf: d.atualizar_custo_entrada_nf ?? true,
        impressora_cupom: d.impressora_cupom ?? '80MM',
        tipo_cupom: d.tipo_cupom ?? 'FISCAL',
        imprimir_automaticamente: d.imprimir_automaticamente ?? false,
        percentual_tributos_aproximados: d.percentual_tributos_aproximados ?? '',
      })
    }).catch(() => {})
  }, [])

  async function salvar() {
    setSaving(true)
    try {
      await api.put('/configuracoes', {
        ...form,
        percentual_tributos_aproximados: form.percentual_tributos_aproximados === '' ? null : Number(form.percentual_tributos_aproximados),
      })
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
        <div style={{ marginBottom: 24 }}>
          <label style={lStyle}>Markup padrão para produtos novos na entrada de NF (%)</label>
          <input type="number" min={0} step="0.1" value={form.markup_padrao_entrada_nf}
            onChange={e => setForm(f => ({ ...f, markup_padrao_entrada_nf: +e.target.value }))}
            style={{ ...iStyle, width: 120 }} />
          <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 4 }}>
            Usado para sugerir o preço de venda de produtos criados ao importar uma nota fiscal de compra.
          </p>
        </div>
        <div style={{ marginBottom: 24 }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}>
            <input type="checkbox" checked={form.atualizar_custo_entrada_nf}
              onChange={e => setForm(f => ({ ...f, atualizar_custo_entrada_nf: e.target.checked }))} />
            <span style={{ color: 'var(--text)', fontSize: 14 }}>Atualizar o custo do produto ao lançar entrada por NF</span>
          </label>
        </div>

        <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 16, marginTop: 24 }}>Impressão do cupom</h3>
        <div style={{ marginBottom: 20 }}>
          <label htmlFor="impressora_cupom" style={lStyle}>Impressora usada para o cupom</label>
          <select id="impressora_cupom" value={form.impressora_cupom}
            onChange={e => setForm(f => ({ ...f, impressora_cupom: e.target.value }))}
            style={{ ...iStyle, width: '100%', boxSizing: 'border-box' as const }}>
            <option value="80MM">Térmica de bobina — 80 mm</option>
            <option value="A4">Folha A4 (jato de tinta / laser)</option>
          </select>
          <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 4 }}>
            Vale para o DANFE NFC-e e para o cupom não fiscal. Na térmica, o cupom sai com a altura exata do conteúdo.
          </p>
        </div>
        <div style={{ marginBottom: 20 }}>
          <label htmlFor="tipo_cupom" style={lStyle}>Tipo de cupom</label>
          <select id="tipo_cupom" value={form.tipo_cupom}
            onChange={e => setForm(f => ({ ...f, tipo_cupom: e.target.value }))}
            style={{ ...iStyle, width: '100%', boxSizing: 'border-box' as const }}>
            <option value="FISCAL">Cupom fiscal — DANFE NFC-e</option>
            <option value="NAO_FISCAL">Cupom não fiscal — só os dados da venda</option>
          </select>
          <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 4 }}>
            O cupom não fiscal sai marcado &quot;SEM VALOR FISCAL&quot;, apenas com itens, valores e pagamento da venda.
            O cupom fiscal só pode ser impresso depois que a NFC-e é autorizada.
          </p>
        </div>
        <div style={{ marginBottom: 20 }}>
          <label htmlFor="pct_tributos" style={lStyle}>Percentual aproximado de tributos (%) — Lei 12.741/2012</label>
          <input id="pct_tributos" type="number" min={0} max={100} step="0.01" value={form.percentual_tributos_aproximados}
            onChange={e => setForm(f => ({ ...f, percentual_tributos_aproximados: e.target.value }))}
            style={{ ...iStyle, width: 120 }} placeholder="Ex.: 31,5" />
          <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 4 }}>
            Informe o percentual médio fornecido pelo seu contador (tabela IBPT). Ele preenche o valor dos tributos no cupom NFC-e e,
            no motor NFePHP, também no XML. Em branco, a informação não é impressa.
          </p>
        </div>
        <div style={{ marginBottom: 24 }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}>
            <input type="checkbox" checked={form.imprimir_automaticamente}
              onChange={e => setForm(f => ({ ...f, imprimir_automaticamente: e.target.checked }))} />
            <span style={{ color: 'var(--text)', fontSize: 14 }}>Imprimir o cupom automaticamente ao emitir a NFC-e / concluir a venda</span>
          </label>
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

        <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 8, padding: 16, marginTop: 16 }}>
          <h3 style={{ fontFamily: 'Barlow Condensed', fontWeight: 700, fontSize: 16, margin: '0 0 4px' }}>
            Padrões fiscais por categoria
          </h3>
          <p style={{ fontSize: 13, color: 'var(--muted)', margin: '0 0 12px' }}>
            NCM, origem e tributação usados como ponto de partida para produtos cadastrados manualmente.
          </p>
          <Link
            href="/configuracoes/categorias-fiscais"
            style={{ padding: '8px 14px', fontSize: 13, color: 'var(--text)', border: '1px solid var(--border)', borderRadius: 6, textDecoration: 'none', display: 'inline-block' }}
          >
            Configurar
          </Link>
        </div>
      </div>
    </div>
  )
}
