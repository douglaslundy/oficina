'use client'
import { useState, useEffect, useCallback } from 'react'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

const SERVICO_LABEL: Record<string, string> = {
  ALERTA_WHATSAPP: '💬 Alertas WhatsApp',
  ALERTA_EMAIL: '✉️ Alertas E-mail',
  ORCAMENTO: '📝 Orçamentos',
}
const STATUS: Record<string, { label: string; cls: string }> = {
  PENDENTE: { label: 'Pendente', cls: 'pill-accent' },
  APROVADA: { label: 'Aprovada', cls: 'pill-success' },
  RECUSADA: { label: 'Recusada', cls: 'pill-danger' },
}

interface Pacote {
  id: string; nome: string; servico: string; quantidade: number
  valor: string; recorrente: boolean; periodo_dias: number | null; ja_disponivel: boolean
}
interface Solic {
  id: string; status: string; observacao: string | null; criado_em: string
  pacote: { nome: string; servico: string } | null
}

function brl(v: number | string) {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(parseFloat(String(v)) || 0)
}

export default function ContratarPage() {
  const [pacotes, setPacotes] = useState<Pacote[]>([])
  const [solics, setSolics] = useState<Solic[]>([])
  const [loading, setLoading] = useState(true)
  const [enviando, setEnviando] = useState<string | null>(null)
  const [confirmar, setConfirmar] = useState<Pacote | null>(null)

  const carregar = useCallback(() => {
    setLoading(true)
    Promise.all([
      api.get<{ data: Pacote[] }>('/pacotes-disponiveis'),
      api.get<{ data: Solic[] }>('/solicitacoes'),
    ]).then(([p, s]) => {
      setPacotes(p.data.data ?? [])
      setSolics(s.data.data ?? [])
    }).catch(() => {}).finally(() => setLoading(false))
  }, [])
  useEffect(() => { carregar() }, [carregar])

  async function solicitar(pacoteId: string) {
    setEnviando(pacoteId)
    try {
      const r = await api.post<{ message: string }>('/solicitacoes', { pacote_id: pacoteId })
      toast(r.data.message, 'success')
      setConfirmar(null)
      carregar()
    } catch (e: unknown) {
      toast((e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao solicitar.', 'danger')
    } finally { setEnviando(null) }
  }

  return (
    <div style={{ maxWidth: 900, margin: '0 auto' }}>
      {confirmar && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.65)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 14, padding: 28, width: 420, maxWidth: '100%' }}>
            <div style={{ fontSize: 36, textAlign: 'center', marginBottom: 8 }}>🛍️</div>
            <h3 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: 0, textAlign: 'center' }}>Confirmar solicitação?</h3>
            <p style={{ color: 'var(--muted)', fontSize: 14, textAlign: 'center', margin: '10px 0 0', lineHeight: 1.5 }}>
              Solicitar <b style={{ color: 'var(--text)' }}>{confirmar.nome}</b> ({SERVICO_LABEL[confirmar.servico] ?? confirmar.servico}) por <b style={{ color: 'var(--accent)' }}>{brl(confirmar.valor)}/mês</b>.
              <br />O administrador precisará aprovar e o valor entrará na sua mensalidade.
            </p>
            <div style={{ display: 'flex', gap: 12, marginTop: 24, justifyContent: 'center' }}>
              <button onClick={() => setConfirmar(null)} disabled={enviando === confirmar.id}
                style={{ padding: '9px 22px', borderRadius: 8, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>Cancelar</button>
              <button onClick={() => solicitar(confirmar.id)} disabled={enviando === confirmar.id}
                style={{ padding: '9px 24px', borderRadius: 8, background: 'var(--accent)', border: 'none', color: '#000', fontSize: 14, fontWeight: 700, cursor: enviando === confirmar.id ? 'not-allowed' : 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
                {enviando === confirmar.id ? '⟳ Solicitando...' : 'Confirmar'}
              </button>
            </div>
          </div>
        </div>
      )}
      <div style={{ marginBottom: 24 }}>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>Contratar Serviços</h1>
        <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>Solicite serviços avulsos além do seu plano. O administrador aprovará e o valor entra na sua mensalidade.</p>
      </div>

      {loading ? <p style={{ color: 'var(--muted)' }}>Carregando...</p> : (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))', gap: 14, marginBottom: 32 }}>
            {pacotes.length === 0 && <p style={{ color: 'var(--muted)', fontSize: 14 }}>Nenhum pacote disponível no momento.</p>}
            {pacotes.map(p => (
              <div key={p.id} style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, padding: 20 }}>
                <div style={{ fontSize: 13, color: 'var(--muted)' }}>{SERVICO_LABEL[p.servico] ?? p.servico}</div>
                <div style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: '4px 0 8px' }}>{p.nome}</div>
                <div style={{ fontSize: 13, color: 'var(--muted)' }}>{p.quantidade < 0 ? 'Ilimitado' : `${p.quantidade}/mês`} · {p.recorrente ? 'recorrente' : `${p.periodo_dias} dias`}</div>
                <div className="font-display" style={{ fontSize: 22, fontWeight: 800, color: 'var(--accent)', margin: '10px 0' }}>{brl(p.valor)}<span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 400 }}> /mês</span></div>
                {p.ja_disponivel && (
                  <div style={{ fontSize: 11, color: 'var(--success)', marginBottom: 6 }}>✓ Já incluso no seu plano — contrate para cota/serviço adicional</div>
                )}
                <button onClick={() => setConfirmar(p)} disabled={enviando === p.id}
                  style={{ width: '100%', padding: '9px', borderRadius: 8, background: 'var(--accent)', border: 'none', color: '#000', fontWeight: 700, fontSize: 14, cursor: enviando === p.id ? 'not-allowed' : 'pointer' }}>
                  {enviando === p.id ? '⟳ Solicitando...' : 'Solicitar contratação'}
                </button>
              </div>
            ))}
          </div>

          <h2 className="font-display" style={{ fontSize: 18, fontWeight: 800, color: 'var(--text)', marginBottom: 12 }}>Minhas solicitações</h2>
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
            {solics.length === 0 ? (
              <p style={{ padding: 24, color: 'var(--muted)', fontSize: 14, textAlign: 'center' }}>Nenhuma solicitação ainda.</p>
            ) : (
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <tbody>
                  {solics.map((s, i) => (
                    <tr key={s.id} style={{ borderBottom: i < solics.length - 1 ? '1px solid var(--border)' : undefined }}>
                      <td style={{ padding: '12px 16px', fontWeight: 600 }}>{s.pacote?.nome ?? '—'}</td>
                      <td style={{ padding: '12px 16px', fontSize: 13, color: 'var(--muted)' }}>{s.pacote ? (SERVICO_LABEL[s.pacote.servico] ?? s.pacote.servico) : ''}</td>
                      <td style={{ padding: '12px 16px' }}>
                        <span className={`pill ${STATUS[s.status]?.cls ?? 'pill-muted'}`}>{STATUS[s.status]?.label ?? s.status}</span>
                        {s.observacao && <div style={{ fontSize: 12, color: 'var(--danger)', marginTop: 4 }}>{s.observacao}</div>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </>
      )}
    </div>
  )
}
