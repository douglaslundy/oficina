import type { Metadata } from 'next'
import './globals.css'

export const metadata: Metadata = {
  title: 'MecânicaPro',
  description: 'Sistema de Gestão para Oficinas Mecânicas',
}

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="pt-BR">
      <body>{children}</body>
    </html>
  )
}
