'use client'
import { useState, useEffect, Suspense } from 'react'
import { useRouter, useSearchParams } from 'next/navigation'
import { Sidebar } from '@/components/layout/Sidebar'
import { Topbar } from '@/components/layout/Topbar'
import { AlertBanner } from '@/components/layout/AlertBanner'
import { NotaTerceiroBanner } from '@/components/layout/NotaTerceiroBanner'
import { ToastContainer } from '@/components/ui/Toast'
import { NotificacaoModal } from '@/components/NotificacaoModal'
import { AssinaturaAlertaModal } from '@/components/AssinaturaAlertaModal'
import { useEstoqueAlerts } from '@/hooks/useEstoqueAlerts'
import { useAlertBanner } from '@/hooks/useAlertBanner'
import { useNotasTerceiroPendentes } from '@/hooks/useNotasTerceiroPendentes'
import { toast } from '@/hooks/useToast'

function AcessoNegadoToast() {
  // Feedback do redirect de bloqueio por role em proxy.ts (?acesso=negado)
  const router = useRouter()
  const searchParams = useSearchParams()

  useEffect(() => {
    if (searchParams.get('acesso') === 'negado') {
      toast('Você não tem permissão para acessar essa página.', 'danger')
      router.replace('/')
    }
  }, [searchParams, router])

  return null
}

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const { items, produtosCount, clientesDevedoresCount } = useEstoqueAlerts()
  const { dismissed, dismiss } = useAlertBanner()
  const { dismissed: notasTerceiroDismissed, dismiss: dismissNotasTerceiro } = useAlertBanner()
  const { pendentes: notasTerceiroPendentes } = useNotasTerceiroPendentes()
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
          <NotaTerceiroBanner pendentes={notasTerceiroPendentes} dismissed={notasTerceiroDismissed} onDismiss={dismissNotasTerceiro} />
          {children}
        </main>
      </div>
      <ToastContainer />
      <NotificacaoModal />
      <AssinaturaAlertaModal />
      <Suspense fallback={null}>
        <AcessoNegadoToast />
      </Suspense>
    </div>
  )
}
