'use client'
import { useState, useCallback } from 'react'

export type ToastType = 'success' | 'danger' | 'info'

export interface ToastItem {
  id: string
  message: string
  type: ToastType
}

// Module-level ref so toast() can be called from anywhere
let _addToast: ((message: string, type: ToastType) => void) | null = null

export function useToast() {
  const [toasts, setToasts] = useState<ToastItem[]>([])

  const addToast = useCallback((message: string, type: ToastType = 'info') => {
    const id = Math.random().toString(36).slice(2)
    setToasts(prev => [...prev, { id, message, type }])
    setTimeout(() => setToasts(prev => prev.filter(t => t.id !== id)), 3500)
  }, [])

  _addToast = addToast

  const removeToast = (id: string) => setToasts(prev => prev.filter(t => t.id !== id))

  return { toasts, addToast, removeToast }
}

export function toast(message: string, type: ToastType = 'info') {
  _addToast?.(message, type)
}
