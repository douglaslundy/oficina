'use client'
import { useState, useEffect, useCallback } from 'react'
import saasApi from '@/lib/saas-api'

const SERVICO_LABEL: Record<string, string> = {
  ALERTA_WHATSAPP: 'Alertas WhatsApp', ALERTA_EMAIL: 'Alertas E-mail', ORCAMENTO: 'Orçamentos',
}
const STATUS: Record<string, { label: string; cls: string }> = {
  PENDENTE: { label: 'Pendente', cls: 'pill-accent' },
  APROVADA: { label: 'Aprovada', cls: 'pill-success' },
  RECUSADA: { label: 'Recusada', cls: 'pill-danger' },
}

interface Solic {
  id: string; status: string; observacao: string | null; criado_em: string
  oficina: { id: string; nome: string } | null
  pacote: { nome: string; servico: string; valor: string; quantidade: number; recorrente: boolean; periodo_dias: number | null } | null
}

function brl(v: number | string) {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(parseFloat(String(v)) || 0)
}

export default function SolicitacoesPage() {
  const [lista, setLista] = useState<Solic[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState<string | null>(null)

  const carregar = useCallback(() => {
    setLoading(true)
    saasApi.get<{ data: Solic[] }>('/saas/solicitacoes').then(r => setLista(r.data.data ?? [])).catch(() => setLista([])).finally(() => setLoading(false))
  }, [])
  useEffect(() => { carregar() }, [carregar])

  async function aprovar(id: string) {
    setBusy(id)
    try { await saasApi.post(`/saas/solicitacoes/${id}/aprovar`); carregar() } catch { /* ignore */ } finally { setBusy(null) }
  }
  async function recusar(id: string) {
    const obs = prompt('Motivo da recusa (opcional):') ?? undefined
    setBusy(id)
    try { await saasApi.post(`/saas/solicitacoes/${id}/recusar`, { observacao: obs }); carregar() } catch { /* ignore */ } finally { setBusy(null) }
  }

  return (
    <div style={{ padding: 32, maxWidth: 1000, margin: '0 auto', color: 'var(--text)' }}>
      <div style={{ marginBottom: 24 }}>
        <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, margin: 0 }}>Solicitações de Serviço</h1>
        <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>Pedidos de pacotes avulsos feitos pelas oficinas</p>
      </div>
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Oficina', 'Pacote', 'Serviço', 'Valor', 'Status', 'Ações'].map(c => (
            <th key={c} style={{ padding: '11px 16px', textAlign: 'left', fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', borderBottom: '1px solid var(--border)', background: 'var(--surface)' }}>{c}</th>
          ))}</tr></thead>
          <tbody>
            {loading ? <tr><td colSpan={6} style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Carregando...</td></tr>
              : lista.length === 0 ? <tr><td colSpan={6} style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Nenhuma solicitação.</td></tr>
              : lista.map((s, i) => (
                <tr key={s.id} style={{ borderBottom: i < lista.length - 1 ? '1px solid var(--border)' : undefined }}>
                  <td style={{ padding: '12px 16px', fontWeight: 600 }}>{s.oficina?.nome ?? '—'}</td>
                  <td style={{ padding: '12px 16px', fontSize: 13 }}>{s.pacote?.nome ?? '—'}</td>
                  <td style={{ padding: '12px 16px', fontSize: 13, color: 'var(--muted)' }}>{s.pacote ? (SERVICO_LABEL[s.pacote.servico] ?? s.pacote.servico) : ''}</td>
                  <td style={{ padding: '12px 16px', fontFamily: "'JetBrains Mono', monospace", color: 'var(--accent)' }}>{s.pacote ? brl(s.pacote.valor) : '—'}</td>
                  <td style={{ padding: '12px 16px' }}>
                    <span className={`pill ${STATUS[s.status]?.cls ?? 'pill-muted'}`}>{STATUS[s.status]?.label ?? s.status}</span>
                    {s.observacao && <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 4 }}>{s.observacao}</div>}
                  </td>
                  <td style={{ padding: '12px 16px' }}>
                    {s.status === 'PENDENTE' ? (
                      <div style={{ display: 'flex', gap: 8 }}>
                        <button onClick={() => aprovar(s.id)} disabled={busy === s.id} style={{ padding: '5px 12px', borderRadius: 6, background: 'rgba(67,160,71,.15)', border: '1px solid var(--success)', color: 'var(--success)', cursor: 'pointer', fontSize: 13, fontWeight: 700 }}>Aprovar</button>
                        <button onClick={() => recusar(s.id)} disabled={busy === s.id} style={{ padding: '5px 12px', borderRadius: 6, background: 'rgba(229,57,53,.1)', border: '1px solid var(--danger)', color: 'var(--danger)', cursor: 'pointer', fontSize: 13 }}>Recusar</button>
                      </div>
                    ) : <span style={{ color: 'var(--muted)', fontSize: 13 }}>—</span>}
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
