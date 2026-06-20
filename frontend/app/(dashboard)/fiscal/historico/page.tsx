'use client'
import { useState, useEffect, useCallback } from 'react'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarMoeda, formatarData } from '@/lib/formatters'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

interface NotaFiscal {
  id: string
  numero: number | null
  cliente?: { nome: string }
  emitido_em: string | null
  valor_total: number | null
  modelo: string
  status: string
}

const API_URL = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000'

export default function HistoricoNFPage() {
  const [notas, setNotas]               = useState<NotaFiscal[]>([])
  const [loading, setLoading]           = useState(true)
  const [selected, setSelected]         = useState<Set<string>>(new Set())
  const [baixandoZip, setBaixandoZip]   = useState(false)
  const [cancelModal, setCancelModal]   = useState<{ id: string } | null>(null)
  const [motivo, setMotivo]             = useState('')
  const [cancelando, setCancelando]     = useState(false)

  const fetchNotas = useCallback(() => {
    setLoading(true)
    api.get('/notas-fiscais')
      .then(r => { setNotas(r.data.data ?? []); setSelected(new Set()) })
      .catch(() => setNotas([]))
      .finally(() => setLoading(false))
  }, [])

  useEffect(fetchNotas, [fetchNotas])

  function toggleSelect(id: string) {
    setSelected(prev => {
      const next = new Set(prev)
      next.has(id) ? next.delete(id) : next.add(id)
      return next
    })
  }

  function toggleAll() {
    if (selected.size === notas.length) {
      setSelected(new Set())
    } else {
      setSelected(new Set(notas.map(n => n.id)))
    }
  }

  async function baixarZip() {
    if (selected.size === 0) return
    setBaixandoZip(true)
    try {
      const token = localStorage.getItem('auth_token')
      const res = await fetch(`${API_URL}/api/notas-fiscais/download-zip`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`,
          'X-Tenant': localStorage.getItem('tenant_slug') ?? '',
        },
        body: JSON.stringify({ ids: Array.from(selected) }),
      })
      if (!res.ok) throw new Error()
      const blob = await res.blob()
      const url  = URL.createObjectURL(blob)
      const a    = document.createElement('a')
      a.href     = url
      a.download = 'notas_fiscais.zip'
      a.click()
      URL.revokeObjectURL(url)
      toast(`${selected.size} NF(s) baixadas com sucesso.`, 'success')
    } catch {
      toast('Erro ao gerar ZIP.', 'danger')
    } finally {
      setBaixandoZip(false)
    }
  }

  async function baixarPdf(nota: NotaFiscal) {
    try {
      const token = localStorage.getItem('auth_token')
      const res = await fetch(`${API_URL}/api/notas-fiscais/${nota.id}/pdf`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'X-Tenant': localStorage.getItem('tenant_slug') ?? '',
        },
      })
      if (!res.ok) throw new Error()
      const blob = await res.blob()
      const url  = URL.createObjectURL(blob)
      const a    = document.createElement('a')
      a.href     = url
      a.download = `NF-${nota.numero}.pdf`
      a.click()
      URL.revokeObjectURL(url)
    } catch {
      toast('Erro ao gerar PDF.', 'danger')
    }
  }

  async function confirmarCancelamento() {
    if (!cancelModal) return
    if (motivo.length < 10) { toast('O motivo deve ter no mínimo 10 caracteres.', 'danger'); return }
    setCancelando(true)
    try {
      await api.post(`/notas-fiscais/${cancelModal.id}/cancelar`, { motivo })
      toast('Nota Fiscal cancelada.', 'success')
      setCancelModal(null)
      fetchNotas()
    } catch {
      toast('Erro ao cancelar NF.', 'danger')
    } finally {
      setCancelando(false)
    }
  }

  const thStyle: React.CSSProperties = {
    padding: '10px 12px', textAlign: 'left', fontSize: 11, fontWeight: 700,
    color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em',
    borderBottom: '1px solid var(--border)', whiteSpace: 'nowrap',
  }
  const tdStyle: React.CSSProperties = {
    padding: '12px 12px', fontSize: 14, color: 'var(--text)',
    borderBottom: '1px solid var(--border)',
  }

  const allChecked = notas.length > 0 && selected.size === notas.length
  const someChecked = selected.size > 0 && selected.size < notas.length

  return (
    <div>
      {/* Header row */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 20 }}>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
          Histórico de Notas Fiscais
        </h1>
        {selected.size > 0 && (
          <button
            onClick={baixarZip}
            disabled={baixandoZip}
            className="font-display"
            style={{
              display: 'flex', alignItems: 'center', gap: 8,
              padding: '8px 20px', borderRadius: 8, border: 'none',
              background: baixandoZip ? 'var(--border)' : 'var(--accent)',
              color: baixandoZip ? 'var(--muted)' : '#000',
              fontSize: 14, fontWeight: 700,
              cursor: baixandoZip ? 'not-allowed' : 'pointer',
            }}
          >
            {baixandoZip ? '⟳ Gerando ZIP...' : `⬇ Baixar ${selected.size} NF(s) em ZIP`}
          </button>
        )}
      </div>

      {/* Table */}
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
        {loading ? (
          <div style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Carregando...</div>
        ) : notas.length === 0 ? (
          <div style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Nenhuma NF emitida ainda.</div>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr>
                <th style={{ ...thStyle, width: 44 }}>
                  <input
                    type="checkbox"
                    checked={allChecked}
                    ref={el => { if (el) el.indeterminate = someChecked }}
                    onChange={toggleAll}
                    style={{ cursor: 'pointer', accentColor: 'var(--accent)', width: 16, height: 16 }}
                  />
                </th>
                <th style={thStyle}>#NF</th>
                <th style={thStyle}>Cliente</th>
                <th style={thStyle}>Emissão</th>
                <th style={thStyle}>Valor</th>
                <th style={thStyle}>Modelo</th>
                <th style={thStyle}>Situação</th>
                <th style={{ ...thStyle, width: 160 }}></th>
              </tr>
            </thead>
            <tbody>
              {notas.map(nota => (
                <tr key={nota.id} style={{ background: selected.has(nota.id) ? 'rgba(245,166,35,.04)' : undefined }}>
                  <td style={tdStyle}>
                    <input
                      type="checkbox"
                      checked={selected.has(nota.id)}
                      onChange={() => toggleSelect(nota.id)}
                      style={{ cursor: 'pointer', accentColor: 'var(--accent)', width: 16, height: 16 }}
                    />
                  </td>
                  <td style={tdStyle}>
                    <span className="font-mono" style={{ color: 'var(--accent)' }}>
                      {nota.numero ? `#${nota.numero}` : '-'}
                    </span>
                  </td>
                  <td style={tdStyle}>{nota.cliente?.nome ?? '-'}</td>
                  <td style={tdStyle}>{formatarData(nota.emitido_em)}</td>
                  <td style={tdStyle}>
                    <span className="font-mono">
                      {nota.valor_total ? formatarMoeda(nota.valor_total) : '-'}
                    </span>
                  </td>
                  <td style={tdStyle}>
                    <span style={{ color: 'var(--muted)', fontSize: 13 }}>{nota.modelo}</span>
                  </td>
                  <td style={tdStyle}><StatusPill status={nota.status} /></td>
                  <td style={tdStyle}>
                    <div style={{ display: 'flex', gap: 8 }}>
                      {nota.status === 'AUTORIZADA' && nota.numero && (
                        <button
                          onClick={() => baixarPdf(nota)}
                          style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, padding: '4px 10px', cursor: 'pointer', fontSize: 13 }}
                        >
                          📄 PDF
                        </button>
                      )}
                      {nota.status === 'AUTORIZADA' && (
                        <button
                          onClick={() => { setCancelModal({ id: nota.id }); setMotivo('') }}
                          style={{ background: 'none', border: '1px solid var(--danger)', color: 'var(--danger)', borderRadius: 6, padding: '4px 10px', cursor: 'pointer', fontSize: 13, whiteSpace: 'nowrap' }}
                        >
                          Cancelar
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* Cancel modal */}
      {cancelModal && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.6)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, padding: 32, width: 440, maxWidth: '90vw' }}>
            <h3 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', marginBottom: 8 }}>
              Cancelar Nota Fiscal
            </h3>
            <p style={{ color: 'var(--muted)', fontSize: 14, marginBottom: 20 }}>
              Esta ação não pode ser desfeita. Informe o motivo do cancelamento.
            </p>
            <label style={{ color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 6 }}>
              Motivo <span style={{ color: 'var(--danger)' }}>*</span>
            </label>
            <textarea
              value={motivo}
              onChange={e => setMotivo(e.target.value)}
              rows={3}
              placeholder="Descreva o motivo do cancelamento (mínimo 10 caracteres)..."
              style={{
                width: '100%', background: 'var(--card)',
                border: `1px solid ${motivo.length > 0 && motivo.length < 10 ? 'var(--danger)' : 'var(--border)'}`,
                borderRadius: 8, color: 'var(--text)', padding: '10px 12px',
                fontSize: 14, resize: 'vertical', outline: 'none', boxSizing: 'border-box',
              }}
            />
            {motivo.length > 0 && motivo.length < 10 && (
              <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>
                Mínimo 10 caracteres ({motivo.length}/10)
              </p>
            )}
            <div style={{ display: 'flex', gap: 12, marginTop: 24, justifyContent: 'flex-end' }}>
              <button
                onClick={() => setCancelModal(null)}
                disabled={cancelando}
                style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 8, padding: '8px 20px', cursor: 'pointer', fontSize: 14 }}
              >
                Voltar
              </button>
              <button
                onClick={confirmarCancelamento}
                disabled={cancelando || motivo.length < 10}
                style={{
                  background: 'var(--danger)', color: '#fff', border: 'none', borderRadius: 8,
                  padding: '8px 20px', fontSize: 14,
                  cursor: cancelando || motivo.length < 10 ? 'not-allowed' : 'pointer',
                  opacity: cancelando || motivo.length < 10 ? 0.6 : 1,
                }}
              >
                {cancelando ? 'Cancelando...' : 'Confirmar Cancelamento'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
