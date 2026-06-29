import axios from 'axios'

const saasApi = axios.create({
  baseURL: (process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000') + '/api',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  withCredentials: false,
})

saasApi.interceptors.request.use((config) => {
  if (typeof window !== 'undefined') {
    config.baseURL = window.location.origin + '/api'
    const token = localStorage.getItem('saas_token')
    if (token) config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

saasApi.interceptors.response.use(
  (res) => res,
  (error) => {
    if (error.response?.status === 401 && typeof window !== 'undefined') {
      localStorage.removeItem('saas_token')
      document.cookie = 'saas_token=; path=/; max-age=0'
      window.location.href = '/saas-admin/login'
    }
    return Promise.reject(error)
  }
)

export default saasApi
