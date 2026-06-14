import React from 'react'

export interface Column<T> {
  key: string
  label: string
  render?: (row: T) => React.ReactNode
}

interface DataTableProps<T> {
  columns: Column<T>[]
  data: T[]
  loading?: boolean
  getRowClass?: (row: T) => string
  onRowClick?: (row: T) => void
  emptyMessage?: string
}

export function DataTable<T extends object>({
  columns, data, loading, getRowClass, onRowClick, emptyMessage = 'Nenhum registro encontrado.'
}: DataTableProps<T>) {
  const thStyle: React.CSSProperties = {
    padding: '10px 16px', textAlign: 'left', fontSize: 12,
    fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase',
    letterSpacing: '0.05em', borderBottom: '1px solid var(--border)',
    background: 'var(--card)', whiteSpace: 'nowrap',
  }
  const tdStyle: React.CSSProperties = {
    padding: '12px 16px', fontSize: 14, color: 'var(--text)',
    borderBottom: '1px solid var(--border)',
  }

  return (
    <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', overflow: 'hidden' }}>
      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead>
            <tr>
              {columns.map(col => <th key={col.key} style={thStyle}>{col.label}</th>)}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              Array.from({ length: 5 }).map((_, i) => (
                <tr key={i}>
                  {columns.map(col => (
                    <td key={col.key} style={tdStyle}>
                      <div style={{ height: 14, background: 'var(--border)', borderRadius: 4, width: '70%', animation: 'pulse 1.5s ease-in-out infinite' }} />
                    </td>
                  ))}
                </tr>
              ))
            ) : data.length === 0 ? (
              <tr>
                <td colSpan={columns.length} style={{ ...tdStyle, textAlign: 'center', color: 'var(--muted)', padding: 40 }}>
                  {emptyMessage}
                </td>
              </tr>
            ) : (
              data.map((row, i) => (
                <tr key={((row as Record<string, unknown>).id as string) ?? i}
                  className={getRowClass?.(row) ?? ''}
                  onClick={() => onRowClick?.(row)}
                  style={{ cursor: onRowClick ? 'pointer' : 'default' }}>
                  {columns.map(col => (
                    <td key={col.key} style={tdStyle}>
                      {col.render ? col.render(row) : String((row as Record<string, unknown>)[col.key] ?? '')}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
