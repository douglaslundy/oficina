import axios from 'axios'

const api = axios.create({
  baseURL: (process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000') + '/api',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  withCredentials: true,
})

api.interceptors.request.use((config) => {
  if (typeof window !== 'undefined') {
    // Use current origin so tenant subdomains (e.g. stuntmotos.dlsistemas.com.br)
    // call /api relative to themselves — avoids cross-origin CORS entirely
    config.baseURL = window.location.origin + '/api'
    const token = localStorage.getItem('auth_token')
    if (token) config.headers.Authorization = `Bearer ${token}`
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
      localStorage.removeItem('auth_token')
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
