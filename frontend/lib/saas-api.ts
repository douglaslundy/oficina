import axios from 'axios'

const saasApi = axios.create({
  baseURL: (process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000') + '/api',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  // Falha de segurança grave corrigida em 2026-09-14: era um token Bearer
  // guardado em localStorage/document.cookie (ainda mais sensível aqui —
  // acesso a TODAS as oficinas da plataforma). Agora é sessão httpOnly
  // (Sanctum SPA), por isso `withCredentials` precisa ser true (era false).
  withCredentials: true,
})

let csrfPromise: Promise<void> | null = null
function ensureCsrfCookie(): Promise<void> {
  if (typeof document === 'undefined') return Promise.resolve()
  if (document.cookie.includes('XSRF-TOKEN=')) return Promise.resolve()
  if (!csrfPromise) {
    csrfPromise = axios
      .get(window.location.origin + '/sanctum/csrf-cookie', { withCredentials: true })
      .then(() => undefined)
      .catch(() => { csrfPromise = null })
  }
  return csrfPromise
}

saasApi.interceptors.request.use(async (config) => {
  if (typeof window !== 'undefined') {
    config.baseURL = window.location.origin + '/api'
    const method = (config.method || 'get').toUpperCase()
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      await ensureCsrfCookie()
    }
  }
  return config
})

saasApi.interceptors.response.use(
  (res) => res,
  (error) => {
    if (error.response?.status === 401 && typeof window !== 'undefined') {
      localStorage.removeItem('saas_user')
      window.location.href = '/saas-admin/login'
    }
    return Promise.reject(error)
  }
)

export default saasApi
