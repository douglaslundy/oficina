'use client'
import { useState, useEffect, useCallback } from 'react'
import api from '@/lib/api'

export interface AlertItem {
  id: string
  nome: string
  qty_atual: number
  status: string
}

export interface EstoqueAlertsState {
  items: AlertItem[]
  produtosCount: number
  clientesDevedoresCount: number
  loading: boolean
  refresh: () => void
}

export function useEstoqueAlerts(): EstoqueAlertsState {
  const [items, setItems] = useState<AlertItem[]>([])
  const [produtosCount, setProdutosCount] = useState(0)
  const [clientesDevedoresCount, setClientesDevedoresCount] = useState(0)
  const [loading, setLoading] = useState(true)

  const refresh = useCallback(() => {
    setLoading(true)
    Promise.all([
      api.get('/produtos?status=CRITICO,SEM_ESTOQUE').catch(() => ({ data: { data: [] } })),
      api.get('/clientes?status=DEVEDOR&count=1').catch(() => ({ data: { total: 0 } })),
    ]).then(([prodRes, cliRes]) => {
      const prods: AlertItem[] = prodRes.data?.data ?? []
      setItems(prods)
      setProdutosCount(prods.length)
      setClientesDevedoresCount(cliRes.data?.total ?? 0)
    }).finally(() => setLoading(false))
  }, [])

  useEffect(() => { refresh() }, [refresh])

  return { items, produtosCount, clientesDevedoresCount, loading, refresh }
}
