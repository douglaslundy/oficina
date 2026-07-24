'use client'
import { useState, useEffect, useCallback } from 'react'
import saasApi from '@/lib/saas-api'
import { formatarDataHora } from '@/lib/formatters'

interface LogRow {
  id: string
  ip: string | null
  user_agent: string | null
  visualizado_em: string
  oficina?: { nome: string } | null
  usuario?: { nome: string } | null
}
interface Paginated {
  data: LogRow[]
  current_page: number
  last_page: number
  total: number
}

export function NotificacaoLogInline({ endpoint, mostrarOficina, colSpan }: {
  endpoint: string
  mostrarOficina: boolean
  colSpan: number
}) {
  const [logs, setLogs] = useState<Paginated | null>(null)
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)

  const carregar = useCallback(() => {
    setLoading(true)
    const sep = endpoint.includes('?') ? '&' : '?'
    saasApi.get<Paginated>(`${endpoint}${sep}page=${page}`)
      .then(r => setLogs(r.data))
      .catch(() => setLogs(null))
      .finally(() => setLoading(false))
  }, [endpoint, page])

  useEffect(() => { carregar() }, [carregar])

  return (
    <tr>
      <td colSpan={colSpan} style={{ padding: 0, background: 'var(--bg)' }}>
        <div style={{ padding: '14px 20px' }}>
          {loading ? (
            <div style={{ color: 'var(--muted)', fontSize: 13, padding: 12 }}>Carregando...</div>
          ) : !logs || logs.data.length === 0 ? (
            <div style={{ color: 'var(--muted)', fontSize: 13, padding: 12 }}>Nenhuma visualização registrada.</div>
          ) : (
            <>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr>
                    {[...(mostrarOficina ? ['Oficina'] : []), 'Usuário', 'Data/Hora', 'IP'].map(h => (
                      <th key={h} style={{ padding: '6px 10px', textAlign: 'left', fontSize: 10, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em' }}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {logs.data.map(l => (
                    <tr key={l.id} style={{ borderTop: '1px solid var(--border)' }}>
                      {mostrarOficina && <td style={{ padding: '6px 10px', fontSize: 12 }}>{l.oficina?.nome ?? '—'}</td>}
                      <td style={{ padding: '6px 10px', fontSize: 12 }}>{l.usuario?.nome ?? '—'}</td>
                      <td style={{ padding: '6px 10px', fontSize: 12, fontFamily: 'monospace' }}>{formatarDataHora(l.visualizado_em)}</td>
                      <td style={{ padding: '6px 10px', fontSize: 12, fontFamily: 'monospace' }}>{l.ip ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {logs.last_page > 1 && (
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 10 }}>
                  <span style={{ fontSize: 11, color: 'var(--muted)' }}>{logs.total} registros · Página {logs.current_page} de {logs.last_page}</span>
                  <div style={{ display: 'flex', gap: 6 }}>
                    <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} style={{ padding: '3px 10px', borderRadius: 5, background: 'transparent', border: '1px solid var(--border)', color: page <= 1 ? 'var(--border)' : 'var(--muted)', cursor: page <= 1 ? 'not-allowed' : 'pointer', fontSize: 11 }}>← Anterior</button>
                    <button disabled={page >= logs.last_page} onClick={() => setPage(p => p + 1)} style={{ padding: '3px 10px', borderRadius: 5, background: 'transparent', border: '1px solid var(--border)', color: page >= logs.last_page ? 'var(--border)' : 'var(--muted)', cursor: page >= logs.last_page ? 'not-allowed' : 'pointer', fontSize: 11 }}>Próxima →</button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      </td>
    </tr>
  )
}
