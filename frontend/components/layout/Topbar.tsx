'use client'
import { usePathname } from 'next/navigation'
import Link from 'next/link'
import { useTheme } from 'next-themes'

const BREADCRUMBS: Record<string, string> = {
  '/':                    'Dashboard',
  '/clientes':            'Clientes',
  '/clientes/novo':       'Clientes / Novo',
  '/produtos':            'Produtos / Estoque',
  '/os':                  'Ordens de Serviço',
  '/os/nova':             'Ordens de Serviço / Nova OS',
  '/agendamentos':        'Agendamento',
  '/fiscal/emitir':       'Fiscal / Emitir NF',
  '/fiscal/historico':    'Fiscal / Histórico',
  '/usuarios':            'Usuários',
  '/empresa':             'Empresa',
  '/configuracoes':       'Configurações',
  '/relatorios':          'Relatórios',
}

const ACTION_BUTTONS: Record<string, { label: string; href: string }> = {
  '/clientes':         { label: '+ Novo Cliente', href: '/clientes/novo' },
  '/produtos':         { label: '+ Novo Produto', href: '/produtos/novo' },
  '/os':               { label: '+ Nova OS',      href: '/os/nova' },
  '/relatorios':       { label: 'Exportar XLSX',  href: '/relatorios?export=true' },
  '/usuarios':         { label: '+ Novo Usuário', href: '/usuarios/novo' },
  '/fiscal/historico': { label: 'Emitir NF',      href: '/fiscal/emitir' },
}

interface TopbarProps {
  onMenuClick?: () => void
  isMobile?: boolean
}

export function Topbar({ onMenuClick, isMobile = false }: TopbarProps) {
  const pathname = usePathname()
  const { theme, setTheme } = useTheme()
  const breadcrumb = BREADCRUMBS[pathname] ?? pathname.split('/').filter(Boolean).join(' / ')
  const action = ACTION_BUTTONS[pathname]

  return (
    <header style={{
      height: 60, background: 'var(--surface)',
      borderBottom: '1px solid var(--border)',
      display: 'flex', alignItems: 'center', justifyContent: 'space-between',
      padding: '0 24px', position: 'sticky', top: 0, zIndex: 50,
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
        {isMobile && (
          <button
            onClick={onMenuClick}
            style={{
              background: 'none', border: 'none', color: 'var(--muted)',
              cursor: 'pointer', fontSize: 20, padding: '4px 8px', lineHeight: 1,
            }}
          >
            ☰
          </button>
        )}
      <nav style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
        {breadcrumb.split(' / ').map((part, i, arr) => (
          <span key={i} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <span style={{
              color: i === arr.length - 1 ? 'var(--text)' : 'var(--muted)',
              fontSize: 14, fontWeight: i === arr.length - 1 ? 600 : 400,
            }}>
              {part}
            </span>
            {i < arr.length - 1 && <span style={{ color: 'var(--muted)', fontSize: 12 }}>/</span>}
          </span>
        ))}
      </nav>
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
        <button
          onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
          title={theme === 'dark' ? 'Modo claro' : 'Modo escuro'}
          style={{
            background: 'none', border: '1px solid var(--border)',
            color: 'var(--muted)', cursor: 'pointer', fontSize: 16,
            borderRadius: 6, padding: '4px 8px', lineHeight: 1,
          }}
        >
          {theme === 'dark' ? '☀️' : '🌙'}
        </button>
        {action && (
          <Link href={action.href} className="font-display"
            style={{
              padding: '8px 16px', background: 'var(--accent)', color: '#000',
              borderRadius: 8, textDecoration: 'none', fontWeight: 700, fontSize: 14,
            }}>
            {action.label}
          </Link>
        )}
      </div>
    </header>
  )
}
