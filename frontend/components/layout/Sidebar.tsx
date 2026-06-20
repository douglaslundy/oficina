'use client'
import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { useAuth, AuthUser } from '@/hooks/useAuth'
import { useState, useEffect } from 'react'

interface NavItem {
  href: string
  label: string
  icon: string
  badge?: number
}

const NAV_ITEMS: NavItem[] = [
  { href: '/',                 label: 'Dashboard',         icon: '📊' },
  { href: '/clientes',         label: 'Clientes',          icon: '👥' },
  { href: '/produtos',         label: 'Produtos',          icon: '📦' },
  { href: '/servicos',         label: 'Serviços',          icon: '🛠️' },
  { href: '/os',               label: 'Ordens de Serviço', icon: '🔧' },
  { href: '/agendamentos',     label: 'Agendamento',       icon: '📅' },
  { href: '/fiscal/emitir',    label: 'Emitir NF',         icon: '🧾' },
  { href: '/fiscal/historico', label: 'Histórico NF',      icon: '📋' },
  { href: '/relatorios',       label: 'Relatórios',        icon: '📈' },
  { href: '/usuarios',         label: 'Usuários',          icon: '👤' },
  { href: '/empresa',          label: 'Empresa',           icon: '🏢' },
  { href: '/auditoria',        label: 'Auditoria',         icon: '🔍' },
  { href: '/configuracoes',    label: 'Configurações',     icon: '⚙️' },
]

interface SidebarProps {
  clientesDevedores?: number
  produtosAlerta?: number
  isMobile?: boolean
  isOpen?: boolean
  onClose?: () => void
}

export function Sidebar({ clientesDevedores = 0, produtosAlerta = 0, isMobile = false, isOpen = false, onClose }: SidebarProps) {
  const pathname = usePathname()
  const { logout, getUser } = useAuth()
  const [user, setUser] = useState<AuthUser | null>(null)

  useEffect(() => {
    setUser(getUser())
  }, [])

  const itemsWithBadges = NAV_ITEMS.map(item => {
    if (item.href === '/clientes' && clientesDevedores > 0)
      return { ...item, badge: clientesDevedores }
    if (item.href === '/produtos' && produtosAlerta > 0)
      return { ...item, badge: produtosAlerta }
    return item
  })

  return (
    <aside style={{
      width: 230, height: '100vh', position: 'fixed', left: 0, top: 0,
      background: 'var(--surface)', borderRight: '1px solid var(--border)',
      display: 'flex', flexDirection: 'column', zIndex: 100,
      transform: isMobile ? (isOpen ? 'translateX(0)' : 'translateX(-100%)') : 'translateX(0)',
      transition: 'transform 0.25s ease',
    }}>
      {/* Logo */}
      <div style={{ padding: '16px 20px 12px', borderBottom: '1px solid var(--border)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <div style={{
            width: 36, height: 36, borderRadius: 8, background: 'var(--accent)',
            display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 18,
          }}>🔧</div>
          <span className="font-display" style={{ fontSize: 18, fontWeight: 800, color: 'var(--text)' }}>MecânicaPro</span>
        </div>
      </div>

      {/* Nav */}
      <nav style={{ flex: 1, padding: '6px 0', overflowY: 'auto' }}>
        {itemsWithBadges.map(item => {
          const active = pathname === item.href || (item.href !== '/' && pathname.startsWith(item.href))
          return (
            <Link key={item.href} href={item.href} onClick={isMobile ? onClose : undefined}
              style={{
                display: 'flex', alignItems: 'center', gap: 9,
                padding: '7px 16px', textDecoration: 'none',
                color: active ? 'var(--text)' : 'var(--muted)',
                background: active ? 'rgba(245,166,35,0.08)' : 'transparent',
                borderLeft: `2px solid ${active ? 'var(--accent)' : 'transparent'}`,
                fontSize: 13, fontWeight: active ? 600 : 400,
                transition: 'all 0.15s',
              }}>
              <span style={{ fontSize: 15 }}>{item.icon}</span>
              <span style={{ flex: 1 }}>{item.label}</span>
              {item.badge && (
                <span style={{
                  background: 'var(--danger)', color: '#fff',
                  fontSize: 11, fontWeight: 700, borderRadius: 999,
                  padding: '1px 6px', minWidth: 18, textAlign: 'center',
                }}>
                  {item.badge > 99 ? '99+' : item.badge}
                </span>
              )}
            </Link>
          )
        })}
      </nav>

      {/* User pill */}
      {user && (
        <div style={{ padding: '10px 16px', borderBottom: '1px solid var(--border)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
            <div style={{
              width: 32, height: 32, borderRadius: '50%',
              background: 'var(--card)', display: 'flex', alignItems: 'center',
              justifyContent: 'center', fontSize: 14, border: '1px solid var(--border)',
            }}>
              {user.nome.charAt(0).toUpperCase()}
            </div>
            <div>
              <p style={{ color: 'var(--text)', fontSize: 13, fontWeight: 600, margin: 0 }}>{user.nome}</p>
              <p style={{ color: 'var(--muted)', fontSize: 11, margin: 0 }}>{user.role}</p>
            </div>
          </div>
          <button onClick={logout}
            style={{
              width: '100%', padding: 6, borderRadius: 6,
              background: 'transparent', border: '1px solid var(--border)',
              color: 'var(--muted)', fontSize: 13, cursor: 'pointer',
            }}>
            Sair
          </button>
        </div>
      )}
    </aside>
  )
}
