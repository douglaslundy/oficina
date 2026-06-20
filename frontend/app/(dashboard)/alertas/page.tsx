'use client'
import { useState, useEffect, useCallback } from 'react'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

interface AlertaConfig {
  id: string
  tipo: string
  nome: string
  pre_definido: boolean
  ativo: boolean
  template_mensagem: string | null
  destinatarios: string[]
  enviar_cliente: boolean
  enviar_mecanico: boolean
}

const TIPO_LABELS: Record<string, string> = {
  ESTOQUE_BAIXO:          '📦 Estoque Baixo',
  ESTOQUE_CRITICO:        '🚨 Estoque Crítico/Zerado',
  CLIENTE_DEVEDOR:        '💸 Cliente com Dívida',
  DIVIDA_VENCIDA:         '🔴 Dívida Vencida',
  OS_NOVA:                '🔧 Nova OS Criada',
  OS_STATUS_MUDOU:        '📋 OS Mudou de Status',
  OS_VENCIDA:             '⏰ OS com Prazo Vencido',
  AGENDAMENTO_CONFIRMADO: '✅ Agendamento Confirmado',
  AGENDAMENTO_LEMBRETE:   '📅 Lembrete de Agendamento',
  PAGAMENTO_RECEBIDO:     '✅ Pagamento Recebido',
  PAGAMENTO_PARCIAL:      '⚡ Pagamento Parcial',
  NF_AUTORIZADA:          '🧾 Nota Fiscal Autorizada',
}

const TIPOS_CUSTOM = [
  'ESTOQUE_BAIXO', 'ESTOQUE_CRITICO', 'CLIENTE_DEVEDOR', 'DIVIDA_VENCIDA',
  'OS_NOVA', 'OS_STATUS_MUDOU', 'OS_VENCIDA', 'AGENDAMENTO_CONFIRMADO',
  'AGENDAMENTO_LEMBRETE', 'PAGAMENTO_RECEBIDO', 'PAGAMENTO_PARCIAL', 'NF_AUTORIZADA',
]

const VARIAVEIS_POR_TIPO: Record<string, string[]> = {
  ESTOQUE_BAIXO:          ['{produto}', '{quantidade}', '{unidade}'],
  ESTOQUE_CRITICO:        ['{produto}', '{quantidade}'],
  CLIENTE_DEVEDOR:        ['{cliente}', '{valor}', '{os_numero}', '{itens}'],
  DIVIDA_VENCIDA:         ['{cliente}', '{valor}', '{os_numero}', '{vencimento}', '{itens}'],
  OS_NOVA:                ['{os_numero}', '{cliente}', '{veiculo}', '{problema}'],
  OS_STATUS_MUDOU:        ['{os_numero}', '{status}', '{cliente}', '{veiculo}'],
  OS_VENCIDA:             ['{os_numero}', '{cliente}', '{vencimento}'],
  AGENDAMENTO_CONFIRMADO: ['{cliente}', '{data}', '{hora}', '{servico}', '{os_numero}'],
  AGENDAMENTO_LEMBRETE:   ['{cliente}', '{data}', '{hora}', '{servico}'],
  PAGAMENTO_RECEBIDO:     ['{os_numero}', '{cliente}', '{valor}', '{forma_pagamento}', '{saldo_devedor}'],
  PAGAMENTO_PARCIAL:      ['{os_numero}', '{cliente}', '{valor}', '{saldo_devedor}'],
  NF_AUTORIZADA:          ['{nf_numero}', '{cliente}', '{valor}', '{chave_acesso}'],
}

interface EditModalProps {
  alerta: AlertaConfig
  onClose: () => void
  onSaved: () => void
}

function EditModal({ alerta, onClose, onSaved }: EditModalProps) {
  const [form, setForm] = useState({
    template_mensagem: alerta.template_mensagem ?? '',
    destinatarios:     (alerta.destinatarios ?? []).join('\n'),
    enviar_cliente:    alerta.enviar_cliente,
    enviar_mecanico:   alerta.enviar_mecanico,
  })
  const [saving, setSaving] = useState(false)

  async function salvar() {
    setSaving(true)
    try {
      const dests = form.destinatarios.split('\n').map(s => s.trim()).filter(Boolean)
      await api.put(`/alertas/${alerta.id}`, {
        template_mensagem: form.template_mensagem,
        destinatarios:     dests,
        enviar_cliente:    form.enviar_cliente,
        enviar_mecanico:   form.enviar_mecanico,
      })
      toast('Alerta atualizado!', 'success')
      onSaved()
      onClose()
    } catch {
      toast('Erro ao salvar alerta.', 'danger')
    } finally { setSaving(false) }
  }

  const variaveis = VARIAVEIS_POR_TIPO[alerta.tipo] ?? []
  const iStyle: React.CSSProperties = { width: '100%', padding: '9px 12px', borderRadius: 7, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.65)', zIndex: 999, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 14, padding: 28, width: 540, maxHeight: '90vh', overflowY: 'auto' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
          <h3 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
            {TIPO_LABELS[alerta.tipo] ?? alerta.nome}
          </h3>
          <button onClick={onClose} style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 22 }}>×</button>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
              Template da mensagem
            </label>
            <textarea
              value={form.template_mensagem}
              onChange={e => setForm(f => ({ ...f, template_mensagem: e.target.value }))}
              rows={4}
              style={{ ...iStyle, resize: 'vertical' as const }}
              placeholder="Ex: ⚠️ Estoque baixo: {produto} — Qtd: {quantidade}"
            />
            {variaveis.length > 0 && (
              <div style={{ marginTop: 6, display: 'flex', flexWrap: 'wrap', gap: 4 }}>
                {variaveis.map(v => (
                  <button key={v} type="button"
                    onClick={() => setForm(f => ({ ...f, template_mensagem: f.template_mensagem + v }))}
                    style={{ padding: '2px 8px', borderRadius: 4, background: 'rgba(245,166,35,.15)', border: '1px solid var(--accent)', color: 'var(--accent)', fontSize: 11, cursor: 'pointer', fontFamily: 'monospace' }}>
                    {v}
                  </button>
                ))}
              </div>
            )}
          </div>

          <div>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
              Destinatários (um número por linha, com DDD)
            </label>
            <textarea
              value={form.destinatarios}
              onChange={e => setForm(f => ({ ...f, destinatarios: e.target.value }))}
              rows={3}
              style={{ ...iStyle, resize: 'vertical' as const }}
              placeholder="35984000000&#10;35999887766"
            />
            <p style={{ fontSize: 11, color: 'var(--muted)', marginTop: 4 }}>Apenas números, com DDD. Ex: 35984000000</p>
          </div>

          <div style={{ display: 'flex', gap: 20 }}>
            <label style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
              <input type="checkbox" checked={form.enviar_cliente}
                onChange={e => setForm(f => ({ ...f, enviar_cliente: e.target.checked }))}
                style={{ width: 16, height: 16, accentColor: 'var(--accent)' }} />
              <span style={{ fontSize: 14 }}>Enviar ao cliente</span>
            </label>
            <label style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
              <input type="checkbox" checked={form.enviar_mecanico}
                onChange={e => setForm(f => ({ ...f, enviar_mecanico: e.target.checked }))}
                style={{ width: 16, height: 16, accentColor: 'var(--accent)' }} />
              <span style={{ fontSize: 14 }}>Enviar ao mecânico</span>
            </label>
          </div>
        </div>

        <div style={{ display: 'flex', gap: 12, marginTop: 20, justifyContent: 'flex-end' }}>
          <button onClick={onClose} style={{ padding: '8px 20px', borderRadius: 7, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>
            Cancelar
          </button>
          <button onClick={salvar} disabled={saving}
            style={{ padding: '8px 24px', borderRadius: 7, background: saving ? 'var(--border)' : 'var(--accent)', color: saving ? 'var(--muted)' : '#000', border: 'none', fontSize: 14, fontWeight: 700, cursor: saving ? 'not-allowed' : 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
            {saving ? '⟳ Salvando...' : 'Salvar'}
          </button>
        </div>
      </div>
    </div>
  )
}

interface CreateModalProps {
  onClose: () => void
  onSaved: () => void
}

function CreateModal({ onClose, onSaved }: CreateModalProps) {
  const [form, setForm] = useState({
    tipo:              TIPOS_CUSTOM[0],
    nome:              '',
    template_mensagem: '',
    destinatarios:     '',
    enviar_cliente:    false,
    enviar_mecanico:   false,
  })
  const [saving, setSaving] = useState(false)

  async function salvar() {
    if (!form.nome.trim() || !form.template_mensagem.trim()) {
      toast('Nome e template são obrigatórios.', 'danger'); return
    }
    setSaving(true)
    try {
      await api.post('/alertas', {
        ...form,
        destinatarios: form.destinatarios.split('\n').map(s => s.trim()).filter(Boolean),
      })
      toast('Alerta criado!', 'success')
      onSaved(); onClose()
    } catch {
      toast('Erro ao criar alerta.', 'danger')
    } finally { setSaving(false) }
  }

  const iStyle: React.CSSProperties = { width: '100%', padding: '9px 12px', borderRadius: 7, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }
  const variaveis = VARIAVEIS_POR_TIPO[form.tipo] ?? []

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.65)', zIndex: 999, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 14, padding: 28, width: 540, maxHeight: '90vh', overflowY: 'auto' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
          <h3 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: 0 }}>Novo Alerta</h3>
          <button onClick={onClose} style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 22 }}>×</button>
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <div>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>Gatilho</label>
            <select value={form.tipo} onChange={e => setForm(f => ({ ...f, tipo: e.target.value }))} style={iStyle}>
              {TIPOS_CUSTOM.map(t => <option key={t} value={t}>{TIPO_LABELS[t] ?? t}</option>)}
            </select>
          </div>
          <div>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>Nome do alerta *</label>
            <input value={form.nome} onChange={e => setForm(f => ({ ...f, nome: e.target.value }))} style={iStyle} placeholder="Ex: Lembrete de cobrança" />
          </div>
          <div>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>Template *</label>
            <textarea value={form.template_mensagem} onChange={e => setForm(f => ({ ...f, template_mensagem: e.target.value }))} rows={3}
              style={{ ...iStyle, resize: 'vertical' as const }} placeholder="Digite a mensagem..." />
            {variaveis.length > 0 && (
              <div style={{ marginTop: 6, display: 'flex', flexWrap: 'wrap', gap: 4 }}>
                {variaveis.map(v => (
                  <button key={v} type="button"
                    onClick={() => setForm(f => ({ ...f, template_mensagem: f.template_mensagem + v }))}
                    style={{ padding: '2px 8px', borderRadius: 4, background: 'rgba(245,166,35,.15)', border: '1px solid var(--accent)', color: 'var(--accent)', fontSize: 11, cursor: 'pointer', fontFamily: 'monospace' }}>
                    {v}
                  </button>
                ))}
              </div>
            )}
          </div>
          <div>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>Destinatários (um por linha)</label>
            <textarea value={form.destinatarios} onChange={e => setForm(f => ({ ...f, destinatarios: e.target.value }))} rows={2}
              style={{ ...iStyle, resize: 'vertical' as const }} placeholder="35984000000" />
          </div>
          <div style={{ display: 'flex', gap: 20 }}>
            <label style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
              <input type="checkbox" checked={form.enviar_cliente} onChange={e => setForm(f => ({ ...f, enviar_cliente: e.target.checked }))} style={{ width: 16, height: 16, accentColor: 'var(--accent)' }} />
              <span style={{ fontSize: 14 }}>Enviar ao cliente</span>
            </label>
            <label style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
              <input type="checkbox" checked={form.enviar_mecanico} onChange={e => setForm(f => ({ ...f, enviar_mecanico: e.target.checked }))} style={{ width: 16, height: 16, accentColor: 'var(--accent)' }} />
              <span style={{ fontSize: 14 }}>Enviar ao mecânico</span>
            </label>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 12, marginTop: 20, justifyContent: 'flex-end' }}>
          <button onClick={onClose} style={{ padding: '8px 20px', borderRadius: 7, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>Cancelar</button>
          <button onClick={salvar} disabled={saving}
            style={{ padding: '8px 24px', borderRadius: 7, background: saving ? 'var(--border)' : 'var(--accent)', color: saving ? 'var(--muted)' : '#000', border: 'none', fontSize: 14, fontWeight: 700, cursor: saving ? 'not-allowed' : 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
            {saving ? '⟳ Criando...' : 'Criar Alerta'}
          </button>
        </div>
      </div>
    </div>
  )
}

export default function AlertasPage() {
  const [alertas, setAlertas]       = useState<AlertaConfig[]>([])
  const [loading, setLoading]       = useState(true)
  const [editando, setEditando]     = useState<AlertaConfig | null>(null)
  const [criando, setCriando]       = useState(false)
  const [toggling, setToggling]     = useState<string | null>(null)

  const fetchAlertas = useCallback(() => {
    setLoading(true)
    api.get<{ data: AlertaConfig[] }>('/alertas')
      .then(r => setAlertas(r.data.data ?? []))
      .catch(() => setAlertas([]))
      .finally(() => setLoading(false))
  }, [])

  useEffect(fetchAlertas, [fetchAlertas])

  async function toggle(id: string) {
    setToggling(id)
    try {
      await api.post(`/alertas/${id}/toggle`)
      setAlertas(prev => prev.map(a => a.id === id ? { ...a, ativo: !a.ativo } : a))
    } catch {
      toast('Erro ao atualizar alerta.', 'danger')
    } finally { setToggling(null) }
  }

  async function remover(id: string, nome: string) {
    if (!confirm(`Remover alerta "${nome}"?`)) return
    try {
      await api.delete(`/alertas/${id}`)
      toast('Alerta removido.', 'success')
      fetchAlertas()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast(msg ?? 'Erro ao remover.', 'danger')
    }
  }

  const predefinidos = alertas.filter(a => a.pre_definido)
  const customizados = alertas.filter(a => !a.pre_definido)

  return (
    <div>
      {editando && <EditModal alerta={editando} onClose={() => setEditando(null)} onSaved={fetchAlertas} />}
      {criando  && <CreateModal onClose={() => setCriando(false)} onSaved={fetchAlertas} />}

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 24 }}>
        <div>
          <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
            Alertas WhatsApp
          </h1>
          <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
            Configure quais alertas serão enviados via WhatsApp
          </p>
        </div>
        <button onClick={() => setCriando(true)}
          className="font-display"
          style={{ padding: '9px 20px', borderRadius: 8, background: 'var(--accent)', color: '#000', border: 'none', fontSize: 14, fontWeight: 800, cursor: 'pointer' }}>
          + Novo Alerta
        </button>
      </div>

      {loading ? (
        <div style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Carregando...</div>
      ) : (
        <>
          {/* Pré-definidos */}
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden', marginBottom: 24 }}>
            <div style={{ padding: '14px 20px', borderBottom: '1px solid var(--border)' }}>
              <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--text)' }}>Alertas do Sistema</div>
              <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2 }}>Ative/desative e personalize a mensagem de cada alerta</div>
            </div>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <tbody>
                {predefinidos.map((alerta, i) => (
                  <tr key={alerta.id} style={{ borderBottom: i < predefinidos.length - 1 ? '1px solid var(--border)' : undefined }}>
                    <td style={{ padding: '12px 20px', width: 44 }}>
                      <button
                        onClick={() => toggle(alerta.id)}
                        disabled={toggling === alerta.id}
                        style={{
                          width: 44, height: 24, borderRadius: 999, border: 'none', cursor: 'pointer',
                          background: alerta.ativo ? 'var(--success)' : 'var(--border)',
                          transition: 'background 0.2s', position: 'relative',
                          opacity: toggling === alerta.id ? 0.6 : 1,
                        }}
                        title={alerta.ativo ? 'Clique para desativar' : 'Clique para ativar'}
                      >
                        <span style={{
                          position: 'absolute', top: 3, left: alerta.ativo ? 22 : 3,
                          width: 18, height: 18, borderRadius: '50%', background: '#fff',
                          transition: 'left 0.2s', display: 'block',
                        }} />
                      </button>
                    </td>
                    <td style={{ padding: '12px 0' }}>
                      <div style={{ fontSize: 14, fontWeight: 600, color: 'var(--text)' }}>
                        {TIPO_LABELS[alerta.tipo] ?? alerta.nome}
                      </div>
                      {alerta.template_mensagem && (
                        <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2, maxWidth: 380, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                          {alerta.template_mensagem}
                        </div>
                      )}
                    </td>
                    <td style={{ padding: '12px 20px', textAlign: 'right' }}>
                      <button onClick={() => setEditando(alerta)}
                        style={{ padding: '5px 14px', borderRadius: 6, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer', fontSize: 13 }}>
                        ✏️ Editar
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Customizados */}
          {customizados.length > 0 && (
            <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
              <div style={{ padding: '14px 20px', borderBottom: '1px solid var(--border)' }}>
                <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--text)' }}>Alertas Customizados</div>
              </div>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <tbody>
                  {customizados.map((alerta, i) => (
                    <tr key={alerta.id} style={{ borderBottom: i < customizados.length - 1 ? '1px solid var(--border)' : undefined }}>
                      <td style={{ padding: '12px 20px', width: 44 }}>
                        <button onClick={() => toggle(alerta.id)} disabled={toggling === alerta.id}
                          style={{ width: 44, height: 24, borderRadius: 999, border: 'none', cursor: 'pointer', background: alerta.ativo ? 'var(--success)' : 'var(--border)', transition: 'background 0.2s', position: 'relative', opacity: toggling === alerta.id ? 0.6 : 1 }}>
                          <span style={{ position: 'absolute', top: 3, left: alerta.ativo ? 22 : 3, width: 18, height: 18, borderRadius: '50%', background: '#fff', transition: 'left 0.2s', display: 'block' }} />
                        </button>
                      </td>
                      <td style={{ padding: '12px 0' }}>
                        <div style={{ fontSize: 14, fontWeight: 600, color: 'var(--text)' }}>{alerta.nome}</div>
                        <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 1 }}>
                          Gatilho: {TIPO_LABELS[alerta.tipo] ?? alerta.tipo}
                        </div>
                      </td>
                      <td style={{ padding: '12px 20px', textAlign: 'right' }}>
                        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                          <button onClick={() => setEditando(alerta)}
                            style={{ padding: '5px 14px', borderRadius: 6, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer', fontSize: 13 }}>
                            ✏️ Editar
                          </button>
                          <button onClick={() => remover(alerta.id, alerta.nome)}
                            style={{ padding: '5px 14px', borderRadius: 6, background: 'transparent', border: '1px solid var(--danger)', color: 'var(--danger)', cursor: 'pointer', fontSize: 13 }}>
                            🗑
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {alertas.length === 0 && (
            <div style={{ padding: 40, textAlign: 'center', color: 'var(--muted)', fontSize: 14 }}>
              Nenhum alerta encontrado. Configure o WhatsApp primeiro.
            </div>
          )}
        </>
      )}
    </div>
  )
}
