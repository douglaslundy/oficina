'use client'
import { useState, useEffect } from 'react'
import { Sidebar } from '@/components/layout/Sidebar'
import { Topbar } from '@/components/layout/Topbar'
import { AlertBanner } from '@/components/layout/AlertBanner'
import { ToastContainer } from '@/components/ui/Toast'
import { useEstoqueAlerts } from '@/hooks/useEstoqueAlerts'
import { useAlertBanner } from '@/hooks/useAlertBanner'

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const { items, produtosCount, clientesDevedoresCount } = useEstoqueAlerts()
  const { dismissed, dismiss } = useAlertBanner()
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [isMobile, setIsMobile] = useState(false)

  useEffect(() => {
    const check = () => setIsMobile(window.innerWidth < 768)
    check()
    window.addEventListener('resize', check)
    return () => window.removeEventListener('resize', check)
  }, [])

  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg)' }}>
      {/* Overlay para fechar sidebar no mobile */}
      {isMobile && sidebarOpen && (
        <div
          onClick={() => setSidebarOpen(false)}
          style={{
            position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)',
            zIndex: 99, cursor: 'pointer',
          }}
        />
      )}

      <Sidebar
        clientesDevedores={clientesDevedoresCount}
        produtosAlerta={produtosCount}
        isMobile={isMobile}
        isOpen={sidebarOpen}
        onClose={() => setSidebarOpen(false)}
      />

      <div style={{
        marginLeft: isMobile ? 0 : 230,
        display: 'flex', flexDirection: 'column', minHeight: '100vh',
      }}>
        <Topbar onMenuClick={() => setSidebarOpen(o => !o)} isMobile={isMobile} />
        <main style={{ flex: 1, padding: isMobile ? '16px' : '24px' }}>
          <AlertBanner items={items} dismissed={dismissed} onDismiss={dismiss} />
          {children}
        </main>
      </div>
      <ToastContainer />
    </div>
  )
}
