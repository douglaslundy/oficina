'use client'

import { useState, useEffect } from 'react'
import api from '@/lib/api'

interface LimiteItem {
  atual: number
  limite: number
  percent: number
}

interface NotasMesItem extends LimiteItem {
  preco_excedente: number
}

interface PlanLimites {
  plano: { id: string; nome: string } | null
  usuarios: LimiteItem | null
  os_mes: LimiteItem | null
  produtos: LimiteItem | null
  clientes: LimiteItem | null
  notas_mes: NotasMesItem | null
}

export function usePlanLimites() {
  const [data, setData] = useState<PlanLimites | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api.get<PlanLimites>('/plano/limites')
      .then(r => setData(r.data))
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])

  return { limites: data, loading }
}
