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
}

export function NotificacaoModal() {
  const [atual, setAtual] = useState<Notificacao | null>(null)

  useEffect(() => {
    api.get<{ data: Notificacao[] }>('/notificacoes/ativas')
      .then(r => {
        const lista = r.data.data ?? []
        if (lista.length > 0) setAtual(lista[0])
      })
      .catch(() => { /* silencioso */ })
  }, [])

  function fechar() {
    if (atual) {
      api.post(`/notificacoes/${atual.id}/visualizar`).catch(() => { /* silencioso */ })
    }
    setAtual(null)
  }

  if (!atual) return null

  return <NotificacaoCard notificacao={atual} onFechar={fechar} />
}
