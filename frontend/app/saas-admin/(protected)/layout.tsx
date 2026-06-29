'use client'
import { usePathname, useRouter } from 'next/navigation'

const NAV_ITEMS = [
  { label: 'Dashboard', href: '/saas-admin' },
  { label: 'Oficinas', href: '/saas-admin/oficinas' },
  { label: 'Planos', href: '/saas-admin/planos' },
  { label: 'Pacotes', href: '/saas-admin/pacotes' },
  { label: 'Solicitações', href: '/saas-admin/solicitacoes' },
  { label: 'Cobranças', href: '/saas-admin/cobrancas' },
  { label: 'Notificações', href: '/saas-admin/notificacoes' },
  { label: 'Configurações', href: '/saas-admin/configuracoes' },
  { label: 'Backup', href: '/saas-admin/backup' },
  { label: 'VPS', href: '/saas-admin/vps' },
] as const

export default function SaasAdminLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname()
  const router = useRouter()

  function handleLogout() {
    localStorage.removeItem('saas_token')
    document.cookie = 'saas_token=; path=/; max-age=0'
    router.push('/saas-admin/login')
  }

  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg)' }}>
      {/* Fixed topbar */}
      <header style={{
        position: 'fixed',
        top: 0,
        left: 0,
        right: 0,
        height: 56,
        background: 'var(--surface)',
        borderBottom: '1px solid var(--border)',
        display: 'flex',
        alignItems: 'center',
        padding: '0 24px',
        zIndex: 100,
        gap: 24,
        overflowX: 'auto',
      }}>
        {/* Logo + badge */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginRight: 16, flexShrink: 0 }}>
          <span className="font-display" style={{ fontSize: 18, fontWeight: 700, color: 'var(--text)' }}>
            🔧 MecânicaPro
          </span>
          <span style={{
            background: 'var(--accent)',
            color: '#000',
            fontSize: 11,
            fontWeight: 700,
            padding: '2px 8px',
            borderRadius: 999,
            letterSpacing: '0.02em',
          }}>
            SaaS Admin
          </span>
        </div>

        {/* Nav links */}
        <nav style={{ display: 'flex', alignItems: 'center', gap: 2, flex: 1, minWidth: 0 }}>
          {NAV_ITEMS.map((item) => {
            const isActive =
              item.href === '/saas-admin'
                ? pathname === '/saas-admin'
                : pathname.startsWith(item.href)
            return (
              <a
                key={item.href}
                href={item.href}
                style={{
                  padding: '6px 10px',
                  borderRadius: 6,
                  fontSize: 13,
                  fontWeight: isActive ? 600 : 400,
                  color: isActive ? 'var(--accent)' : 'var(--muted)',
                  textDecoration: 'none',
                  borderBottom: isActive ? '2px solid var(--accent)' : '2px solid transparent',
                  transition: 'color 0.15s, border-color 0.15s',
                  whiteSpace: 'nowrap',
                  flexShrink: 0,
                }}
              >
                {item.label}
              </a>
            )
          })}
        </nav>

        {/* Perfil + Logout */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexShrink: 0 }}>
          <a
            href="/saas-admin/perfil"
            style={{
              padding: '6px 12px',
              borderRadius: 6,
              fontSize: 13,
              fontWeight: pathname.startsWith('/saas-admin/perfil') ? 600 : 400,
              color: pathname.startsWith('/saas-admin/perfil') ? 'var(--accent)' : 'var(--muted)',
              textDecoration: 'none',
              borderBottom: pathname.startsWith('/saas-admin/perfil') ? '2px solid var(--accent)' : '2px solid transparent',
              transition: 'color 0.15s',
              whiteSpace: 'nowrap',
            }}
          >
            👤 Perfil
          </a>
          <button
            onClick={handleLogout}
            style={{
              background: 'none',
              border: 'none',
              color: 'var(--muted)',
              fontSize: 13,
              cursor: 'pointer',
              padding: '6px 12px',
              borderRadius: 6,
              transition: 'color 0.15s',
              whiteSpace: 'nowrap',
            }}
            onMouseEnter={(e) => { (e.currentTarget as HTMLButtonElement).style.color = 'var(--danger)' }}
            onMouseLeave={(e) => { (e.currentTarget as HTMLButtonElement).style.color = 'var(--muted)' }}
          >
            Sair
          </button>
        </div>
      </header>

      {/* Main content — offset for fixed topbar */}
      <main style={{ paddingTop: 56 }}>
        {children}
      </main>
    </div>
  )
}
