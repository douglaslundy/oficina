'use client'
import { usePathname } from 'next/navigation'
import Link from 'next/link'
import { useTheme } from 'next-themes'
import { useAuth } from '@/hooks/useAuth'

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
  '/auditoria':           'Auditoria',
  '/meus-dados':          'Meus Dados',
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
  const { user } = useAuth()
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
        {/* Theme toggle */}
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

        {/* Contextual action button */}
        {action && (
          <Link href={action.href} className="font-display"
            style={{
              padding: '8px 16px', background: 'var(--accent)', color: '#000',
              borderRadius: 8, textDecoration: 'none', fontWeight: 700, fontSize: 14,
            }}>
            {action.label}
          </Link>
        )}

        {/* User avatar → Meus Dados */}
        {user && (
          <Link
            href="/meus-dados"
            title={`${user.nome} — Meus dados`}
            style={{
              display: 'flex', alignItems: 'center', gap: 8,
              padding: '4px 10px 4px 4px',
              border: '1px solid var(--border)',
              borderRadius: 20,
              textDecoration: 'none',
              background: pathname === '/meus-dados' ? 'rgba(245,166,35,.08)' : 'transparent',
              borderColor: pathname === '/meus-dados' ? 'var(--accent)' : 'var(--border)',
              transition: 'border-color 0.15s, background 0.15s',
            }}
            onMouseEnter={e => {
              (e.currentTarget as HTMLAnchorElement).style.borderColor = 'var(--accent)'
              ;(e.currentTarget as HTMLAnchorElement).style.background = 'rgba(245,166,35,.08)'
            }}
            onMouseLeave={e => {
              if (pathname !== '/meus-dados') {
                ;(e.currentTarget as HTMLAnchorElement).style.borderColor = 'var(--border)'
                ;(e.currentTarget as HTMLAnchorElement).style.background = 'transparent'
              }
            }}
          >
            <div style={{
              width: 28, height: 28, borderRadius: '50%',
              background: 'var(--card)', display: 'flex', alignItems: 'center',
              justifyContent: 'center', fontSize: 12, fontWeight: 700,
              color: 'var(--accent)', border: '1px solid var(--border)',
              flexShrink: 0,
            }}>
              {user.nome.charAt(0).toUpperCase()}
            </div>
            <div style={{ lineHeight: 1.2 }}>
              <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text)', maxWidth: 120, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                {user.nome.split(' ')[0]}
              </div>
              <div style={{ fontSize: 10, color: 'var(--muted)' }}>Meus dados</div>
            </div>
          </Link>
        )}
      </div>
    </header>
  )
}
