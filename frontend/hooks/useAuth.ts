'use client'
import { useState } from 'react'
import { useRouter } from 'next/navigation'
import api from '@/lib/api'

export interface AuthUser {
  id: string
  nome: string
  email: string
  role: 'ADMIN' | 'MECANICO' | 'ATENDENTE' | 'FINANCEIRO'
}

export function useAuth() {
  const router = useRouter()
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  function getUser(): AuthUser | null {
    if (typeof window === 'undefined') return null
    const raw = localStorage.getItem('auth_user')
    return raw ? JSON.parse(raw) : null
  }

  async function login(email: string, senha: string, lembrar: boolean, oficina_slug: string) {
    setLoading(true)
    setError(null)
    localStorage.setItem('oficina_slug', oficina_slug)
    try {
      const { data } = await api.post('/auth/login', { email, senha })
      localStorage.setItem('auth_token', data.token)
      localStorage.setItem('auth_user', JSON.stringify(data.user))
      document.cookie = `auth_token=${data.token}; path=/; SameSite=Lax`
      if (lembrar) localStorage.setItem('remember_email', email)
      router.push('/')
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      setError(e.response?.data?.message ?? 'Erro ao fazer login.')
      localStorage.removeItem('oficina_slug')
    } finally {
      setLoading(false)
    }
  }

  async function logout() {
    try { await api.post('/auth/logout') } catch { /* ignore */ }
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth_user')
    localStorage.removeItem('oficina_slug')
    document.cookie = 'auth_token=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT'
    router.push('/login')
  }

  return { login, logout, getUser, loading, error }
}
