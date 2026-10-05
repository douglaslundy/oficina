'use client'
import { useState, useEffect, useCallback } from 'react'
import api from '@/lib/api'
import { formatarDataHoraVirgula } from '@/lib/formatters'

type Canal = '' | 'WHATSAPP' | 'EMAIL'

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
  ORCAMENTO:              '📝 Orçamento',
  NPS:                    '⭐ Pesquisa de satisfação',
  RECUPERACAO_SENHA:      '🔑 Recuperação de senha',
  TESTE:                  '🧪 Teste',
  ALERTA:                 '🔔 Alerta',
  MANUAL:                 '👤 Manual',
}

const CANAL_LABEL: Record<string, string> = {
  WHATSAPP: '💬 WhatsApp',
  EMAIL: '✉️ E-mail',
}

const ORIGEM_LABEL: Record<string, string> = {
  CLIENTE: '👤 Cliente',
  MECANICO: '🔧 Mecânico',
  CADASTRADO: '📇 Nº cadastrado',
  USUARIO: '👤 Usuário',
}

interface Mensagem {
  id: string
  tipo: string
  canal?: string | null
  assunto?: string | null
  destinatario: string
  destinatario_tipo?: string | null
  mensagem: string
  sucesso: boolean
  erro: string | null
  enviado_em: string
}

interface Paginado {
  data: Mensagem[]
  current_page: number
  last_page: number
  total: number
}

export default function MensagensPage() {
  const [dados, setDados]       = useState<Paginado | null>(null)
  const [loading, setLoading]   = useState(true)
  const [page, setPage]         = useState(1)
  // Padrão: todos os canais.
  const [canal, setCanal]       = useState<Canal>('')
  const [tipo, setTipo]         = useState('')
  const [sucesso, setSucesso]   = useState('')
  const [de, setDe]             = useState('')
  const [ate, setAte]           = useState('')
  const [busca, setBusca]       = useState('')
  const [buscaAplicada, setBuscaAplicada] = useState('')
  const [selecionada, setSelecionada]     = useState<Mensagem | null>(null)

  const carregar = useCallback(() => {
    setLoading(true)
    const params = new URLSearchParams({ page: String(page) })
    if (canal)          params.set('canal', canal)
    if (tipo)           params.set('tipo', tipo)
    if (sucesso)        params.set('sucesso', sucesso)
    if (de)             params.set('de', de)
    if (ate)            params.set('ate', ate)
    if (buscaAplicada)  params.set('busca', buscaAplicada)

    api.get<Paginado>(`/mensagens?${params}`)
      .then(r => setDados(r.data))
      .catch(() => setDados(null))
      .finally(() => setLoading(false))
  }, [page, canal, tipo, sucesso, de, ate, buscaAplicada])

  useEffect(carregar, [carregar])

  const filtrando = canal !== '' || tipo !== '' || sucesso !== '' || de !== '' || ate !== '' || buscaAplicada !== ''

  function limpar() {
    setCanal(''); setTipo(''); setSucesso(''); setDe(''); setAte(''); setBusca(''); setBuscaAplicada(''); setPage(1)
  }

  const iStyle: React.CSSProperties = {
    padding: '7px 10px', borderRadius: 6,
    background: 'var(--bg)', border: '1px solid var(--border)',
    color: 'var(--text)', fontSize: 13, outline: 'none',
  }
  const lStyle: React.CSSProperties = { display: 'block', fontSize: 11, color: 'var(--muted)', marginBottom: 3, textTransform: 'uppercase', letterSpacing: '0.05em' }

  return (
    <div>
      {selecionada && (
        <div onClick={() => setSelecionada(null)}
          style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.65)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
          <div onClick={e => e.stopPropagation()}
            style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 14, padding: 28, width: 560, maxWidth: '100%', maxHeight: '90vh', overflowY: 'auto' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18 }}>
              <h3 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
                {TIPO_LABELS[selecionada.tipo] ?? selecionada.tipo}
              </h3>
              <button onClick={() => setSelecionada(null)} aria-label="Fechar"
                style={{ background: 'none', border: 'none', color: 'var(--muted)', fontSize: 24, cursor: 'pointer', lineHeight: 1 }}>×</button>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '10px 16px', fontSize: 14 }}>
              <span style={{ color: 'var(--muted)' }}>Status</span>
              <span style={{ fontWeight: 700, color: selecionada.sucesso ? 'var(--success)' : 'var(--danger)' }}>{selecionada.sucesso ? '✅ Enviada' : '❌ Falhou'}</span>
              <span style={{ color: 'var(--muted)' }}>Canal</span>
              <span>{CANAL_LABEL[selecionada.canal ?? ''] ?? (selecionada.canal ?? '—')}</span>
              {selecionada.assunto && (<>
                <span style={{ color: 'var(--muted)' }}>Assunto</span>
                <span>{selecionada.assunto}</span>
              </>)}
              <span style={{ color: 'var(--muted)' }}>Destinatário</span>
              <span style={{ fontFamily: 'monospace', wordBreak: 'break-all' }}>{selecionada.destinatario}</span>
              <span style={{ color: 'var(--muted)' }}>Origem</span>
              <span>{selecionada.destinatario_tipo ? (ORIGEM_LABEL[selecionada.destinatario_tipo] ?? selecionada.destinatario_tipo) : '—'}</span>
              <span style={{ color: 'var(--muted)' }}>Data/Hora</span>
              <span style={{ fontFamily: 'monospace' }}>{formatarDataHoraVirgula(selecionada.enviado_em)}</span>
            </div>
            <div style={{ marginTop: 18 }}>
              <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>Mensagem</div>
              <div style={{ background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, padding: '12px 14px', fontSize: 14, color: 'var(--text)', whiteSpace: 'pre-wrap', lineHeight: 1.5 }}>
                {selecionada.mensagem}
              </div>
            </div>
            {selecionada.erro && (
              <div style={{ marginTop: 14 }}>
                <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--danger)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>Erro</div>
                <div style={{ background: 'rgba(229,57,53,.08)', border: '1px solid rgba(229,57,53,.3)', borderRadius: 8, padding: '12px 14px', fontSize: 13, color: 'var(--danger)', whiteSpace: 'pre-wrap' }}>
                  {selecionada.erro}
                </div>
              </div>
            )}
          </div>
        </div>
      )}

      <div style={{ marginBottom: 24 }}>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
          Mensagens
        </h1>
        <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
          Todas as mensagens e alertas disparados pelo sistema, por WhatsApp e por e-mail
        </p>
      </div>

      {/* Filtros */}
      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-end', marginBottom: 20 }}>
        <div>
          <label htmlFor="f-canal" style={lStyle}>Canal</label>
          <select id="f-canal" value={canal} onChange={e => { setCanal(e.target.value as Canal); setPage(1) }} style={iStyle}>
            <option value="">Todos</option>
            <option value="WHATSAPP">💬 WhatsApp</option>
            <option value="EMAIL">✉️ E-mail</option>
          </select>
        </div>
        <div>
          <label htmlFor="f-tipo" style={lStyle}>Tipo</label>
          <select id="f-tipo" value={tipo} onChange={e => { setTipo(e.target.value); setPage(1) }} style={iStyle}>
            <option value="">Todos os tipos</option>
            {Object.entries(TIPO_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </div>
        <div>
          <label htmlFor="f-status" style={lStyle}>Status</label>
          <select id="f-status" value={sucesso} onChange={e => { setSucesso(e.target.value); setPage(1) }} style={iStyle}>
            <option value="">Todos</option>
            <option value="true">✅ Enviada</option>
            <option value="false">❌ Falhou</option>
          </select>
        </div>
        <div>
          <label htmlFor="f-de" style={lStyle}>De</label>
          <input id="f-de" type="date" value={de} onChange={e => { setDe(e.target.value); setPage(1) }} style={iStyle} />
        </div>
        <div>
          <label htmlFor="f-ate" style={lStyle}>Até</label>
          <input id="f-ate" type="date" value={ate} onChange={e => { setAte(e.target.value); setPage(1) }} style={iStyle} />
        </div>
        <form onSubmit={e => { e.preventDefault(); setBuscaAplicada(busca.trim()); setPage(1) }}>
          <label htmlFor="f-busca" style={lStyle}>Buscar</label>
          <input id="f-busca" value={busca} onChange={e => setBusca(e.target.value)} placeholder="Destinatário, assunto ou texto"
            style={{ ...iStyle, width: 220 }} />
        </form>
        {filtrando && (
          <button onClick={limpar} style={{ ...iStyle, color: 'var(--muted)', cursor: 'pointer' }}>✕ Limpar</button>
        )}
      </div>

      {/* Tabela */}
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
        {loading ? (
          <div style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Carregando...</div>
        ) : !dados || dados.data.length === 0 ? (
          <div style={{ padding: 40, textAlign: 'center', color: 'var(--muted)', fontSize: 14 }}>
            {filtrando ? 'Nenhuma mensagem encontrada com os filtros selecionados.' : 'Nenhuma mensagem foi disparada pelo sistema ainda.'}
          </div>
        ) : (
          <>
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 720 }}>
                <thead>
                  <tr style={{ borderBottom: '1px solid var(--border)' }}>
                    {['Data/Hora', 'Canal', 'Tipo', 'Destinatário', 'Mensagem', 'Status'].map(h => (
                      <th key={h} style={{ padding: '10px 16px', fontSize: 11, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', textAlign: 'left', whiteSpace: 'nowrap' }}>
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {dados.data.map((m, i) => (
                    <tr key={m.id} onClick={() => setSelecionada(m)} title="Ver detalhes"
                      style={{ borderBottom: i < dados.data.length - 1 ? '1px solid var(--border)' : undefined, background: m.sucesso ? undefined : 'rgba(229,57,53,.06)', cursor: 'pointer' }}>
                      <td style={{ padding: '10px 16px', fontSize: 12, color: 'var(--muted)', whiteSpace: 'nowrap', fontFamily: 'monospace' }}>
                        {formatarDataHoraVirgula(m.enviado_em)}
                      </td>
                      <td style={{ padding: '10px 16px', fontSize: 12, whiteSpace: 'nowrap' }}>
                        {CANAL_LABEL[m.canal ?? ''] ?? (m.canal ?? '—')}
                      </td>
                      <td style={{ padding: '10px 16px', fontSize: 12, whiteSpace: 'nowrap' }}>
                        {TIPO_LABELS[m.tipo] ?? m.tipo}
                      </td>
                      <td style={{ padding: '10px 16px', fontSize: 13, color: 'var(--text)' }}>
                        <div style={{ fontFamily: 'monospace', wordBreak: 'break-all' }}>{m.destinatario}</div>
                        {m.destinatario_tipo && (
                          <span style={{ fontSize: 11, color: 'var(--muted)' }}>{ORIGEM_LABEL[m.destinatario_tipo] ?? m.destinatario_tipo}</span>
                        )}
                      </td>
                      <td style={{ padding: '10px 16px', fontSize: 12, color: 'var(--muted)', maxWidth: 340 }}>
                        {m.assunto && <div style={{ color: 'var(--text)', fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{m.assunto}</div>}
                        <div style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={m.mensagem}>
                          {m.mensagem.length > 60 ? m.mensagem.slice(0, 60) + '…' : m.mensagem}
                        </div>
                        {m.erro && (
                          <div style={{ color: 'var(--danger)', fontSize: 11, marginTop: 2 }} title={m.erro}>
                            ⚠ {m.erro.length > 80 ? m.erro.slice(0, 80) + '…' : m.erro}
                          </div>
                        )}
                      </td>
                      <td style={{ padding: '10px 16px' }}>
                        <span style={{
                          display: 'inline-block', padding: '2px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700, whiteSpace: 'nowrap',
                          background: m.sucesso ? 'rgba(67,160,71,.15)' : 'rgba(229,57,53,.15)',
                          color: m.sucesso ? 'var(--success)' : 'var(--danger)',
                        }}>
                          {m.sucesso ? '✅ Enviada' : '❌ Falhou'}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '12px 16px', borderTop: '1px solid var(--border)' }}>
              <span style={{ fontSize: 12, color: 'var(--muted)' }}>
                {dados.total} {dados.total === 1 ? 'mensagem' : 'mensagens'}{dados.last_page > 1 ? ` · Página ${dados.current_page} de ${dados.last_page}` : ''}
              </span>
              {dados.last_page > 1 && (
                <div style={{ display: 'flex', gap: 8 }}>
                  <button disabled={page <= 1} onClick={() => setPage(p => p - 1)}
                    style={{ padding: '5px 14px', borderRadius: 6, background: 'transparent', border: '1px solid var(--border)', color: page <= 1 ? 'var(--border)' : 'var(--muted)', cursor: page <= 1 ? 'not-allowed' : 'pointer', fontSize: 13 }}>
                    ← Anterior
                  </button>
                  <button disabled={page >= dados.last_page} onClick={() => setPage(p => p + 1)}
                    style={{ padding: '5px 14px', borderRadius: 6, background: 'transparent', border: '1px solid var(--border)', color: page >= dados.last_page ? 'var(--border)' : 'var(--muted)', cursor: page >= dados.last_page ? 'not-allowed' : 'pointer', fontSize: 13 }}>
                    Próxima →
                  </button>
                </div>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  )
}
