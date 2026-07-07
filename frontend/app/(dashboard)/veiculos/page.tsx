'use client'
import { useEffect, useState } from 'react'
import { useRouter } from 'next/navigation'
import { formatarPlaca } from '@/lib/formatters'
import api from '@/lib/api'

interface VeiculoBusca {
  id: string
  placa: string | null
  modelo: string
  ano: number | null
  ativo: boolean
  cliente_id: string
  cliente_nome: string | null
}

export default function VeiculosPage() {
  const router = useRouter()
  const [placa, setPlaca] = useState('')
  const [resultados, setResultados] = useState<VeiculoBusca[]>([])
  const [loading, setLoading] = useState(false)
  const [buscou, setBuscou] = useState(false)

  useEffect(() => {
    if (!placa.trim()) {
      setResultados([])
      setBuscou(false)
      return
    }
    const timeout = setTimeout(() => {
      setLoading(true)
      api.get('/veiculos/busca', { params: { placa } })
        .then(res => setResultados(res.data ?? []))
        .catch(() => setResultados([]))
        .finally(() => { setLoading(false); setBuscou(true) })
    }, 300)
    return () => clearTimeout(timeout)
  }, [placa])

  return (
    <div style={{ maxWidth: 720, margin: '0 auto' }}>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 4 }}>Veículos</h1>
      <p style={{ color: 'var(--muted)', fontSize: 14, marginBottom: 24 }}>Digite a placa para consultar o histórico completo de um veículo.</p>

      <input
        autoFocus
        placeholder="Digite a placa (ex: ABC-1234)"
        value={placa}
        onChange={e => setPlaca(e.target.value)}
        className="font-mono"
        style={{
          width: '100%', padding: '14px 16px', borderRadius: 10,
          background: 'var(--card)', border: '1px solid var(--border)',
          color: 'var(--text)', fontSize: 18, outline: 'none', boxSizing: 'border-box',
        }}
      />

      <div style={{ marginTop: 16, display: 'flex', flexDirection: 'column', gap: 8 }}>
        {loading && <p style={{ color: 'var(--muted)', fontSize: 14 }}>Buscando...</p>}

        {!loading && buscou && resultados.length === 0 && (
          <p style={{ color: 'var(--muted)', fontSize: 14 }}>Nenhum veículo encontrado com essa placa.</p>
        )}

        {!loading && resultados.map(v => (
          <div
            key={v.id}
            onClick={() => router.push(`/veiculos/${v.id}`)}
            style={{
              display: 'flex', justifyContent: 'space-between', alignItems: 'center',
              padding: '14px 16px', borderRadius: 10,
              background: 'var(--card)', border: '1px solid var(--border)', cursor: 'pointer',
            }}
          >
            <div>
              <p style={{ margin: 0, fontSize: 15, color: 'var(--text)', fontWeight: 700 }}>
                {v.modelo}{v.ano ? ` ${v.ano}` : ''}
              </p>
              <p style={{ margin: '2px 0 0', fontSize: 13, color: 'var(--muted)' }}>
                {v.cliente_nome ?? 'Sem proprietário'}
                {!v.ativo && <span style={{ color: 'var(--danger)' }}> · Inativo</span>}
              </p>
            </div>
            {v.placa && (
              <span className="font-mono" style={{ color: 'var(--accent)', fontSize: 15, fontWeight: 700 }}>
                {formatarPlaca(v.placa)}
              </span>
            )}
          </div>
        ))}
      </div>
    </div>
  )
}
