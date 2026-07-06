'use client'
import { useEffect, useState } from 'react'
import api from '@/lib/api'
import { NotificacaoCard } from '@/components/NotificacaoCard'

interface Notificacao {
  id: string
  titulo: string
  subtitulo: string | null
  texto: string
  imagem: string | null
  vezes_dia: number
  intervalo_minutos: number
}

interface Registro { day: string; count: number; last: number }

function hoje() {
  return new Date().toISOString().slice(0, 10)
}

function ler(id: string): Registro {
  try {
    const raw = localStorage.getItem(`mp_notif_${id}`)
    if (raw) return JSON.parse(raw) as Registro
  } catch { /* ignore */ }
  return { day: '', count: 0, last: 0 }
}

function elegivel(n: Notificacao): boolean {
  const r = ler(n.id)
  const countHoje = r.day === hoje() ? r.count : 0
  if (countHoje >= n.vezes_dia) return false
  if (Date.now() - r.last < n.intervalo_minutos * 60_000) return false
  return true
}

export function NotificacaoModal() {
  const [atual, setAtual] = useState<Notificacao | null>(null)

  useEffect(() => {
    api.get<{ data: Notificacao[] }>('/notificacoes/ativas')
      .then(r => {
        const elegiveis = (r.data.data ?? []).filter(elegivel)
        if (elegiveis.length > 0) setAtual(elegiveis[0])
      })
      .catch(() => { /* silencioso */ })
  }, [])

  function fechar() {
    if (atual) {
      const r = ler(atual.id)
      const count = r.day === hoje() ? r.count + 1 : 1
      try {
        localStorage.setItem(`mp_notif_${atual.id}`, JSON.stringify({ day: hoje(), count, last: Date.now() }))
      } catch { /* ignore */ }
    }
    setAtual(null)
  }

  if (!atual) return null

  return <NotificacaoCard notificacao={atual} onFechar={fechar} />
}
