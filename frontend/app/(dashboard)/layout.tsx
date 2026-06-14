'use client'
import { Sidebar } from '@/components/layout/Sidebar'
import { Topbar } from '@/components/layout/Topbar'
import { AlertBanner } from '@/components/layout/AlertBanner'
import { ToastContainer } from '@/components/ui/Toast'
import { useEstoqueAlerts } from '@/hooks/useEstoqueAlerts'
import { useAlertBanner } from '@/hooks/useAlertBanner'

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const { items, produtosCount, clientesDevedoresCount } = useEstoqueAlerts()
  const { dismissed, dismiss } = useAlertBanner()

  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg)' }}>
      <Sidebar clientesDevedores={clientesDevedoresCount} produtosAlerta={produtosCount} />
      <div style={{ marginLeft: 230, display: 'flex', flexDirection: 'column', minHeight: '100vh' }}>
        <Topbar />
        <main style={{ flex: 1, padding: '24px' }}>
          <AlertBanner items={items} dismissed={dismissed} onDismiss={dismiss} />
          {children}
        </main>
      </div>
      <ToastContainer />
    </div>
  )
}
