'use client'
import { useState, useEffect, useCallback } from 'react'
import api from '@/lib/api'

const TIPO_LABELS: Record<string, string> = {
  ESTOQUE_BAIXO:          '📦 Estoque Baixo',
  ESTOQUE_CRITICO:        '🚨 Estoque Crítico',
  CLIENTE_DEVEDOR:        '💸 Cliente Devedor',
  DIVIDA_VENCIDA:         '🔴 Dívida Vencida',
  OS_NOVA:                '🔧 OS Nova',
  OS_STATUS_MUDOU:        '📋 OS Status Mudou',
  OS_VENCIDA:             '⏰ OS Vencida',
  AGENDAMENTO_CONFIRMADO: '✅ Agend. Confirmado',
  AGENDAMENTO_LEMBRETE:   '📅 Lembrete Agend.',
  PAGAMENTO_RECEBIDO:     '✅ Pagamento',
  PAGAMENTO_PARCIAL:      '⚡ Pagamento Parcial',
  NF_AUTORIZADA:          '🧾 NF Autorizada',
  ALERTA:                 '🔔 Alerta',
  MANUAL:                 '👤 Manual',
}

interface LogEntry {
  id: string
  tipo: string
  canal?: string | null
  destinatario: string
  mensagem: string
  sucesso: boolean
  erro: string | null
  enviado_em: string
}

const CANAL_LABEL: Record<string, string> = {
  WHATSAPP: '💬 WhatsApp',
  EMAIL: '✉️ E-mail',
}

interface PaginatedLogs {
  data: LogEntry[]
  current_page: number
  last_page: number
  total: number
  per_page: number
}

function formatDate(iso: string) {
  const d = new Date(iso)
  return d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

export default function AlertaLogsPage() {
  const [logs, setLogs]         = useState<PaginatedLogs | null>(null)
  const [loading, setLoading]   = useState(true)
  const [page, setPage]         = useState(1)
  const [filtroTipo, setFiltroTipo]     = useState('')
  const [filtroSucesso, setFiltroSucesso] = useState('')
  const [filtroDe, setFiltroDe]         = useState('')
  const [filtroAte, setFiltroAte]       = useState('')
  const [selecionado, setSelecionado]   = useState<LogEntry | null>(null)

  const fetchLogs = useCallback(() => {
    setLoading(true)
    const params = new URLSearchParams({ page: String(page) })
    if (filtroTipo)    params.set('tipo', filtroTipo)
    if (filtroSucesso) params.set('sucesso', filtroSucesso)
    if (filtroDe)      params.set('de', filtroDe)
    if (filtroAte)     params.set('ate', filtroAte)

    api.get<PaginatedLogs>(`/alertas/logs?${params}`)
      .then(r => setLogs(r.data))
      .catch(() => setLogs(null))
      .finally(() => setLoading(false))
  }, [page, filtroTipo, filtroSucesso, filtroDe, filtroAte])

  useEffect(fetchLogs, [fetchLogs])

  const iStyle: React.CSSProperties = {
    padding: '7px 10px', borderRadius: 6,
    background: 'var(--bg)', border: '1px solid var(--border)',
    color: 'var(--text)', fontSize: 13, outline: 'none',
  }

  return (
    <div>
      {selecionado && (
        <div onClick={() => setSelecionado(null)}
          style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.65)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
          <div onClick={e => e.stopPropagation()}
            style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 14, padding: 28, width: 520, maxWidth: '100%', maxHeight: '90vh', overflowY: 'auto' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18 }}>
              <h3 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
                {TIPO_LABELS[selecionado.tipo] ?? selecionado.tipo}
              </h3>
              <button onClick={() => setSelecionado(null)} style={{ background: 'none', border: 'none', color: 'var(--muted)', fontSize: 24, cursor: 'pointer', lineHeight: 1 }}>×</button>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '10px 16px', fontSize: 14 }}>
              <span style={{ color: 'var(--muted)' }}>Status</span>
              <span style={{ fontWeight: 700, color: selecionado.sucesso ? 'var(--success)' : 'var(--danger)' }}>{selecionado.sucesso ? '✅ Enviado' : '❌ Falhou'}</span>
              <span style={{ color: 'var(--muted)' }}>Canal</span>
              <span>{CANAL_LABEL[selecionado.canal ?? ''] ?? (selecionado.canal ?? '—')}</span>
              <span style={{ color: 'var(--muted)' }}>Destinatário</span>
              <span style={{ fontFamily: 'monospace' }}>{selecionado.destinatario}</span>
              <span style={{ color: 'var(--muted)' }}>Data/Hora</span>
              <span style={{ fontFamily: 'monospace' }}>{formatDate(selecionado.enviado_em)}</span>
            </div>
            <div style={{ marginTop: 18 }}>
              <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>Mensagem</div>
              <div style={{ background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, padding: '12px 14px', fontSize: 14, color: 'var(--text)', whiteSpace: 'pre-wrap', lineHeight: 1.5 }}>
                {selecionado.mensagem}
              </div>
            </div>
            {selecionado.erro && (
              <div style={{ marginTop: 14 }}>
                <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--danger)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>Erro</div>
                <div style={{ background: 'rgba(229,57,53,.08)', border: '1px solid rgba(229,57,53,.3)', borderRadius: 8, padding: '12px 14px', fontSize: 13, color: 'var(--danger)', whiteSpace: 'pre-wrap' }}>
                  {selecionado.erro}
                </div>
              </div>
            )}
          </div>
        </div>
      )}
      <div style={{ marginBottom: 24 }}>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
          Histórico de Alertas Enviados
        </h1>
        <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
          Registro de todas as mensagens WhatsApp disparadas pelo sistema
        </p>
      </div>

      {/* Filtros */}
      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 20 }}>
        <select value={filtroTipo} onChange={e => { setFiltroTipo(e.target.value); setPage(1) }} style={iStyle}>
          <option value="">Todos os tipos</option>
          {Object.entries(TIPO_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>

        <select value={filtroSucesso} onChange={e => { setFiltroSucesso(e.target.value); setPage(1) }} style={iStyle}>
          <option value="">Todos os status</option>
          <option value="true">✅ Enviado</option>
          <option value="false">❌ Falhou</option>
        </select>

        <input type="date" value={filtroDe} onChange={e => { setFiltroDe(e.target.value); setPage(1) }} style={iStyle} title="De" />
        <input type="date" value={filtroAte} onChange={e => { setFiltroAte(e.target.value); setPage(1) }} style={iStyle} title="Até" />

        {(filtroTipo || filtroSucesso || filtroDe || filtroAte) && (
          <button onClick={() => { setFiltroTipo(''); setFiltroSucesso(''); setFiltroDe(''); setFiltroAte(''); setPage(1) }}
            style={{ ...iStyle, color: 'var(--muted)', cursor: 'pointer' }}>
            ✕ Limpar
          </button>
        )}
      </div>

      {/* Tabela */}
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
        {loading ? (
          <div style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Carregando...</div>
        ) : !logs || logs.data.length === 0 ? (
          <div style={{ padding: 40, textAlign: 'center', color: 'var(--muted)', fontSize: 14 }}>
            Nenhum alerta encontrado com os filtros selecionados.
          </div>
        ) : (
          <>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead>
                <tr style={{ borderBottom: '1px solid var(--border)' }}>
                  {['Data/Hora', 'Tipo', 'Destinatário', 'Mensagem', 'Status'].map(h => (
                    <th key={h} style={{ padding: '10px 16px', fontSize: 11, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', textAlign: 'left', whiteSpace: 'nowrap' }}>
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {logs.data.map((log, i) => (
                  <tr key={log.id} onClick={() => setSelecionado(log)}
                    title="Ver detalhes"
                    style={{ borderBottom: i < logs.data.length - 1 ? '1px solid var(--border)' : undefined, background: log.sucesso ? undefined : 'rgba(229,57,53,.04)', cursor: 'pointer' }}
                    onMouseEnter={e => { (e.currentTarget as HTMLTableRowElement).style.background = 'rgba(255,255,255,.03)' }}
                    onMouseLeave={e => { (e.currentTarget as HTMLTableRowElement).style.background = log.sucesso ? '' : 'rgba(229,57,53,.04)' }}>

                    <td style={{ padding: '10px 16px', fontSize: 12, color: 'var(--muted)', whiteSpace: 'nowrap', fontFamily: 'monospace' }}>
                      {formatDate(log.enviado_em)}
                    </td>
                    <td style={{ padding: '10px 16px', fontSize: 12, whiteSpace: 'nowrap' }}>
                      {TIPO_LABELS[log.tipo] ?? log.tipo}
                    </td>
                    <td style={{ padding: '10px 16px', fontSize: 13, fontFamily: 'monospace', color: 'var(--text)' }}>
                      {log.destinatario}
                    </td>
                    <td style={{ padding: '10px 16px', fontSize: 12, color: 'var(--muted)', maxWidth: 340 }}>
                      <div style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={log.mensagem}>
                        {log.mensagem}
                      </div>
                      {log.erro && (
                        <div style={{ color: 'var(--danger)', fontSize: 11, marginTop: 2 }} title={log.erro ?? ''}>
                          ⚠ {log.erro?.substring(0, 80)}...
                        </div>
                      )}
                    </td>
                    <td style={{ padding: '10px 16px' }}>
                      <span style={{
                        display: 'inline-block', padding: '2px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700,
                        background: log.sucesso ? 'rgba(67,160,71,.15)' : 'rgba(229,57,53,.15)',
                        color: log.sucesso ? 'var(--success)' : 'var(--danger)',
                      }}>
                        {log.sucesso ? '✅ Enviado' : '❌ Falhou'}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            {/* Paginação */}
            {logs.last_page > 1 && (
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '12px 16px', borderTop: '1px solid var(--border)' }}>
                <span style={{ fontSize: 12, color: 'var(--muted)' }}>
                  {logs.total} registros · Página {logs.current_page} de {logs.last_page}
                </span>
                <div style={{ display: 'flex', gap: 8 }}>
                  <button disabled={page <= 1} onClick={() => setPage(p => p - 1)}
                    style={{ padding: '5px 14px', borderRadius: 6, background: 'transparent', border: '1px solid var(--border)', color: page <= 1 ? 'var(--border)' : 'var(--muted)', cursor: page <= 1 ? 'not-allowed' : 'pointer', fontSize: 13 }}>
                    ← Anterior
                  </button>
                  <button disabled={page >= logs.last_page} onClick={() => setPage(p => p + 1)}
                    style={{ padding: '5px 14px', borderRadius: 6, background: 'transparent', border: '1px solid var(--border)', color: page >= logs.last_page ? 'var(--border)' : 'var(--muted)', cursor: page >= logs.last_page ? 'not-allowed' : 'pointer', fontSize: 13 }}>
                    Próxima →
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
