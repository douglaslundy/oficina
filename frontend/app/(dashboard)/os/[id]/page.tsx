'use client'
import { useCallback, useEffect, useState } from 'react'
import { useParams, useRouter } from 'next/navigation'
import { OSForm } from '@/components/forms/OSForm'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarMoeda } from '@/lib/formatters'
import { toast } from '@/hooks/useToast'
import api from '@/lib/api'

const FORMAS_PAGAMENTO = ['Dinheiro', 'Cartão de Crédito', 'Cartão de Débito', 'PIX', 'Cheque', 'Transferência', 'Boleto']

interface OsItem {
  id: string
  tipo: 'SERVICO' | 'PECA'
  produto_id?: string
  descricao: string
  quantidade: number
  valor_unitario: number
  valor_total?: number
}

interface OsPagamento {
  id: string
  forma_pagamento: string
  valor: number
  criado_em: string
}

interface OsData {
  id: string
  numero: number
  status: string
  saldo_devedor: number
  valor_total?: number
  valor_pago?: number
  cliente_id: string
  cliente?: { id: string; nome: string; veiculo_placa?: string }
  mecanico_id?: string
  mecanico?: { id: string; nome: string }
  veiculo_descricao?: string
  veiculo_placa?: string
  problema_relatado?: string
  forma_pagamento?: string
  prazo_entrega?: string
  venda_a_prazo?: boolean
  prazo_pagamento_dias?: number
  data_vencimento_pagamento?: string
  itens?: OsItem[]
  pagamentos?: OsPagamento[]
}

function toInputDate(val?: string | null): string | undefined {
  if (!val) return undefined
  // dd/mm/YYYY → YYYY-MM-DD
  const m = val.match(/^(\d{2})\/(\d{2})\/(\d{4})/)
  if (m) return `${m[3]}-${m[2]}-${m[1]}`
  return val
}

export default function OSDetailPage() {
  const { id } = useParams<{ id: string }>()
  const router = useRouter()
  const [os, setOs] = useState<OsData | null>(null)

  const fetchOs = useCallback(() => {
    return api.get(`/os/${id}`).then(r => setOs(r.data.data)).catch(() => {})
  }, [id])

  useEffect(() => {
    fetchOs()
  }, [fetchOs])

  async function downloadFile(endpoint: string, filename: string) {
    try {
      const token = localStorage.getItem('auth_token')
      const slug  = localStorage.getItem('oficina_slug')
      const response = await fetch(
        `${process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000'}/api/os/${id}/${endpoint}`,
        { headers: { Authorization: `Bearer ${token}`, 'X-Tenant': slug ?? '' } }
      )
      if (!response.ok) throw new Error('Erro ao gerar PDF')
      const blob = await response.blob()
      const url  = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = filename
      a.click()
      URL.revokeObjectURL(url)
    } catch {
      alert('Erro ao baixar PDF.')
    }
  }

  const downloadPdf    = () => downloadFile('pdf',    `OS-${os?.numero ?? id}.pdf`)
  const downloadRecibo = () => downloadFile('recibo', `Recibo-OS-${os?.numero ?? id}.pdf`)

  const [novoPag, setNovoPag] = useState({ forma: 'Dinheiro', valor: '' })
  const [addingPag, setAddingPag] = useState(false)

  async function handleAddPagamento() {
    const valor = parseFloat(novoPag.valor)
    if (!valor || valor <= 0) { toast('Informe um valor válido.', 'danger'); return }
    setAddingPag(true)
    try {
      await api.post(`/os/${id}/pagamentos`, { forma_pagamento: novoPag.forma, valor })
      toast('Pagamento registrado!', 'success')
      setNovoPag({ forma: 'Dinheiro', valor: '' })
      fetchOs()
    } catch {
      toast('Erro ao registrar pagamento.', 'danger')
    } finally {
      setAddingPag(false)
    }
  }

  async function handleRemovePagamento(pagamentoId: string) {
    try {
      await api.delete(`/os/${id}/pagamentos/${pagamentoId}`)
      toast('Pagamento removido.', 'success')
      fetchOs()
    } catch {
      toast('Erro ao remover pagamento.', 'danger')
    }
  }

  if (!os) return <p style={{ color: 'var(--muted)' }}>Carregando...</p>

  const formData = {
    ...os,
    prazo_entrega: toInputDate(os.prazo_entrega),
  }

  return (
    <div style={{ maxWidth: 900, margin: '0 auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 24, flexWrap: 'wrap' }}>
        <button onClick={() => router.back()}
          style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>
          ← Voltar
        </button>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
          OS #{os.numero}
        </h1>
        <StatusPill status={os.status} />
        {os.saldo_devedor > 0 && (
          <span style={{ background: 'rgba(229,57,53,0.15)', color: 'var(--danger)', borderRadius: 8, padding: '4px 12px', fontSize: 14, fontWeight: 700 }}>
            Saldo: {formatarMoeda(os.saldo_devedor)}
          </span>
        )}
        <button onClick={downloadPdf}
          style={{ padding: '6px 14px', background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 8, cursor: 'pointer', fontSize: 13 }}>
          📄 PDF
        </button>
        {(os.pagamentos ?? []).reduce((s, p) => s + Number(p.valor), 0) > 0 && (
          <button onClick={downloadRecibo}
            style={{ padding: '6px 14px', background: 'transparent', border: '1px solid var(--success)', color: 'var(--success)', borderRadius: 8, cursor: 'pointer', fontSize: 13 }}>
            🧾 Recibo
          </button>
        )}
      </div>
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 32 }}>
        <OSForm
          initialData={formData}
          onSuccess={() => fetchOs()}
        />
      </div>

      {/* Pagamentos */}
      {os.status !== 'CANCELADA' && (
        <div style={{ marginTop: 24, background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
          <h2 className="font-display" style={{ fontSize: 18, fontWeight: 800, color: 'var(--text)', marginBottom: 16 }}>
            Pagamentos
          </h2>

          {/* Lista de pagamentos */}
          {(os.pagamentos ?? []).length > 0 ? (
            <div style={{ marginBottom: 20 }}>
              {(os.pagamentos ?? []).map(p => (
                <div key={p.id} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '10px 0', borderBottom: '1px solid var(--border)' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                    <span style={{ color: 'var(--muted)', fontSize: 13 }}>{p.criado_em}</span>
                    <span style={{ padding: '2px 10px', borderRadius: 999, background: 'rgba(30,136,229,.15)', color: 'var(--info)', fontSize: 12, fontWeight: 600 }}>{p.forma_pagamento}</span>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                    <span className="font-mono" style={{ color: 'var(--success)', fontWeight: 700 }}>{formatarMoeda(p.valor)}</span>
                    {os.status !== 'CONCLUIDA' && (
                      <button onClick={() => handleRemovePagamento(p.id)}
                        style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 18, padding: '0 4px', lineHeight: 1 }}>
                        ×
                      </button>
                    )}
                  </div>
                </div>
              ))}
              {(() => {
                const totalPago = (os.pagamentos ?? []).reduce((s, p) => s + Number(p.valor), 0)
                const diff = totalPago - Number(os.valor_total ?? 0)
                const isTroco = diff > 0
                const hasDiff = Math.abs(diff) > 0.001
                return (
                  <>
                    <div style={{ display: 'flex', justifyContent: 'space-between', paddingTop: 12, marginTop: 4 }}>
                      <span style={{ color: 'var(--muted)', fontSize: 13, fontWeight: 600 }}>Total pago</span>
                      <span className="font-mono" style={{ color: 'var(--success)', fontWeight: 700, fontSize: 15 }}>
                        {formatarMoeda(totalPago)}
                      </span>
                    </div>
                    {hasDiff && (
                      <div style={{ display: 'flex', justifyContent: 'space-between', paddingTop: 8, marginTop: 4 }}>
                        <span style={{ color: isTroco ? 'var(--success)' : 'var(--danger)', fontSize: 13, fontWeight: 600 }}>
                          {isTroco ? 'Troco' : 'Falta pagar'}
                        </span>
                        <span className="font-mono" style={{ color: isTroco ? 'var(--success)' : 'var(--danger)', fontWeight: 700, fontSize: 15 }}>
                          {formatarMoeda(Math.abs(diff))}
                        </span>
                      </div>
                    )}
                  </>
                )
              })()}
            </div>
          ) : (
            <p style={{ color: 'var(--muted)', fontSize: 14, marginBottom: 20 }}>Nenhum pagamento registrado.</p>
          )}

          {/* Formulário novo pagamento */}
          {os.status !== 'CONCLUIDA' && (
            <div style={{ display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
              <div>
                <label style={{ color: 'var(--muted)', fontSize: 12, display: 'block', marginBottom: 4 }}>Forma</label>
                <select value={novoPag.forma} onChange={e => setNovoPag(p => ({ ...p, forma: e.target.value }))}
                  style={{ padding: '8px 12px', borderRadius: 8, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, outline: 'none' }}>
                  {FORMAS_PAGAMENTO.map(f => <option key={f} value={f}>{f}</option>)}
                </select>
              </div>
              <div>
                <label style={{ color: 'var(--muted)', fontSize: 12, display: 'block', marginBottom: 4 }}>Valor (R$)</label>
                <input type="number" step="0.01" min="0.01" value={novoPag.valor}
                  onChange={e => setNovoPag(p => ({ ...p, valor: e.target.value }))}
                  placeholder="0,00"
                  style={{ padding: '8px 12px', borderRadius: 8, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, outline: 'none', width: 140 }} />
              </div>
              <button onClick={handleAddPagamento} disabled={addingPag} className="font-display"
                style={{ padding: '9px 20px', background: addingPag ? 'var(--muted)' : 'var(--accent)', color: '#000', border: 'none', borderRadius: 8, fontWeight: 800, fontSize: 14, cursor: addingPag ? 'not-allowed' : 'pointer' }}>
                {addingPag ? 'Registrando...' : '+ Registrar'}
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
