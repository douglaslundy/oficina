'use client'
import { useState, useEffect, useCallback, Fragment } from 'react'
import saasApi from '@/lib/saas-api'
import { formatarData, formatarMoeda } from '@/lib/formatters'
import { NotificacaoLogInline } from '@/components/saas/NotificacaoLogInline'

interface Grupo {
  oficina_id: string
  cobranca_id: string
  total_exibicoes: number
  ultima_exibicao_em: string
  oficina: { nome: string } | null
  cobranca: { valor: number; vencimento: string; status: string } | null
}

const FASE_LABEL: Record<string, string> = { PENDENTE: 'Disponível', VENCIDA: 'Vencida' }

export function NotificacaoCobrancaTable() {
  const [lista, setLista] = useState<Grupo[]>([])
  const [loading, setLoading] = useState(true)
  const [expandido, setExpandido] = useState<string | null>(null)

  const carregar = useCallback(() => {
    setLoading(true)
    saasApi.get<{ data: Grupo[] }>('/saas/notificacoes-cobranca')
      .then(r => setLista(r.data.data ?? []))
      .catch(() => setLista([]))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => { carregar() }, [carregar])

  return (
    <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead>
          <tr>
            {['Oficina', 'Valor', 'Vencimento', 'Fase', 'Exibições', ''].map(c => (
              <th key={c} style={{ padding: '11px 16px', textAlign: 'left', fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', borderBottom: '1px solid var(--border)', background: 'var(--surface)' }}>{c}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {loading ? (
            <tr><td colSpan={6} style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Carregando...</td></tr>
          ) : lista.length === 0 ? (
            <tr><td colSpan={6} style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Nenhuma exibição de alerta de cobrança registrada.</td></tr>
          ) : lista.map(g => {
            const chave = `${g.oficina_id}:${g.cobranca_id}`
            return (
              <Fragment key={chave}>
                <tr style={{ borderBottom: expandido === chave ? undefined : '1px solid var(--border)' }}>
                  <td style={{ padding: '12px 16px', fontWeight: 600 }}>{g.oficina?.nome ?? '—'}</td>
                  <td style={{ padding: '12px 16px', fontFamily: 'monospace' }}>{g.cobranca ? formatarMoeda(Number(g.cobranca.valor)) : '—'}</td>
                  <td style={{ padding: '12px 16px', fontSize: 13, color: 'var(--muted)' }}>{g.cobranca ? formatarData(g.cobranca.vencimento) : '—'}</td>
                  <td style={{ padding: '12px 16px' }}>
                    <span className={`pill ${g.cobranca?.status === 'VENCIDA' ? 'pill-danger' : 'pill-accent'}`}>
                      {FASE_LABEL[g.cobranca?.status ?? ''] ?? g.cobranca?.status ?? '—'}
                    </span>
                  </td>
                  <td style={{ padding: '12px 16px' }}>{g.total_exibicoes}</td>
                  <td style={{ padding: '12px 16px' }}>
                    <button onClick={() => setExpandido(expandido === chave ? null : chave)}
                      style={{ padding: '5px 12px', borderRadius: 6, background: 'rgba(30,136,229,.1)', border: '1px solid rgba(30,136,229,.3)', color: 'var(--info)', cursor: 'pointer', fontSize: 13 }}>
                      {expandido === chave ? '▲ Ocultar log' : '▼ Ver log'}
                    </button>
                  </td>
                </tr>
                {expandido === chave && (
                  <NotificacaoLogInline
                    endpoint={`/saas/notificacoes-cobranca/log?oficina_id=${g.oficina_id}&cobranca_id=${g.cobranca_id}`}
                    mostrarOficina={false}
                    colSpan={6}
                  />
                )}
              </Fragment>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
