'use client'
import { useState, useEffect } from 'react'
import api from '@/lib/api'

/**
 * Pedido explícito do usuário (2026-09-14): "receba um aviso dentro do
 * sistema" quando uma nota nova for emitida pro CNPJ da oficina. A contagem
 * vem de `/entradas-nf/pendentes-count`, que só lê o que o comando agendado
 * `nfe:verificar-notas-recebidas` já detectou — nunca chama o provedor
 * fiscal a partir do navegador.
 */
export function useNotasTerceiroPendentes() {
  const [pendentes, setPendentes] = useState(0)

  useEffect(() => {
    api.get<{ pendentes: number }>('/entradas-nf/pendentes-count')
      .then(r => setPendentes(r.data.pendentes ?? 0))
      // Best-effort: MECANICO não tem permissão (403) e simplesmente não vê o aviso.
      .catch(() => setPendentes(0))
  }, [])

  return { pendentes }
}
