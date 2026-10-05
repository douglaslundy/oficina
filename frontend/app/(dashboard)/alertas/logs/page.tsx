'use client'
import { useEffect } from 'react'
import { useRouter } from 'next/navigation'

// O histórico de alertas virou a página "Mensagens" (todas as mensagens, WhatsApp e e-mail).
export default function AlertaLogsRedirect() {
  const router = useRouter()
  useEffect(() => { router.replace('/mensagens') }, [router])
  return <p style={{ color: 'var(--muted)' }}>Redirecionando para Mensagens...</p>
}
