'use client'

import { useState, useEffect, useCallback, useRef } from 'react'
import saasApi from '@/lib/saas-api'
import { formatarDataHora } from '@/lib/formatters'

type ToastType = 'success' | 'danger'

interface Backup {
  arquivo: string
  tamanho: number
  criado_em: string
}

function Toast({ msg, type, onClose }: { msg: string; type: ToastType; onClose: () => void }) {
  useEffect(() => {
    const t = setTimeout(onClose, type === 'danger' ? 12000 : 4000)
    return () => clearTimeout(t)
  }, [onClose, type])
  return (
    <div style={{
      position: 'fixed', bottom: 24, right: 24, zIndex: 9999,
      background: type === 'success' ? 'var(--success)' : 'var(--danger)',
      color: '#fff', padding: '12px 20px', borderRadius: 8,
      fontSize: 14, fontWeight: 600, boxShadow: '0 4px 20px rgba(0,0,0,.35)',
      display: 'flex', alignItems: 'flex-start', gap: 10,
      maxWidth: 480, whiteSpace: 'pre-wrap',
    }}>
      <span>{type === 'success' ? '✓' : '✕'}</span>
      <span style={{ fontFamily: type === 'danger' ? 'monospace' : 'inherit', fontSize: type === 'danger' ? 12 : 14 }}>{msg}</span>
    </div>
  )
}

function SectionCard({ title, subtitle, children }: { title: string; subtitle?: string; children: React.ReactNode }) {
  return (
    <div style={{
      background: 'var(--card)', border: '1px solid var(--border)',
      borderRadius: 10, overflow: 'hidden', marginBottom: 20,
    }}>
      <div style={{ padding: '16px 24px', borderBottom: '1px solid var(--border)' }}>
        <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--text)' }}>{title}</div>
        {subtitle && <div style={{ fontSize: 13, color: 'var(--muted)', marginTop: 2 }}>{subtitle}</div>}
      </div>
      <div style={{ padding: 24 }}>{children}</div>
    </div>
  )
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / 1024 / 1024).toFixed(2) + ' MB'
}

export default function BackupPage() {
  const [backups, setBackups] = useState<Backup[]>([])
  const [loadingList, setLoadingList] = useState(true)
  const [gerando, setGerando] = useState(false)
  const [importando, setImportando] = useState(false)
  const [deletando, setDeletando] = useState<string | null>(null)
  const [toast, setToast] = useState<{ msg: string; type: ToastType } | null>(null)
  const [confirmImport, setConfirmImport] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState<string | null>(null)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [ultimoGerado, setUltimoGerado] = useState<string | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)

  const showToast = useCallback((msg: string, type: ToastType) => setToast({ msg, type }), [])

  const carregarLista = useCallback(() => {
    setLoadingList(true)
    saasApi.get<{ data: Backup[] }>('/saas/backup/listar')
      .then(r => setBackups(r.data.data))
      .catch(() => showToast('Erro ao carregar lista de backups.', 'danger'))
      .finally(() => setLoadingList(false))
  }, [showToast])

  useEffect(() => { carregarLista() }, [carregarLista])

  async function gerarBackup() {
    setGerando(true)
    try {
      const r = await saasApi.post<{ arquivo: string; tamanho: number }>('/saas/backup/gerar')
      setUltimoGerado(r.data.arquivo)
      showToast(`Backup gerado: ${r.data.arquivo}`, 'success')
      carregarLista()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao gerar backup.', 'danger')
    } finally {
      setGerando(false)
    }
  }

  function baixarBackup(arquivo: string) {
    const token = localStorage.getItem('saas_token')
    const base = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000'
    const url = `${base}/api/saas/backup/${encodeURIComponent(arquivo)}/download`
    const a = document.createElement('a')
    a.href = url
    a.setAttribute('download', arquivo)
    // Add auth header via fetch + blob
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then(res => res.blob())
      .then(blob => {
        const blobUrl = URL.createObjectURL(blob)
        a.href = blobUrl
        document.body.appendChild(a)
        a.click()
        document.body.removeChild(a)
        URL.revokeObjectURL(blobUrl)
      })
      .catch(() => showToast('Erro ao baixar backup.', 'danger'))
  }

  async function apagarBackup(arquivo: string) {
    setDeletando(arquivo)
    try {
      await saasApi.delete(`/saas/backup/${encodeURIComponent(arquivo)}`)
      showToast('Backup apagado.', 'success')
      setConfirmDelete(null)
      carregarLista()
    } catch {
      showToast('Erro ao apagar backup.', 'danger')
    } finally {
      setDeletando(null)
    }
  }

  async function importarBackup() {
    if (!selectedFile) return
    const form = new FormData()
    form.append('arquivo', selectedFile)
    setImportando(true)
    try {
      await saasApi.post('/saas/backup/importar', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      showToast('Backup importado com sucesso!', 'success')
      setSelectedFile(null)
      setConfirmImport(false)
      if (fileRef.current) fileRef.current.value = ''
      carregarLista()
    } catch (e: unknown) {
      const data = (e as { response?: { data?: { message?: string; detalhe?: string } } })?.response?.data
      const msg = data?.message ?? 'Erro ao importar backup.'
      const detalhe = data?.detalhe ? '\n\n' + data.detalhe.split('\n').slice(-6).join('\n') : ''
      showToast(msg + detalhe, 'danger')
    } finally {
      setImportando(false)
    }
  }

  return (
    <div style={{ padding: '28px 32px', maxWidth: 800, margin: '0 auto', color: 'var(--text)' }}>
      {toast && <Toast msg={toast.msg} type={toast.type} onClose={() => setToast(null)} />}

      {/* Modal de confirmação de delete */}
      {confirmDelete && (
        <div style={{
          position: 'fixed', inset: 0, background: 'rgba(0,0,0,.6)',
          display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000,
        }}>
          <div style={{
            background: 'var(--card)', border: '1px solid var(--border)',
            borderRadius: 12, padding: 28, maxWidth: 420, width: '90%',
          }}>
            <div style={{ fontSize: 16, fontWeight: 700, marginBottom: 10 }}>Apagar Backup</div>
            <div style={{ fontSize: 14, color: 'var(--muted)', marginBottom: 20 }}>
              Tem certeza que deseja apagar <strong>{confirmDelete}</strong>? Esta ação não pode ser desfeita.
            </div>
            <div style={{ display: 'flex', gap: 10 }}>
              <button onClick={() => setConfirmDelete(null)} style={{
                flex: 1, padding: '9px 0', borderRadius: 7, border: '1px solid var(--border)',
                background: 'transparent', color: 'var(--text)', fontSize: 14, cursor: 'pointer',
              }}>Cancelar</button>
              <button onClick={() => apagarBackup(confirmDelete)} disabled={!!deletando} style={{
                flex: 1, padding: '9px 0', borderRadius: 7, border: 'none',
                background: 'var(--danger)', color: '#fff', fontSize: 14, fontWeight: 700,
                cursor: deletando ? 'not-allowed' : 'pointer',
              }}>
                {deletando ? 'Apagando...' : 'Sim, apagar'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal de confirmação de importação */}
      {confirmImport && selectedFile && (
        <div style={{
          position: 'fixed', inset: 0, background: 'rgba(0,0,0,.6)',
          display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000,
        }}>
          <div style={{
            background: 'var(--card)', border: '1px solid var(--border)',
            borderRadius: 12, padding: 28, maxWidth: 460, width: '90%',
          }}>
            <div style={{ fontSize: 16, fontWeight: 700, marginBottom: 10, color: 'var(--danger)' }}>
              ⚠️ Atenção — Operação Destrutiva
            </div>
            <div style={{
              background: 'rgba(229,57,53,.08)', border: '1px solid rgba(229,57,53,.3)',
              borderRadius: 7, padding: '12px 14px', fontSize: 13, color: 'var(--danger)', marginBottom: 16,
            }}>
              Esta operação irá sobrescrever dados existentes no banco de dados. Certifique-se de ter feito um backup antes de continuar.
            </div>
            <div style={{ fontSize: 14, color: 'var(--muted)', marginBottom: 20 }}>
              Arquivo: <strong style={{ color: 'var(--text)' }}>{selectedFile.name}</strong> ({formatBytes(selectedFile.size)})
            </div>
            <div style={{ display: 'flex', gap: 10 }}>
              <button onClick={() => setConfirmImport(false)} style={{
                flex: 1, padding: '9px 0', borderRadius: 7, border: '1px solid var(--border)',
                background: 'transparent', color: 'var(--text)', fontSize: 14, cursor: 'pointer',
              }}>Cancelar</button>
              <button onClick={importarBackup} disabled={importando} style={{
                flex: 1, padding: '9px 0', borderRadius: 7, border: 'none',
                background: 'var(--danger)', color: '#fff', fontSize: 14, fontWeight: 700,
                cursor: importando ? 'not-allowed' : 'pointer',
              }}>
                {importando ? '⟳ Importando...' : 'Confirmar Importação'}
              </button>
            </div>
          </div>
        </div>
      )}

      <div style={{ marginBottom: 24 }}>
        <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, margin: 0 }}>Backup do Banco</h1>
        <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>
          Gere, baixe e restaure backups completos do PostgreSQL
        </p>
      </div>

      {/* Gerar Backup */}
      <SectionCard title="Gerar Novo Backup" subtitle="Exporta todos os dados de todas as oficinas em formato SQL comprimido (.sql.gz)">
        <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
          <button onClick={gerarBackup} disabled={gerando} style={{
            padding: '10px 28px', borderRadius: 7, border: 'none',
            background: gerando ? 'var(--border)' : 'var(--accent)',
            color: gerando ? 'var(--muted)' : '#000',
            fontSize: 14, fontWeight: 700, cursor: gerando ? 'not-allowed' : 'pointer',
            fontFamily: "'Barlow Condensed', sans-serif",
          }}>
            {gerando ? '⟳ Gerando backup...' : '💾 Gerar Backup Agora'}
          </button>
          {ultimoGerado && !gerando && (
            <span style={{ fontSize: 13, color: 'var(--success)' }}>
              ✓ Último gerado: <strong>{ultimoGerado}</strong>
            </span>
          )}
        </div>
        {gerando && (
          <div style={{ marginTop: 14, fontSize: 13, color: 'var(--muted)' }}>
            ⏳ Executando pg_dump... isso pode levar alguns segundos.
          </div>
        )}
      </SectionCard>

      {/* Lista de Backups */}
      <SectionCard title="Backups Disponíveis" subtitle="Arquivos armazenados no servidor">
        {loadingList ? (
          <div style={{ color: 'var(--muted)', fontSize: 14 }}>Carregando...</div>
        ) : backups.length === 0 ? (
          <div style={{ color: 'var(--muted)', fontSize: 14, textAlign: 'center', padding: '20px 0' }}>
            Nenhum backup encontrado. Gere o primeiro acima.
          </div>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr style={{ borderBottom: '1px solid var(--border)' }}>
                {['Arquivo', 'Tamanho', 'Data', 'Ações'].map(h => (
                  <th key={h} style={{
                    textAlign: 'left', padding: '8px 12px', fontSize: 12,
                    fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase',
                  }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {backups.map(b => (
                <tr key={b.arquivo} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td style={{ padding: '10px 12px', fontSize: 13, fontFamily: 'monospace', color: 'var(--text)' }}>
                    {b.arquivo}
                  </td>
                  <td style={{ padding: '10px 12px', fontSize: 13, color: 'var(--muted)' }}>
                    {formatBytes(b.tamanho)}
                  </td>
                  <td style={{ padding: '10px 12px', fontSize: 13, color: 'var(--muted)' }}>
                    {formatarDataHora(b.criado_em)}
                  </td>
                  <td style={{ padding: '10px 12px' }}>
                    <div style={{ display: 'flex', gap: 8 }}>
                      <button onClick={() => baixarBackup(b.arquivo)} style={{
                        padding: '5px 14px', borderRadius: 6, border: '1px solid var(--accent)',
                        background: 'transparent', color: 'var(--accent)', fontSize: 12,
                        fontWeight: 600, cursor: 'pointer',
                      }}>⬇ Baixar</button>
                      <button onClick={() => setConfirmDelete(b.arquivo)} style={{
                        padding: '5px 14px', borderRadius: 6, border: '1px solid var(--danger)',
                        background: 'transparent', color: 'var(--danger)', fontSize: 12,
                        fontWeight: 600, cursor: 'pointer',
                      }}>🗑 Apagar</button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </SectionCard>

      {/* Importar Backup */}
      <SectionCard
        title="Importar Backup"
        subtitle="Restaura um backup .sql ou .sql.gz. ATENÇÃO: operação destrutiva, sobrescreve dados existentes."
      >
        <div style={{
          background: 'rgba(229,57,53,.06)', border: '1px solid rgba(229,57,53,.2)',
          borderRadius: 7, padding: '10px 14px', fontSize: 12, color: 'var(--danger)', marginBottom: 20,
        }}>
          ⚠️ A importação de um backup executa um restore no banco de dados. Dados existentes podem ser sobrescritos. Use com cautela.
        </div>
        <div style={{ display: 'flex', gap: 12, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div>
            <label style={{
              display: 'block', fontSize: 12, fontWeight: 600,
              color: 'var(--muted)', textTransform: 'uppercase',
              letterSpacing: '0.05em', marginBottom: 6,
            }}>Arquivo de Backup (.sql ou .sql.gz)</label>
            <input
              ref={fileRef}
              type="file"
              accept=".sql,.gz"
              onChange={e => setSelectedFile(e.target.files?.[0] ?? null)}
              style={{
                padding: '8px 12px', borderRadius: 7, border: '1px solid var(--border)',
                background: 'var(--card)', color: 'var(--text)', fontSize: 13, cursor: 'pointer',
              }}
            />
          </div>
          <button
            onClick={() => selectedFile && setConfirmImport(true)}
            disabled={!selectedFile || importando}
            style={{
              padding: '9px 20px', borderRadius: 7, border: 'none',
              background: !selectedFile ? 'var(--border)' : 'var(--danger)',
              color: !selectedFile ? 'var(--muted)' : '#fff',
              fontSize: 14, fontWeight: 700,
              cursor: !selectedFile || importando ? 'not-allowed' : 'pointer',
              fontFamily: "'Barlow Condensed', sans-serif",
            }}
          >
            📤 Importar Backup
          </button>
        </div>
        {selectedFile && (
          <div style={{ marginTop: 10, fontSize: 13, color: 'var(--muted)' }}>
            Selecionado: <strong style={{ color: 'var(--text)' }}>{selectedFile.name}</strong> ({formatBytes(selectedFile.size)})
          </div>
        )}
      </SectionCard>
    </div>
  )
}
