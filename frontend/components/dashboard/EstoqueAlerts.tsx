import { StockBar } from '@/components/ui/StockBar'

interface Produto {
  id: string
  nome: string
  qty_atual: number
  qty_minima: number
}

interface EstoqueAlertsProps {
  produtos: Produto[]
}

export function EstoqueAlerts({ produtos }: EstoqueAlertsProps) {
  return (
    <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
      <h3 className="font-display" style={{ fontSize: 18, fontWeight: 800, color: 'var(--text)', marginBottom: 16 }}>
        Alertas de Estoque
      </h3>
      {produtos.length === 0 ? (
        <p style={{ color: 'var(--success)', fontSize: 14 }}>✓ Estoque normalizado</p>
      ) : (
        produtos.map(p => {
          const status = p.qty_atual <= 0
            ? 'SEM_ESTOQUE'
            : p.qty_atual < p.qty_minima * 0.4
              ? 'CRITICO'
              : 'BAIXO'
          return (
            <div key={p.id} style={{ marginBottom: 14 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 4 }}>
                <span style={{ color: 'var(--text)', fontSize: 13 }}>{p.nome}</span>
                <span className="font-mono" style={{ color: 'var(--muted)', fontSize: 12 }}>
                  {p.qty_atual}/{p.qty_minima}
                </span>
              </div>
              <StockBar qtyAtual={p.qty_atual} qtyMinima={p.qty_minima} status={status} />
            </div>
          )
        })
      )}
    </div>
  )
}
