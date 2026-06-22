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
  emails: string[]
  canais: string[]
  enviar_cliente: boolean
  enviar_mecanico: boolean
  condicoes?: { status_alvo?: string[] } | null
}

interface Entitlements {
  whatsapp: boolean
  email: boolean
}

// Seletor de canais — só permite marcar o que o plano libera.
function CanaisSelector({ canais, setCanais, ent }: {
  canais: { whatsapp: boolean; email: boolean }
  setCanais: (c: { whatsapp: boolean; email: boolean }) => void
  ent: Entitlements
}) {
  const lockMsg = 'Funcionalidade não faz parte do seu plano, contate o administrador do seu plano e contrate.'
  return (
    <div>
      <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
        Canais de envio
      </label>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
        {([
          { key: 'whatsapp' as const, label: '💬 WhatsApp', allowed: ent.whatsapp },
          { key: 'email' as const,    label: '✉️ E-mail',  allowed: ent.email },
        ]).map(opt => (
          <div key={opt.key}>
            <label style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: opt.allowed ? 'pointer' : 'not-allowed', opacity: opt.allowed ? 1 : 0.5 }}>
              <input type="checkbox" disabled={!opt.allowed}
                checked={opt.allowed && canais[opt.key]}
                onChange={e => setCanais({ ...canais, [opt.key]: e.target.checked })}
                style={{ width: 16, height: 16, accentColor: 'var(--accent)' }} />
              <span style={{ fontSize: 14 }}>{opt.label}</span>
            </label>
            {!opt.allowed && (
              <p style={{ fontSize: 11, color: 'var(--danger)', margin: '2px 0 0 24px' }}>{lockMsg}</p>
            )}
          </div>
        ))}
      </div>
    </div>
  )
}

// Seletor de status-alvo (condição do gatilho de mudança de status da OS).
function StatusAlvoSelector({ value, onChange }: {
  value: string[]
  onChange: (v: string[]) => void
}) {
  function toggle(s: string) {
    onChange(value.includes(s) ? value.filter(x => x !== s) : [...value, s])
  }
  return (
    <div>
      <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
        Disparar quando a OS virar
      </label>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
        {STATUS_OS_OPCOES.map(opt => {
          const on = value.includes(opt.value)
          return (
            <button key={opt.value} type="button" onClick={() => toggle(opt.value)}
              style={{
                padding: '6px 12px', borderRadius: 999, fontSize: 13, cursor: 'pointer',
                border: `1px solid ${on ? 'var(--accent)' : 'var(--border)'}`,
                background: on ? 'rgba(245,166,35,.15)' : 'transparent',
                color: on ? 'var(--accent)' : 'var(--muted)', fontWeight: on ? 700 : 400,
              }}>
              {on ? '✓ ' : ''}{opt.label}
            </button>
          )
        })}
      </div>
      <p style={{ fontSize: 11, color: 'var(--muted)', margin: '6px 0 0' }}>
        Nenhum selecionado = dispara em qualquer mudança de status.
      </p>
    </div>
  )
}

// Máscara de telefone brasileiro: (XX) XXXXX-XXXX
function formatTel(v: string): string {
  const d = v.replace(/\D/g, '').slice(0, 11)
  if (d.length <= 2) return d
  if (d.length <= 6) return `(${d.slice(0, 2)}) ${d.slice(2)}`
  if (d.length <= 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`
  return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`
}

// Input com máscara + botão para adicionar telefones (chips) ao alerta.
function TelefonesInput({ telefones, setTelefones }: {
  telefones: string[]
  setTelefones: (t: string[]) => void
}) {
  const [input, setInput] = useState('')

  function adicionar() {
    const d = input.replace(/\D/g, '')
    if (d.length < 10 || d.length > 11) {
      toast('Telefone inválido. Informe DDD + número.', 'danger'); return
    }
    if (!telefones.includes(d)) setTelefones([...telefones, d])
    setInput('')
  }

  const inp: React.CSSProperties = {
    flex: 1, padding: '9px 12px', borderRadius: 7, background: 'var(--bg)',
    border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box',
  }

  return (
    <div>
      <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
        Telefones (WhatsApp)
      </label>
      <div style={{ display: 'flex', gap: 8 }}>
        <input
          value={formatTel(input)}
          onChange={e => setInput(e.target.value)}
          onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); adicionar() } }}
          placeholder="(11) 99999-8888"
          style={inp}
        />
        <button type="button" onClick={adicionar}
          style={{ padding: '0 16px', borderRadius: 7, background: 'var(--accent)', color: '#000', border: 'none', fontSize: 13, fontWeight: 700, cursor: 'pointer', whiteSpace: 'nowrap' }}>
          + Adicionar
        </button>
      </div>
      {telefones.length > 0 && (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 8 }}>
          {telefones.map(t => (
            <span key={t} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 999, padding: '4px 10px', fontSize: 13 }}>
              📱 {formatTel(t)}
              <button type="button" onClick={() => setTelefones(telefones.filter(x => x !== t))}
                style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 15, lineHeight: 1, padding: 0 }}>×</button>
            </span>
          ))}
        </div>
      )}
    </div>
  )
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
  ORCAMENTO_APROVADO:     '✅ Orçamento Aprovado',
  ORCAMENTO_RECUSADO:     '❌ Orçamento Recusado',
}

// Eventos agrupados por entidade — base do construtor de gatilhos.
const ENTIDADES: { entidade: string; eventos: string[] }[] = [
  { entidade: 'Ordem de Serviço', eventos: ['OS_NOVA', 'OS_STATUS_MUDOU', 'OS_VENCIDA'] },
  { entidade: 'Pagamento',        eventos: ['PAGAMENTO_RECEBIDO', 'PAGAMENTO_PARCIAL'] },
  { entidade: 'Nota Fiscal',      eventos: ['NF_AUTORIZADA'] },
  { entidade: 'Agendamento',      eventos: ['AGENDAMENTO_CONFIRMADO', 'AGENDAMENTO_LEMBRETE'] },
  { entidade: 'Estoque',          eventos: ['ESTOQUE_BAIXO', 'ESTOQUE_CRITICO'] },
  { entidade: 'Cliente',          eventos: ['CLIENTE_DEVEDOR', 'DIVIDA_VENCIDA'] },
]

// Status-alvo selecionáveis na condição de mudança de status da OS.
const STATUS_OS_OPCOES: { value: string; label: string }[] = [
  { value: 'ABERTA',           label: 'Aberta' },
  { value: 'EM_ANDAMENTO',     label: 'Em Andamento' },
  { value: 'AGUARDANDO_PECAS', label: 'Aguardando Peças' },
  { value: 'CONCLUIDA',        label: 'Concluída' },
  { value: 'CANCELADA',        label: 'Cancelada' },
]

// Eventos que suportam filtro por status-alvo.
function suportaStatusAlvo(tipo: string): boolean {
  return tipo === 'OS_STATUS_MUDOU'
}

function statusAlvoLabel(values?: string[]): string {
  if (!values || values.length === 0) return 'qualquer status'
  const map = Object.fromEntries(STATUS_OS_OPCOES.map(o => [o.value, o.label]))
  return values.map(v => map[v] ?? v).join(', ')
}

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
  ORCAMENTO_APROVADO:     ['{cliente}', '{os_numero}', '{valor}', '{servicos_aprovados}'],
  ORCAMENTO_RECUSADO:     ['{cliente}', '{os_numero}'],
}

interface EditModalProps {
  alerta: AlertaConfig
  ent: Entitlements
  onClose: () => void
  onSaved: () => void
}

function EditModal({ alerta, ent, onClose, onSaved }: EditModalProps) {
  const [form, setForm] = useState({
    template_mensagem: alerta.template_mensagem ?? '',
    emails:            (alerta.emails ?? []).join('\n'),
    enviar_cliente:    alerta.enviar_cliente,
    enviar_mecanico:   alerta.enviar_mecanico,
  })
  const [telefones, setTelefones] = useState<string[]>(
    (alerta.destinatarios ?? []).map(t => t.replace(/\D/g, '')).filter(Boolean)
  )
  const [canais, setCanais] = useState({
    whatsapp: (alerta.canais ?? ['WHATSAPP']).includes('WHATSAPP'),
    email:    (alerta.canais ?? []).includes('EMAIL'),
  })
  const [statusAlvo, setStatusAlvo] = useState<string[]>(alerta.condicoes?.status_alvo ?? [])
  const [saving, setSaving] = useState(false)

  async function salvar() {
    const canaisArr = [
      ...(canais.whatsapp && ent.whatsapp ? ['WHATSAPP'] : []),
      ...(canais.email && ent.email ? ['EMAIL'] : []),
    ]
    if (canaisArr.length === 0) {
      toast('Selecione ao menos um canal disponível no seu plano.', 'danger'); return
    }
    setSaving(true)
    try {
      const emails = form.emails.split('\n').map(s => s.trim()).filter(Boolean)
      await api.put(`/alertas/${alerta.id}`, {
        template_mensagem: form.template_mensagem,
        destinatarios:     telefones,
        emails,
        canais:            canaisArr,
        enviar_cliente:    form.enviar_cliente,
        enviar_mecanico:   form.enviar_mecanico,
        // Só envia condicoes para eventos que a suportam (null = limpa o filtro).
        ...(suportaStatusAlvo(alerta.tipo)
          ? { condicoes: statusAlvo.length > 0 ? { status_alvo: statusAlvo } : null }
          : {}),
      })
      toast('Alerta atualizado!', 'success')
      onSaved()
      onClose()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast(msg ?? 'Erro ao salvar alerta.', 'danger')
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

          {suportaStatusAlvo(alerta.tipo) && (
            <StatusAlvoSelector value={statusAlvo} onChange={setStatusAlvo} />
          )}

          <TelefonesInput telefones={telefones} setTelefones={setTelefones} />

          <CanaisSelector canais={canais} setCanais={setCanais} ent={ent} />

          {canais.email && ent.email && (
            <div>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
                E-mails (um por linha)
              </label>
              <textarea value={form.emails} onChange={e => setForm(f => ({ ...f, emails: e.target.value }))} rows={2}
                style={{ ...iStyle, resize: 'vertical' as const }} placeholder="contato@cliente.com" />
            </div>
          )}

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
  ent: Entitlements
  onClose: () => void
  onSaved: () => void
}

function CreateModal({ ent, onClose, onSaved }: CreateModalProps) {
  const [entidade, setEntidade] = useState(ENTIDADES[0].entidade)
  const [form, setForm] = useState({
    tipo:              ENTIDADES[0].eventos[0],
    nome:              '',
    template_mensagem: '',
    emails:            '',
    enviar_cliente:    false,
    enviar_mecanico:   false,
  })
  const [statusAlvo, setStatusAlvo] = useState<string[]>([])
  const [telefones, setTelefones] = useState<string[]>([])
  // Pré-seleciona o primeiro canal disponível no plano.
  const [canais, setCanais] = useState({ whatsapp: ent.whatsapp, email: ent.email && !ent.whatsapp })
  const [saving, setSaving] = useState(false)

  const eventosDaEntidade = ENTIDADES.find(e => e.entidade === entidade)?.eventos ?? []

  function trocarEntidade(nova: string) {
    setEntidade(nova)
    const primeiro = ENTIDADES.find(e => e.entidade === nova)?.eventos[0] ?? ''
    setForm(f => ({ ...f, tipo: primeiro }))
    setStatusAlvo([])
  }

  function trocarEvento(tipo: string) {
    setForm(f => ({ ...f, tipo }))
    setStatusAlvo([])
  }

  async function salvar() {
    if (!form.nome.trim() || !form.template_mensagem.trim()) {
      toast('Nome e template são obrigatórios.', 'danger'); return
    }
    const canaisArr = [
      ...(canais.whatsapp && ent.whatsapp ? ['WHATSAPP'] : []),
      ...(canais.email && ent.email ? ['EMAIL'] : []),
    ]
    if (canaisArr.length === 0) {
      toast('Selecione ao menos um canal disponível no seu plano.', 'danger'); return
    }
    setSaving(true)
    try {
      await api.post('/alertas', {
        tipo:              form.tipo,
        nome:              form.nome,
        template_mensagem: form.template_mensagem,
        enviar_cliente:    form.enviar_cliente,
        enviar_mecanico:   form.enviar_mecanico,
        canais:            canaisArr,
        destinatarios:     telefones,
        emails:            form.emails.split('\n').map(s => s.trim()).filter(Boolean),
        condicoes:         suportaStatusAlvo(form.tipo) && statusAlvo.length > 0 ? { status_alvo: statusAlvo } : null,
      })
      toast('Alerta criado!', 'success')
      onSaved(); onClose()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast(msg ?? 'Erro ao criar alerta.', 'danger')
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
          <div style={{ display: 'flex', gap: 12 }}>
            <div style={{ flex: 1 }}>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>Entidade</label>
              <select value={entidade} onChange={e => trocarEntidade(e.target.value)} style={iStyle}>
                {ENTIDADES.map(en => <option key={en.entidade} value={en.entidade}>{en.entidade}</option>)}
              </select>
            </div>
            <div style={{ flex: 1 }}>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>Evento (gatilho)</label>
              <select value={form.tipo} onChange={e => trocarEvento(e.target.value)} style={iStyle}>
                {eventosDaEntidade.map(t => <option key={t} value={t}>{TIPO_LABELS[t] ?? t}</option>)}
              </select>
            </div>
          </div>

          {suportaStatusAlvo(form.tipo) && (
            <StatusAlvoSelector value={statusAlvo} onChange={setStatusAlvo} />
          )}
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
          <TelefonesInput telefones={telefones} setTelefones={setTelefones} />

          <CanaisSelector canais={canais} setCanais={setCanais} ent={ent} />

          {canais.email && ent.email && (
            <div>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>E-mails (um por linha)</label>
              <textarea value={form.emails} onChange={e => setForm(f => ({ ...f, emails: e.target.value }))} rows={2}
                style={{ ...iStyle, resize: 'vertical' as const }} placeholder="contato@cliente.com" />
            </div>
          )}

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
  const [ent, setEnt]               = useState<Entitlements>({ whatsapp: false, email: false })

  const fetchAlertas = useCallback(() => {
    setLoading(true)
    api.get<{ data: AlertaConfig[] }>('/alertas')
      .then(r => setAlertas(r.data.data ?? []))
      .catch(() => setAlertas([]))
      .finally(() => setLoading(false))
  }, [])

  useEffect(fetchAlertas, [fetchAlertas])

  useEffect(() => {
    api.get<{ plano: { alerta_whatsapp?: boolean; alerta_email?: boolean } | null }>('/plano/limites')
      .then(r => setEnt({
        whatsapp: !!r.data.plano?.alerta_whatsapp,
        email:    !!r.data.plano?.alerta_email,
      }))
      .catch(() => setEnt({ whatsapp: false, email: false }))
  }, [])

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

  // Detecta sobreposição: um catch-all (sem filtro) ativo + um gatilho filtrado
  // ativo do mesmo tipo dispara mensagens duplicadas para os status filtrados.
  const tiposEmConflito = Object.entries(
    alertas
      .filter(a => a.ativo && suportaStatusAlvo(a.tipo))
      .reduce<Record<string, AlertaConfig[]>>((acc, a) => {
        (acc[a.tipo] ??= []).push(a); return acc
      }, {}),
  )
    .filter(([, list]) =>
      list.some(a => !a.condicoes?.status_alvo?.length) &&
      list.some(a => a.condicoes?.status_alvo?.length),
    )
    .map(([tipo]) => TIPO_LABELS[tipo] ?? tipo)

  return (
    <div>
      {editando && <EditModal alerta={editando} ent={ent} onClose={() => setEditando(null)} onSaved={fetchAlertas} />}
      {criando  && <CreateModal ent={ent} onClose={() => setCriando(false)} onSaved={fetchAlertas} />}

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 24 }}>
        <div>
          <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
            Alertas
          </h1>
          <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
            Configure os alertas e os canais de envio (WhatsApp / e-mail) conforme seu plano
          </p>
        </div>
        <button onClick={() => setCriando(true)}
          className="font-display"
          style={{ padding: '9px 20px', borderRadius: 8, background: 'var(--accent)', color: '#000', border: 'none', fontSize: 14, fontWeight: 800, cursor: 'pointer' }}>
          + Novo Alerta
        </button>
      </div>

      {tiposEmConflito.length > 0 && (
        <div style={{
          display: 'flex', gap: 10, alignItems: 'flex-start', marginBottom: 20, padding: '12px 16px',
          borderRadius: 9, border: '1px solid var(--accent)', background: 'rgba(245,166,35,.08)',
        }}>
          <span style={{ fontSize: 18, lineHeight: 1.2 }}>⚠️</span>
          <div style={{ fontSize: 13, color: 'var(--text)' }}>
            <b>Possível envio duplicado</b> em: {tiposEmConflito.join(', ')}.{' '}
            Há um alerta sem filtro (dispara em qualquer status) ativo junto com gatilhos por status específico.
            Edite o filtro do alerta geral ou desative-o para evitar duas mensagens ao cliente.
          </div>
        </div>
      )}

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
                      {suportaStatusAlvo(alerta.tipo) && alerta.condicoes?.status_alvo?.length ? (
                        <div style={{ fontSize: 11, color: 'var(--accent)', marginTop: 2 }}>
                          Só quando vira: {statusAlvoLabel(alerta.condicoes.status_alvo)}
                        </div>
                      ) : null}
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
                          {suportaStatusAlvo(alerta.tipo) && alerta.condicoes?.status_alvo?.length
                            ? ` · quando vira ${statusAlvoLabel(alerta.condicoes.status_alvo)}`
                            : ''}
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
