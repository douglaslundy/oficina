'use client'
import { useState, useEffect, useCallback } from 'react'
import { DataTable, Column } from '@/components/ui/DataTable'
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

export default function HistoricoNFPage() {
  const [notas, setNotas] = useState<NotaFiscal[]>([])
  const [loading, setLoading] = useState(true)
  const [cancelModal, setCancelModal] = useState<{ id: string } | null>(null)
  const [motivoCancelamento, setMotivoCancelamento] = useState('')
  const [cancelando, setCancelando] = useState(false)

  const fetchNotas = useCallback(() => {
    setLoading(true)
    api.get('/notas-fiscais')
      .then(r => setNotas(r.data.data ?? []))
      .catch(() => setNotas([]))
      .finally(() => setLoading(false))
  }, [])

  useEffect(fetchNotas, [fetchNotas])

  function abrirCancelar(id: string) {
    setCancelModal({ id })
    setMotivoCancelamento('')
  }

  async function confirmarCancelamento() {
    if (!cancelModal) return
    if (motivoCancelamento.length < 10) {
      toast('O motivo deve ter no mínimo 10 caracteres.', 'danger')
      return
    }
    setCancelando(true)
    try {
      await api.post(`/notas-fiscais/${cancelModal.id}/cancelar`, { motivo: motivoCancelamento })
      toast('Nota Fiscal cancelada.', 'success')
      setCancelModal(null)
      fetchNotas()
    } catch {
      toast('Erro ao cancelar NF.', 'danger')
    } finally {
      setCancelando(false)
    }
  }

  const columns: Column<NotaFiscal>[] = [
    {
      key: 'numero',
      label: '#NF',
      render: r => (
        <span className="font-mono" style={{ color: 'var(--accent)' }}>
          {r.numero ? `#${r.numero}` : '-'}
        </span>
      ),
    },
    {
      key: 'cliente',
      label: 'Cliente',
      render: r => r.cliente?.nome ?? '-',
    },
    {
      key: 'emitido_em',
      label: 'Emissão',
      render: r => formatarData(r.emitido_em),
    },
    {
      key: 'valor_total',
      label: 'Valor',
      render: r => (
        <span className="font-mono">
          {r.valor_total ? formatarMoeda(r.valor_total) : '-'}
        </span>
      ),
    },
    {
      key: 'modelo',
      label: 'Modelo',
      render: r => <span style={{ color: 'var(--muted)', fontSize: 13 }}>{r.modelo}</span>,
    },
    {
      key: 'status',
      label: 'Situação',
      render: r => <StatusPill status={r.status} />,
    },
    {
      key: 'acoes',
      label: '',
      render: r => (
        <div style={{ display: 'flex', gap: 8 }}>
          {r.status === 'AUTORIZADA' && r.numero && (
            <button
              onClick={async e => {
                e.stopPropagation()
                try {
                  const token = localStorage.getItem('auth_token')
                  const res = await fetch(
                    `${process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000'}/api/notas-fiscais/${r.id}/pdf`,
                    { headers: { Authorization: `Bearer ${token}` } }
                  )
                  if (!res.ok) throw new Error()
                  const blob = await res.blob()
                  const url = URL.createObjectURL(blob)
                  const a = document.createElement('a')
                  a.href = url
                  a.download = `NF-${r.numero}.pdf`
                  a.click()
                  URL.revokeObjectURL(url)
                } catch { alert('Erro ao gerar PDF.') }
              }}
              style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, padding: '4px 10px', cursor: 'pointer', fontSize: 13 }}>
              📄 PDF
            </button>
          )}
          {r.status === 'AUTORIZADA' ? (
            <button
              onClick={e => { e.stopPropagation(); abrirCancelar(r.id) }}
              style={{
                background: 'none',
                border: '1px solid var(--danger)',
                color: 'var(--danger)',
                borderRadius: 6,
                padding: '4px 10px',
                cursor: 'pointer',
                fontSize: 13,
                whiteSpace: 'nowrap',
              }}
            >
              Cancelar
            </button>
          ) : null}
        </div>
      ),
    },
  ]

  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>
        Histórico de Notas Fiscais
      </h1>
      <DataTable
        columns={columns}
        data={notas}
        loading={loading}
        emptyMessage="Nenhuma NF emitida ainda."
      />
      {cancelModal && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.6)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, padding: 32, width: 440, maxWidth: '90vw' }}>
            <h3 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', marginBottom: 8 }}>Cancelar Nota Fiscal</h3>
            <p style={{ color: 'var(--muted)', fontSize: 14, marginBottom: 20 }}>Esta ação não pode ser desfeita. Informe o motivo do cancelamento.</p>
            <label style={{ color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 6 }}>Motivo <span style={{ color: 'var(--danger)' }}>*</span></label>
            <textarea
              value={motivoCancelamento}
              onChange={e => setMotivoCancelamento(e.target.value)}
              rows={3}
              placeholder="Descreva o motivo do cancelamento (mínimo 10 caracteres)..."
              style={{ width: '100%', background: 'var(--card)', border: `1px solid ${motivoCancelamento.length > 0 && motivoCancelamento.length < 10 ? 'var(--danger)' : 'var(--border)'}`, borderRadius: 8, color: 'var(--text)', padding: '10px 12px', fontSize: 14, resize: 'vertical', outline: 'none', boxSizing: 'border-box' }}
            />
            {motivoCancelamento.length > 0 && motivoCancelamento.length < 10 && (
              <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>Mínimo 10 caracteres ({motivoCancelamento.length}/10)</p>
            )}
            <div style={{ display: 'flex', gap: 12, marginTop: 24, justifyContent: 'flex-end' }}>
              <button
                onClick={() => setCancelModal(null)}
                disabled={cancelando}
                style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 8, padding: '8px 20px', cursor: 'pointer', fontSize: 14 }}>
                Voltar
              </button>
              <button
                onClick={confirmarCancelamento}
                disabled={cancelando || motivoCancelamento.length < 10}
                style={{ background: 'var(--danger)', color: '#fff', border: 'none', borderRadius: 8, padding: '8px 20px', cursor: cancelando || motivoCancelamento.length < 10 ? 'not-allowed' : 'pointer', fontSize: 14, opacity: cancelando || motivoCancelamento.length < 10 ? 0.6 : 1 }}>
                {cancelando ? 'Cancelando...' : 'Confirmar Cancelamento'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
