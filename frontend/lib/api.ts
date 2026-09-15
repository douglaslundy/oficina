import axios from 'axios'

const api = axios.create({
  baseURL: (process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000') + '/api',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  withCredentials: true,
})

// Falha de segurança grave corrigida em 2026-09-14: autenticação era um
// token Bearer guardado em localStorage (legível por qualquer XSS). Agora é
// sessão httpOnly (Sanctum SPA) — o navegador manda o cookie sozinho
// (`withCredentials: true` acima), nenhum segredo passa pelo JavaScript.
// Requisições que mudam estado (POST/PUT/PATCH/DELETE) precisam do cookie
// XSRF-TOKEN presente ANTES de disparar — o axios lê e manda o header
// automaticamente (mesma origem, comportamento padrão), só falta garantir
// que o cookie já existe.
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

/**
 * Pra downloads de arquivo (PDF/XML/ZIP) que usam `fetch()` direto em vez do
 * `api`/axios acima (precisam do blob bruto da resposta) — GET não precisa
 * de nada além de `credentials: 'include'`; POST/DELETE/etc. também
 * precisam do header X-XSRF-TOKEN, que o axios manda sozinho mas o fetch
 * nativo não. Use com `credentials: 'include'` no fetch.
 */
export async function xsrfHeader(): Promise<Record<string, string>> {
  await ensureCsrfCookie()
  const match = typeof document !== 'undefined' ? document.cookie.match(/XSRF-TOKEN=([^;]+)/) : null
  return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {}
}

api.interceptors.request.use(async (config) => {
  if (typeof window !== 'undefined') {
    // Use current origin so tenant subdomains (e.g. stuntmotos.dlsistemas.com.br)
    // call /api relative to themselves — avoids cross-origin CORS entirely
    config.baseURL = window.location.origin + '/api'

    const method = (config.method || 'get').toUpperCase()
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      await ensureCsrfCookie()
    }

    const slug = localStorage.getItem('oficina_slug')
    if (slug) config.headers['X-Tenant'] = slug
  }
  return config
})

api.interceptors.response.use(
  (res) => res,
  (error) => {
    const isLoginEndpoint = error.config?.url?.includes('/auth/login')
    if (error.response?.status === 401 && !isLoginEndpoint && typeof window !== 'undefined') {
      localStorage.removeItem('auth_user')
      window.location.href = '/login'
    }

    const isSuspensa = error.response?.status === 403 && error.response?.data?.code === 'OFICINA_SUSPENSA'
    if (isSuspensa && typeof window !== 'undefined' && window.location.pathname !== '/bloqueado') {
      window.location.href = '/bloqueado'
    }

    return Promise.reject(error)
  }
)

export default api
