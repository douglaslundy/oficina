'use client'
import { useState, useEffect, useCallback } from 'react'
import { useSearchParams, useRouter } from 'next/navigation'
import { Suspense } from 'react'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

// ─── Types ───────────────────────────────────────────────────────────────────

interface Cliente { id: string; nome: string; veiculo_modelo?: string; veiculo_placa?: string }
interface Mecanico { id: string; nome: string }

interface Agendamento {
  id: string
  cliente: { id: string; nome: string; veiculo_placa?: string } | null
  mecanico: { id: string; nome: string } | null
  tipo_servico: string
  observacoes?: string
  data_hora_inicio: string
  data_hora_fim: string
  status: 'AGENDADO' | 'CONFIRMADO' | 'CANCELADO' | 'CONCLUIDO'
  os_id: string | null
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function startOfWeek(date: Date): Date {
  const d = new Date(date)
  const day = d.getDay()
  d.setDate(d.getDate() - day)
  d.setHours(0, 0, 0, 0)
  return d
}

function addDays(date: Date, n: number): Date {
  const d = new Date(date)
  d.setDate(d.getDate() + n)
  return d
}

function fmtHour(iso: string): string {
  return new Date(iso.replace(' ', 'T')).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}

function fmtDate(d: Date): string {
  return d.toLocaleDateString('pt-BR', { weekday: 'short', day: 'numeric', month: 'short' })
}

function isoDate(d: Date): string {
  return d.toISOString().split('T')[0]
}

const STATUS_COLOR: Record<string, string> = {
  AGENDADO:   'var(--info)',
  CONFIRMADO: 'var(--success)',
  CANCELADO:  'var(--danger)',
  CONCLUIDO:  'var(--muted)',
}

const STATUS_BG: Record<string, string> = {
  AGENDADO:   'rgba(30,136,229,0.12)',
  CONFIRMADO: 'rgba(67,160,71,0.12)',
  CANCELADO:  'rgba(229,57,53,0.10)',
  CONCLUIDO:  'rgba(122,128,144,0.10)',
}

const STATUS_LABEL: Record<string, string> = {
  AGENDADO:   'Agendado',
  CONFIRMADO: 'Confirmado',
  CANCELADO:  'Cancelado',
  CONCLUIDO:  'Concluído',
}

const TIPOS_SERVICO = [
  'Troca de óleo', 'Revisão geral', 'Alinhamento e balanceamento',
  'Troca de freios', 'Elétrica', 'Suspensão', 'Motor',
  'Ar condicionado', 'Funilaria', 'Outros',
]

// ─── Modal de criação ─────────────────────────────────────────────────────────

interface NovoAgendamentoModalProps {
  onClose: () => void
  onSaved: () => void
  defaultDate?: string // YYYY-MM-DD
}

function NovoAgendamentoModal({ onClose, onSaved, defaultDate }: NovoAgendamentoModalProps) {
  const [clientes, setClientes]   = useState<Cliente[]>([])
  const [mecanicos, setMecanicos] = useState<Mecanico[]>([])
  const [form, setForm] = useState({
    cliente_id:   '',
    mecanico_id:  '',
    tipo_servico: TIPOS_SERVICO[0],
    observacoes:  '',
    data:         defaultDate ?? isoDate(new Date()),
    hora_inicio:  '08:00',
    hora_fim:     '09:00',
  })
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    Promise.all([
      api.get('/clientes?per_page=200'),
      api.get('/usuarios?role=MECANICO'),
    ]).then(([c, m]) => {
      setClientes(c.data.data ?? [])
      setMecanicos(m.data.data ?? [])
    }).catch(() => {})
  }, [])

  const set = (k: string) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
      setForm(f => ({ ...f, [k]: e.target.value }))

  async function handleSave() {
    if (!form.cliente_id) { toast('Selecione um cliente.', 'danger'); return }
    if (!form.tipo_servico) { toast('Informe o tipo de serviço.', 'danger'); return }
    setSaving(true)
    try {
      await api.post('/agendamentos', {
        cliente_id:       form.cliente_id,
        mecanico_id:      form.mecanico_id || undefined,
        tipo_servico:     form.tipo_servico,
        observacoes:      form.observacoes || undefined,
        data_hora_inicio: `${form.data}T${form.hora_inicio}:00`,
        data_hora_fim:    `${form.data}T${form.hora_fim}:00`,
      })
      toast('Agendamento criado!', 'success')
      onSaved()
      onClose()
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao agendar.', 'danger')
    } finally {
      setSaving(false)
    }
  }

  const iStyle: React.CSSProperties = {
    width: '100%', padding: '9px 12px', borderRadius: 8,
    background: 'var(--bg)', border: '1px solid var(--border)',
    color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box',
  }
  const lStyle: React.CSSProperties = {
    color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4,
  }

  return (
    <div style={{
      position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.65)',
      display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 999,
    }}>
      <div style={{
        background: 'var(--card)', borderRadius: 14, border: '1px solid var(--border)',
        padding: 28, width: 480, maxHeight: '90vh', overflowY: 'auto',
      }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
          <h3 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
            Novo Agendamento
          </h3>
          <button onClick={onClose}
            style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 22, lineHeight: 1 }}>
            ×
          </button>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <div>
            <label style={lStyle}>Cliente *</label>
            <select value={form.cliente_id} onChange={set('cliente_id')} style={iStyle}>
              <option value="">Selecionar cliente...</option>
              {clientes.map(c => (
                <option key={c.id} value={c.id}>
                  {c.nome}{c.veiculo_placa ? ` — ${c.veiculo_placa}` : ''}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label style={lStyle}>Tipo de serviço *</label>
            <select value={form.tipo_servico} onChange={set('tipo_servico')} style={iStyle}>
              {TIPOS_SERVICO.map(t => <option key={t} value={t}>{t}</option>)}
            </select>
          </div>

          <div>
            <label style={lStyle}>Mecânico responsável</label>
            <select value={form.mecanico_id} onChange={set('mecanico_id')} style={iStyle}>
              <option value="">Qualquer mecânico</option>
              {mecanicos.map(m => <option key={m.id} value={m.id}>{m.nome}</option>)}
            </select>
          </div>

          <div>
            <label style={lStyle}>Data</label>
            <input type="date" value={form.data} onChange={set('data')} style={iStyle} />
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div>
              <label style={lStyle}>Hora início</label>
              <input type="time" value={form.hora_inicio} onChange={set('hora_inicio')} style={iStyle} />
            </div>
            <div>
              <label style={lStyle}>Hora fim</label>
              <input type="time" value={form.hora_fim} onChange={set('hora_fim')} style={iStyle} />
            </div>
          </div>

          <div>
            <label style={lStyle}>Observações</label>
            <textarea
              value={form.observacoes}
              onChange={set('observacoes')}
              rows={2}
              style={{ ...iStyle, resize: 'vertical' as const }}
              placeholder="Detalhes adicionais sobre o serviço..."
            />
          </div>
        </div>

        <div style={{ display: 'flex', gap: 12, marginTop: 20 }}>
          <button onClick={onClose}
            style={{
              flex: 1, padding: 10, borderRadius: 8,
              background: 'transparent', border: '1px solid var(--border)',
              color: 'var(--muted)', cursor: 'pointer',
            }}>
            Cancelar
          </button>
          <button onClick={handleSave} disabled={saving} className="font-display"
            style={{
              flex: 2, padding: 10, borderRadius: 8,
              background: saving ? 'var(--muted)' : 'var(--accent)',
              color: '#000', border: 'none', fontWeight: 800, fontSize: 15,
              cursor: saving ? 'not-allowed' : 'pointer',
            }}>
            {saving ? 'Salvando...' : 'Agendar'}
          </button>
        </div>
      </div>
    </div>
  )
}

// ─── Card de agendamento ──────────────────────────────────────────────────────

interface AgendamentoCardProps {
  ag: Agendamento
  onRefresh: () => void
}

function AgendamentoCard({ ag, onRefresh }: AgendamentoCardProps) {
  const router = useRouter()
  const [loading, setLoading] = useState(false)
  const [confirmCancel, setConfirmCancel] = useState(false)

  async function confirmar() {
    setLoading(true)
    try {
      const res = await api.post(`/agendamentos/${ag.id}/confirmar`)
      toast(res.data.message, 'success')
      onRefresh()
    } catch {
      toast('Erro ao confirmar.', 'danger')
    } finally {
      setLoading(false)
    }
  }

  async function cancelar() {
    setLoading(true)
    try {
      await api.post(`/agendamentos/${ag.id}/cancelar`)
      toast('Agendamento cancelado.', 'success')
      setConfirmCancel(false)
      onRefresh()
    } catch {
      toast('Erro ao cancelar.', 'danger')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div style={{
      background: STATUS_BG[ag.status] ?? 'var(--card)',
      border: `1px solid ${STATUS_COLOR[ag.status] ?? 'var(--border)'}`,
      borderLeft: `3px solid ${STATUS_COLOR[ag.status] ?? 'var(--border)'}`,
      borderRadius: 8, padding: '8px 10px', marginBottom: 6, fontSize: 13,
    }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <p style={{
            fontWeight: 600, color: 'var(--text)', margin: 0,
            overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap',
          }}>
            {ag.cliente?.nome ?? 'Cliente'}
          </p>
          <p style={{ color: 'var(--muted)', fontSize: 12, margin: '2px 0 0' }}>
            {fmtHour(ag.data_hora_inicio)}–{fmtHour(ag.data_hora_fim)} · {ag.tipo_servico}
          </p>
          {ag.mecanico && (
            <p style={{ color: 'var(--muted)', fontSize: 11, margin: '2px 0 0' }}>
              👤 {ag.mecanico.nome}
            </p>
          )}
        </div>
        <span style={{
          fontSize: 10, fontWeight: 700, color: STATUS_COLOR[ag.status],
          whiteSpace: 'nowrap', padding: '2px 6px', borderRadius: 4,
          background: STATUS_BG[ag.status],
        }}>
          {STATUS_LABEL[ag.status]}
        </span>
      </div>

      {/* Actions */}
      <div style={{ display: 'flex', gap: 6, marginTop: 8, flexWrap: 'wrap' as const }}>
        {ag.status === 'AGENDADO' && (
          <>
            <button onClick={confirmar} disabled={loading}
              style={{
                padding: '3px 10px',
                background: 'rgba(67,160,71,0.15)', border: '1px solid var(--success)',
                color: 'var(--success)', borderRadius: 6,
                cursor: loading ? 'not-allowed' : 'pointer', fontSize: 11, fontWeight: 600,
              }}>
              ✓ Confirmar
            </button>
            <button onClick={() => setConfirmCancel(true)} disabled={loading}
              style={{
                padding: '3px 10px',
                background: 'transparent', border: '1px solid var(--border)',
                color: 'var(--muted)', borderRadius: 6,
                cursor: loading ? 'not-allowed' : 'pointer', fontSize: 11,
              }}>
              Cancelar
            </button>
          </>
        )}
        {ag.os_id && (
          <button onClick={() => router.push(`/os/${ag.os_id}`)}
            style={{
              padding: '3px 10px',
              background: 'rgba(245,166,35,0.15)', border: '1px solid var(--accent)',
              color: 'var(--accent)', borderRadius: 6, cursor: 'pointer', fontSize: 11, fontWeight: 600,
            }}>
            Ver OS →
          </button>
        )}
      </div>

      {confirmCancel && (
        <div style={{
          position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.65)', zIndex: 1000,
          display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24,
        }}>
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 14, padding: 28, width: 420, maxWidth: '100%' }}>
            <div style={{ fontSize: 38, textAlign: 'center', marginBottom: 8 }}>⚠️</div>
            <h3 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: 0, textAlign: 'center' }}>
              Cancelar este agendamento?
            </h3>
            <p style={{ color: 'var(--muted)', fontSize: 14, textAlign: 'center', margin: '10px 0 0', lineHeight: 1.5 }}>
              {ag.cliente?.nome ?? 'Cliente'} · {fmtHour(ag.data_hora_inicio)} · {ag.tipo_servico}.
              {' '}O agendamento será marcado como <b style={{ color: 'var(--danger)' }}>CANCELADO</b>.
            </p>
            <div style={{ display: 'flex', gap: 12, marginTop: 24, justifyContent: 'center' }}>
              <button onClick={() => setConfirmCancel(false)} disabled={loading}
                style={{ padding: '9px 22px', borderRadius: 8, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>
                Voltar
              </button>
              <button onClick={cancelar} disabled={loading}
                style={{ padding: '9px 24px', borderRadius: 8, background: 'var(--danger)', border: 'none', color: '#fff', fontSize: 14, fontWeight: 700, cursor: loading ? 'not-allowed' : 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
                {loading ? '⟳ Cancelando...' : 'Confirmar cancelamento'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

// ─── Calendar view ────────────────────────────────────────────────────────────

function MonthView({ agendamentos, currentDate, onDayClick }: {
  agendamentos: Agendamento[], currentDate: Date, onDayClick: (date: string) => void
}) {
  const year = currentDate.getFullYear()
  const month = currentDate.getMonth()
  const firstDay = new Date(year, month, 1)
  const lastDay = new Date(year, month + 1, 0)
  const startPad = firstDay.getDay()
  const days: (Date | null)[] = []
  for (let i = 0; i < startPad; i++) days.push(null)
  for (let d = 1; d <= lastDay.getDate(); d++) days.push(new Date(year, month, d))

  const agByDay: Record<string, Agendamento[]> = {}
  agendamentos.forEach(ag => {
    const key = ag.data_hora_inicio.split('T')[0]
    if (!agByDay[key]) agByDay[key] = []
    agByDay[key].push(ag)
  })

  const WEEKDAYS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb']

  return (
    <div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 1, marginBottom: 1 }}>
        {WEEKDAYS.map(d => (
          <div key={d} style={{ textAlign: 'center', color: 'var(--muted)', fontSize: 12, fontWeight: 600, padding: '8px 4px' }}>{d}</div>
        ))}
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 1 }}>
        {days.map((day, i) => {
          if (!day) return <div key={`pad-${i}`} style={{ background: 'var(--surface)', minHeight: 80, borderRadius: 4 }} />
          const key = `${year}-${String(month + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`
          const dayAgs = agByDay[key] ?? []
          const isToday = key === new Date().toISOString().split('T')[0]
          return (
            <div key={key} onClick={() => onDayClick(key)} style={{
              background: 'var(--card)', border: `1px solid ${isToday ? 'var(--accent)' : 'var(--border)'}`,
              borderRadius: 4, minHeight: 80, padding: 6, cursor: 'pointer', transition: 'border-color 0.2s'
            }}>
              <div style={{ fontSize: 13, fontWeight: 600, color: isToday ? 'var(--accent)' : 'var(--muted)', marginBottom: 4 }}>
                {day.getDate()}
              </div>
              {dayAgs.slice(0, 3).map(ag => (
                <div key={ag.id} style={{
                  background: STATUS_BG[ag.status], color: STATUS_COLOR[ag.status],
                  fontSize: 10, borderRadius: 3, padding: '1px 4px', marginBottom: 2,
                  overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'
                }}>
                  {fmtHour(ag.data_hora_inicio)} {ag.cliente?.nome ?? 'Cliente'}
                </div>
              ))}
              {dayAgs.length > 3 && (
                <div style={{ color: 'var(--muted)', fontSize: 10 }}>+{dayAgs.length - 3}</div>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}

function CalendarioAgendamentos() {
  const searchParams = useSearchParams()
  const [semanaBase, setSemanaBase] = useState(() => startOfWeek(new Date()))
  const [agendamentos, setAgendamentos] = useState<Agendamento[]>([])
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [modalDate, setModalDate] = useState<string | undefined>()
  const [viewMode, setViewMode] = useState<'semana' | 'mes'>('semana')

  const dias = Array.from({ length: 7 }, (_, i) => addDays(semanaBase, i))

  const fetchAgendamentos = useCallback(() => {
    setLoading(true)
    let inicio: string
    let fim: string
    if (viewMode === 'mes') {
      const year = semanaBase.getFullYear()
      const month = semanaBase.getMonth()
      inicio = new Date(year, month, 1).toISOString()
      fim = new Date(year, month + 1, 0, 23, 59, 59).toISOString()
    } else {
      inicio = semanaBase.toISOString()
      fim = addDays(semanaBase, 7).toISOString()
    }
    api.get('/agendamentos', { params: { inicio, fim } })
      .then(r => setAgendamentos(r.data.data ?? []))
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [semanaBase, viewMode])

  useEffect(() => { fetchAgendamentos() }, [fetchAgendamentos])

  // Auto-open modal if ?novo=1
  useEffect(() => {
    if (searchParams.get('novo') === '1') setShowModal(true)
  }, [searchParams])

  function agsDoDia(dia: Date): Agendamento[] {
    const dateStr = isoDate(dia)
    return agendamentos.filter(a => a.data_hora_inicio.startsWith(dateStr))
  }

  const hoje = isoDate(new Date())
  const semanaLabel = viewMode === 'mes'
    ? semanaBase.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' }).replace(/^\w/, c => c.toUpperCase())
    : `${dias[0].toLocaleDateString('pt-BR', { day: 'numeric', month: 'short' })} – ${dias[6].toLocaleDateString('pt-BR', { day: 'numeric', month: 'short', year: 'numeric' })}`

  return (
    <div>
      {/* Header */}
      <div style={{
        display: 'flex', justifyContent: 'space-between', alignItems: 'center',
        marginBottom: 20, flexWrap: 'wrap', gap: 12,
      }}>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
          Agendamento
        </h1>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
          {/* Toggle semana/mês */}
          <div style={{ display: 'flex', gap: 4, background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 8, padding: 4 }}>
            {(['semana', 'mes'] as const).map(m => (
              <button key={m} onClick={() => setViewMode(m)} style={{
                background: viewMode === m ? 'var(--accent)' : 'none',
                color: viewMode === m ? '#000' : 'var(--muted)',
                border: 'none', borderRadius: 6, padding: '6px 16px',
                cursor: 'pointer', fontSize: 13, fontWeight: 600, textTransform: 'capitalize'
              }}>{m === 'semana' ? 'Semana' : 'Mês'}</button>
            ))}
          </div>
          {/* Navegação */}
          <div style={{
            display: 'flex', alignItems: 'center', gap: 8,
            background: 'var(--card)', border: '1px solid var(--border)',
            borderRadius: 8, padding: '4px 8px',
          }}>
            <button
              onClick={() => {
                if (viewMode === 'mes') {
                  setSemanaBase(s => new Date(s.getFullYear(), s.getMonth() - 1, 1))
                } else {
                  setSemanaBase(s => addDays(s, -7))
                }
              }}
              style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 16, padding: '2px 4px' }}>
              ‹
            </button>
            <span style={{ color: 'var(--text)', fontSize: 14, minWidth: 180, textAlign: 'center' }}>
              {semanaLabel}
            </span>
            <button
              onClick={() => {
                if (viewMode === 'mes') {
                  setSemanaBase(s => new Date(s.getFullYear(), s.getMonth() + 1, 1))
                } else {
                  setSemanaBase(s => addDays(s, 7))
                }
              }}
              style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 16, padding: '2px 4px' }}>
              ›
            </button>
          </div>
          <button onClick={() => setSemanaBase(viewMode === 'mes' ? new Date(new Date().getFullYear(), new Date().getMonth(), 1) : startOfWeek(new Date()))}
            style={{
              padding: '6px 14px', background: 'transparent',
              border: '1px solid var(--border)', color: 'var(--muted)',
              borderRadius: 8, cursor: 'pointer', fontSize: 13,
            }}>
            Hoje
          </button>
          <button
            onClick={() => { setModalDate(undefined); setShowModal(true) }}
            className="font-display"
            style={{
              padding: '8px 18px', background: 'var(--accent)', color: '#000',
              borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 14, cursor: 'pointer',
            }}>
            + Agendar
          </button>
        </div>
      </div>

      {/* Grid semanal / mensal */}
      {viewMode === 'mes' ? (
        <MonthView
          agendamentos={agendamentos}
          currentDate={semanaBase}
          onDayClick={date => { setModalDate(date); setShowModal(true) }}
        />
      ) : (
        <div style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(7, 1fr)',
          gap: 8,
        }}>
          {dias.map(dia => {
            const dateStr = isoDate(dia)
            const isHoje = dateStr === hoje
            const ags = agsDoDia(dia)
            const isWeekend = dia.getDay() === 0 || dia.getDay() === 6

            return (
              <div key={dateStr} style={{
                background: isWeekend ? 'rgba(122,128,144,0.05)' : 'var(--card)',
                border: `1px solid ${isHoje ? 'var(--accent)' : 'var(--border)'}`,
                borderRadius: 10,
                minHeight: 280,
                overflow: 'hidden',
              }}>
                {/* Cabeçalho do dia */}
                <div style={{
                  padding: '8px 10px',
                  borderBottom: `1px solid ${isHoje ? 'var(--accent)' : 'var(--border)'}`,
                  background: isHoje ? 'rgba(245,166,35,0.08)' : 'transparent',
                  display: 'flex', justifyContent: 'space-between', alignItems: 'center',
                }}>
                  <span style={{
                    fontSize: 12,
                    color: isHoje ? 'var(--accent)' : 'var(--muted)',
                    fontWeight: isHoje ? 700 : 400,
                    textTransform: 'capitalize',
                  }}>
                    {fmtDate(dia)}
                  </span>
                  {ags.length > 0 && (
                    <span style={{
                      background: 'var(--accent)', color: '#000',
                      borderRadius: 999, fontSize: 10, fontWeight: 700, padding: '1px 6px',
                    }}>
                      {ags.length}
                    </span>
                  )}
                </div>

                {/* Agendamentos do dia */}
                <div style={{ padding: '8px 8px', overflowY: 'auto', maxHeight: 340 }}>
                  {loading ? (
                    <div style={{
                      height: 40, background: 'var(--border)', borderRadius: 6,
                      animation: 'pulse 1.5s ease-in-out infinite',
                    }} />
                  ) : ags.length === 0 ? (
                    <button
                      onClick={() => { setModalDate(dateStr); setShowModal(true) }}
                      style={{
                        width: '100%', padding: '12px 0',
                        background: 'transparent', border: '1px dashed var(--border)',
                        borderRadius: 6, color: 'var(--muted)', cursor: 'pointer', fontSize: 12,
                      }}>
                      + Agendar
                    </button>
                  ) : (
                    <>
                      {ags.map(ag => (
                        <AgendamentoCard key={ag.id} ag={ag} onRefresh={fetchAgendamentos} />
                      ))}
                      <button
                        onClick={() => { setModalDate(dateStr); setShowModal(true) }}
                        style={{
                          width: '100%', padding: '6px 0', marginTop: 6,
                          background: 'transparent', border: '1px dashed var(--border)',
                          borderRadius: 6, color: 'var(--muted)', cursor: 'pointer', fontSize: 11,
                        }}>
                        + Agendar
                      </button>
                    </>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      )}

      {/* Legenda */}
      <div style={{ display: 'flex', gap: 16, marginTop: 16, flexWrap: 'wrap' }}>
        {Object.entries(STATUS_LABEL).map(([k, v]) => (
          <div key={k} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, color: 'var(--muted)' }}>
            <div style={{ width: 10, height: 10, borderRadius: 2, background: STATUS_COLOR[k] }} />
            {v}
          </div>
        ))}
      </div>

      {/* Modal */}
      {showModal && (
        <NovoAgendamentoModal
          onClose={() => setShowModal(false)}
          onSaved={fetchAgendamentos}
          defaultDate={modalDate}
        />
      )}
    </div>
  )
}

// ─── Page export ──────────────────────────────────────────────────────────────

export default function AgendamentosPage() {
  return (
    <Suspense>
      <CalendarioAgendamentos />
    </Suspense>
  )
}
