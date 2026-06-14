'use client'
import { useState, useCallback } from 'react'

export function useAlertBanner() {
  const [dismissed, setDismissed] = useState(false)

  const dismiss = useCallback(() => setDismissed(true), [])
  const reset   = useCallback(() => setDismissed(false), [])

  return { dismissed, dismiss, reset }
}
