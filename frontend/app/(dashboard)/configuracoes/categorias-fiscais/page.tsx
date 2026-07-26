'use client'

import { useEffect, useState } from 'react'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

interface LinhaCategoria {
  categoria: string
  ncm: string | null
  origem: number | null
  tributacao_icms: string | null
}

export default function CategoriasFiscaisPage() {
  const [linhas, setLinhas] = useState<LinhaCategoria[]>([])
  const [carregando, setCarregando] = useState(true)
  const [erroCarregamento, setErroCarregamento] = useState(false)
  const [salvando, setSalvando] = useState(false)

  useEffect(() => {
    api.get('/categorias-fiscais')
      .then(({ data }) => {
        setLinhas(data.data ?? [])
        setErroCarregamento(false)
      })
      .catch(() => {
        setErroCarregamento(true)
      })
      .finally(() => setCarregando(false))
  }, [])

  function alterar(indice: number, campo: keyof LinhaCategoria, valor: string) {
    setLinhas((atual) =>
      atual.map((linha, i) =>
        i === indice
          ? { ...linha, [campo]: campo === 'origem' ? (valor === '' ? null : Number(valor)) : (valor === '' ? null : valor) }
          : linha,
      ),
    )
  }

  async function salvar() {
    setSalvando(true)
    try {
      const { data } = await api.put('/categorias-fiscais', { categorias: linhas })
      setLinhas(data.data ?? [])
      toast('Padrões salvos com sucesso.', 'success')
    } catch {
      toast('Não foi possível salvar. Confira NCM (8 dígitos) e origem (0 a 8).', 'danger')
    } finally {
      setSalvando(false)
    }
  }

  if (carregando) {
    return <div style={{ padding: 24, color: 'var(--muted)' }}>Carregando…</div>
  }

  if (erroCarregamento) {
    return <div style={{ padding: 24, color: 'var(--danger)' }}>Não foi possível carregar os padrões fiscais.</div>
  }

  const input: React.CSSProperties = {
    width: '100%', padding: '6px 8px', background: 'var(--bg)',
    border: '1px solid var(--border)', borderRadius: 4, color: 'var(--text)', fontSize: 13,
  }

  return (
    <div style={{ padding: 24, maxWidth: 900 }}>
      <p style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 20 }}>
        Valores usados como ponto de partida para produtos cadastrados manualmente, sem nota de
        entrada. Produtos importados por XML recebem o dado do próprio fornecedor e ignoram estes
        padrões. Deixe em branco o que você não souber — um NCM errado é pior que um NCM ausente.
      </p>

      <table style={{ width: '100%', borderCollapse: 'collapse', background: 'var(--card)' }}>
        <thead>
          <tr style={{ borderBottom: '1px solid var(--border)', textAlign: 'left' }}>
            <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Categoria</th>
            <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>NCM</th>
            <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Origem</th>
            <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Tributação ICMS</th>
          </tr>
        </thead>
        <tbody>
          {linhas.map((linha, i) => (
            <tr key={linha.categoria} style={{ borderBottom: '1px solid var(--border)' }}>
              <td style={{ padding: 10 }}>{linha.categoria}</td>
              <td style={{ padding: 10 }}>
                <input
                  value={linha.ncm ?? ''}
                  onChange={(e) => alterar(i, 'ncm', e.target.value)}
                  placeholder="8 dígitos"
                  maxLength={8}
                  style={input}
                />
              </td>
              <td style={{ padding: 10 }}>
                <select value={linha.origem ?? ''} onChange={(e) => alterar(i, 'origem', e.target.value)} style={input}>
                  <option value="">—</option>
                  {[0, 1, 2, 3, 4, 5, 6, 7, 8].map((n) => (
                    <option key={n} value={n}>{n}</option>
                  ))}
                </select>
              </td>
              <td style={{ padding: 10 }}>
                <select
                  value={linha.tributacao_icms ?? ''}
                  onChange={(e) => alterar(i, 'tributacao_icms', e.target.value)}
                  style={input}
                >
                  <option value="">—</option>
                  <option value="NORMAL">Normal</option>
                  <option value="ST">Substituição tributária</option>
                </select>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <div style={{ marginTop: 16, display: 'flex', alignItems: 'center', gap: 12 }}>
        <button
          onClick={salvar}
          disabled={salvando}
          style={{ padding: '9px 18px', background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 6, fontFamily: 'Barlow Condensed', fontWeight: 800, fontSize: 15, cursor: salvando ? 'default' : 'pointer' }}
        >
          {salvando ? 'Salvando…' : 'Salvar padrões'}
        </button>
      </div>
    </div>
  )
}
