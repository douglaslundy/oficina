'use client'
import { useState, type CSSProperties } from 'react'
import { toast } from '@/hooks/useToast'
import { nomeArquivoFiscal } from '@/lib/arquivoFiscal'
import { useImprimirCupomOs } from '@/components/ui/ImprimirCupomOs'
import api from '@/lib/api'

export interface NotaResumo { id: string; numero: number | null; modelo: string; status: string }

const btn = (bg: string, color: string): CSSProperties => ({
  padding: '4px 10px', background: bg, border: bg === 'transparent' ? '1px solid var(--border)' : 'none',
  color, borderRadius: 6, cursor: 'pointer', fontSize: 12, fontWeight: 700, whiteSpace: 'nowrap',
})

/** Botões "Cupom" e "Gerar/Baixar nota" de uma venda balcão (ou OS). */
export function AcoesVenda({ id, numero, status, notas = [], onChange }: {
  id: string; numero: number; status: string; notas?: NotaResumo[]; onChange?: () => void
}) {
  const { imprimir, modal } = useImprimirCupomOs()
  const [ocupado, setOcupado] = useState(false)

  async function gerar() {
    setOcupado(true)
    try {
      const r = await api.post<{ nfe_id: string | null; nfse_id: string | null; avisos: string[] }>(`/os/${id}/emitir-notas`, {})
      if (r.data.nfe_id || r.data.nfse_id) toast('Nota fiscal enfileirada. Acompanhe em Histórico NF.', 'success')
      else toast('Nenhuma nota gerada.', 'danger')
      ;(r.data.avisos ?? []).forEach(a => toast(a, 'info'))
      onChange?.()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast(msg ?? 'Erro ao gerar a nota fiscal.', 'danger')
    } finally { setOcupado(false) }
  }

  async function baixar() {
    setOcupado(true)
    try {
      const slug = localStorage.getItem('oficina_slug')
      for (const nota of notas) {
        const resp = await fetch(`${window.location.origin}/api/notas-fiscais/${nota.id}/pdf`,
          { credentials: 'include', headers: { 'X-Tenant': slug ?? '' } })
        if (!resp.ok) { toast(`Erro ao baixar a nota ${nota.modelo} nº ${nota.numero ?? '-'}.`, 'danger'); continue }
        const url = URL.createObjectURL(await resp.blob())
        const a = document.createElement('a')
        a.href = url
        a.download = nomeArquivoFiscal(resp, nota.modelo, nota.numero ?? nota.id, 'pdf')
        a.click()
        URL.revokeObjectURL(url)
      }
    } finally { setOcupado(false) }
  }

  return (
    <div style={{ display: 'flex', gap: 6 }} onClick={e => e.stopPropagation()}>
      {status !== 'CANCELADA' && (
        <button onClick={() => imprimir(id, numero)} title="Imprimir cupom" aria-label={`Imprimir venda #${numero}`} style={btn('transparent', 'var(--text)')}>🖨 Cupom</button>
      )}
      {status === 'CONCLUIDA' && (notas.length === 0
        ? <button onClick={gerar} disabled={ocupado} style={btn('var(--info)', '#fff')}>{ocupado ? '⟳ Gerando...' : '🧾 Gerar nota'}</button>
        : <button onClick={baixar} disabled={ocupado} style={btn('var(--info)', '#fff')}>{ocupado ? '⟳ Baixando...' : '📥 Baixar nota'}</button>)}
      {modal}
    </div>
  )
}
