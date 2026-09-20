'use client'
import { useState } from 'react'
import { toast } from '@/hooks/useToast'

type FormatoExportacao = 'pdf' | 'xml' | 'json' | 'xlsx'

const FORMATOS: Array<{ id: FormatoExportacao; icone: string; rotulo: string; descricao: string }> = [
  { id: 'pdf',  icone: '📄', rotulo: 'PDF',  descricao: 'Relatório para imprimir ou enviar' },
  { id: 'xlsx', icone: '📊', rotulo: 'XLSX', descricao: 'Planilha do Excel' },
  { id: 'json', icone: '{ }', rotulo: 'JSON', descricao: 'Dados para integração' },
  { id: 'xml',  icone: '</>', rotulo: 'XML',  descricao: 'Dados em XML' },
]

// Botão "Exportar" + janela de escolha do formato. Baixa todos os produtos
// ativos com os dados fiscais (os sem nenhum campo fiscal vão por último —
// ordem definida no backend, GET /produtos/exportar-fiscal). Mesmo mecanismo
// de download autenticado do histórico de NF.
export function ExportarProdutosFiscais() {
  const [aberto, setAberto] = useState(false)
  const [exportando, setExportando] = useState<FormatoExportacao | null>(null)

  async function exportar(formato: FormatoExportacao) {
    if (exportando) return
    setExportando(formato)
    try {
      const res = await fetch(`${window.location.origin}/api/produtos/exportar-fiscal?formato=${formato}`, {
        credentials: 'include',
        headers: { 'X-Tenant': localStorage.getItem('oficina_slug') ?? '' },
      })
      if (!res.ok) throw new Error()
      const blob = await res.blob()
      const nome = res.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1]
        ?? `produtos-dados-fiscais.${formato}`
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = nome
      a.click()
      URL.revokeObjectURL(url)
      toast(`Exportação em ${formato.toUpperCase()} concluída.`, 'success')
      setAberto(false)
    } catch {
      toast('Erro ao exportar os produtos. Tente novamente.', 'danger')
    } finally {
      setExportando(null)
    }
  }

  return (
    <>
      <button
        onClick={() => setAberto(true)}
        title="Exporta todos os produtos ativos com os dados fiscais"
        style={{
          padding: '8px 14px', fontSize: 13, color: 'var(--text)', background: 'none',
          border: '1px solid var(--border)', borderRadius: 6, cursor: 'pointer', whiteSpace: 'nowrap',
        }}
      >
        ⬇ Exportar
      </button>

      {aberto && (
        <div
          onClick={() => { if (!exportando) setAberto(false) }}
          style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.6)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center' }}
        >
          <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="exportar-titulo"
            onClick={(e) => e.stopPropagation()}
            style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, padding: 32, width: 460, maxWidth: '90vw' }}
          >
            <h3 id="exportar-titulo" className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', marginBottom: 8 }}>
              Exportar produtos e dados fiscais
            </h3>
            <p style={{ color: 'var(--muted)', fontSize: 14, marginBottom: 20 }}>
              Em qual formato você quer exportar? Serão incluídos todos os produtos ativos, e os que não
              têm nenhum campo fiscal preenchido aparecem por último.
            </p>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 20 }}>
              {FORMATOS.map((f) => (
                <button
                  key={f.id}
                  onClick={() => exportar(f.id)}
                  disabled={exportando !== null}
                  style={{
                    textAlign: 'left', padding: '12px 14px', borderRadius: 8, background: 'var(--bg)',
                    border: `1px solid ${exportando === f.id ? 'var(--accent)' : 'var(--border)'}`,
                    color: 'var(--text)', cursor: exportando ? 'not-allowed' : 'pointer',
                    opacity: exportando && exportando !== f.id ? 0.5 : 1,
                  }}
                >
                  <span className="font-mono" style={{ fontSize: 16, marginRight: 8 }}>{f.icone}</span>
                  <span style={{ fontWeight: 700, fontSize: 15 }}>{exportando === f.id ? 'Gerando...' : f.rotulo}</span>
                  <span style={{ display: 'block', color: 'var(--muted)', fontSize: 12, marginTop: 4 }}>{f.descricao}</span>
                </button>
              ))}
            </div>
            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
              <button
                onClick={() => setAberto(false)}
                disabled={exportando !== null}
                style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 8, padding: '8px 20px', cursor: exportando ? 'not-allowed' : 'pointer', fontSize: 14 }}
              >
                Cancelar
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  )
}
