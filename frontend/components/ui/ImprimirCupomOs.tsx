'use client'
import { useCallback, useState } from 'react'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'
import { imprimirCupomOs, type ConfigImpressao } from '@/lib/imprimir'

interface Pendente { osId: string; numero?: number; cfg: ConfigImpressao }

/**
 * Impressão do cupom de uma OS conforme a configuração (PDF, diálogo ou direto).
 * No modo DIRETO abre um modal de confirmação: fecha ao imprimir e mostra o erro dentro dele se falhar.
 * Renderize `modal` uma vez na página e chame `imprimir(osId, numero)`.
 */
export function useImprimirCupomOs() {
  const [pendente, setPendente] = useState<Pendente | null>(null)
  const [imprimindo, setImprimindo] = useState(false)
  const [erro, setErro] = useState('')

  const imprimir = useCallback(async (osId: string, numero?: number) => {
    try {
      const cfg = (await api.get<ConfigImpressao>('/impressao/config')).data
      if (cfg.modo_impressao === 'DIRETO') {
        setErro('')
        setPendente({ osId, numero, cfg })
        return
      }
      await imprimirCupomOs(osId, cfg)
    } catch (e: unknown) {
      toast(e instanceof Error ? e.message : 'Erro ao imprimir o cupom.', 'danger')
    }
  }, [])

  async function confirmar() {
    if (!pendente) return
    setImprimindo(true)
    setErro('')
    try {
      await imprimirCupomOs(pendente.osId, pendente.cfg)
      setPendente(null)
    } catch (e: unknown) {
      setErro(e instanceof Error ? e.message : 'Erro ao imprimir o cupom.')
    } finally { setImprimindo(false) }
  }

  const modal = pendente && (
    <div role="dialog" aria-modal="true" aria-labelledby="imprimir-cupom-titulo"
      style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.6)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000 }}>
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, padding: 24, width: 400, maxWidth: '90vw' }}>
        <h3 id="imprimir-cupom-titulo" className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', marginBottom: 8 }}>Imprimir cupom</h3>
        <p style={{ color: 'var(--muted)', fontSize: 14, marginBottom: 16 }}>
          Deseja realmente imprimir o cupom{pendente.numero ? ` da OS #${pendente.numero}` : ''}?
        </p>
        {erro && (
          <p role="alert" style={{ color: 'var(--danger)', background: 'rgba(229,57,53,.08)', border: '1px solid var(--danger)', borderRadius: 8, padding: '8px 12px', fontSize: 13, marginBottom: 16 }}>{erro}</p>
        )}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10 }}>
          <button onClick={() => setPendente(null)} disabled={imprimindo}
            style={{ padding: '8px 18px', background: 'transparent', border: '1px solid var(--border)', color: 'var(--text)', borderRadius: 8, cursor: 'pointer', fontSize: 14 }}>
            Cancelar
          </button>
          <button onClick={confirmar} disabled={imprimindo} className="font-display"
            style={{ padding: '8px 22px', background: imprimindo ? 'var(--muted)' : 'var(--accent)', color: '#000', border: 'none', borderRadius: 8, fontWeight: 800, fontSize: 15, cursor: imprimindo ? 'not-allowed' : 'pointer' }}>
            {imprimindo ? 'Imprimindo...' : erro ? 'Tentar novamente' : 'Imprimir'}
          </button>
        </div>
      </div>
    </div>
  )

  return { imprimir, modal }
}
