export const formatarMoeda = (v: number) =>
  new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v)

export const formatarCPF = (v: string) =>
  v.replace(/\D/g, '').replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4')

export const formatarCNPJ = (v: string) =>
  v.replace(/\D/g, '').replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5')

export const formatarTelefone = (v: string) => {
  const d = v.replace(/\D/g, '')
  return d.length === 11
    ? d.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3')
    : d.replace(/(\d{2})(\d{4})(\d{4})/, '($1) $2-$3')
}

export const formatarPlaca = (v: string) => {
  const d = v.toUpperCase().replace(/[^A-Z0-9]/g, '')
  if (/^[A-Z]{3}\d[A-Z]\d{2}$/.test(d)) return d.replace(/([A-Z]{3})(\d[A-Z]\d{2})/, '$1-$2')
  if (/^[A-Z]{3}\d{4}$/.test(d))        return d.replace(/([A-Z]{3})(\d{4})/, '$1-$2')
  return d
}

export const formatarData = (iso?: string | null): string => {
  if (!iso) return '-'
  if (/^\d{2}\/\d{2}\/\d{4}/.test(iso)) return iso.split(' ')[0]
  try {
    const d = new Date(iso.replace(' ', 'T'))
    return isNaN(d.getTime()) ? '-' : d.toLocaleDateString('pt-BR')
  } catch {
    return '-'
  }
}

// Para campos de DATA PURA (sem hora significativa: `date` cast do Laravel,
// ou datas de gateways externos tipo Asaas). app.timezone do backend é UTC,
// então esses campos chegam como "2025-11-30T00:00:00.000000Z" — ler via
// toLocaleDateString (hora local do navegador) subtrai 3h e mostra o dia
// anterior no Brasil. Lendo os componentes UTC direto evita esse shift.
export const formatarDataUTC = (iso?: string | null): string => {
  if (!iso) return '-'
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (m) return `${m[3]}/${m[2]}/${m[1]}`
  try {
    const d = new Date(iso)
    if (isNaN(d.getTime())) return '-'
    const dia = String(d.getUTCDate()).padStart(2, '0')
    const mes = String(d.getUTCMonth() + 1).padStart(2, '0')
    return `${dia}/${mes}/${d.getUTCFullYear()}`
  } catch {
    return '-'
  }
}

export const formatarDataHora = (iso?: string | null): string => {
  if (!iso) return '-'
  try {
    const d = new Date(iso.replace(' ', 'T'))
    if (isNaN(d.getTime())) return '-'
    const data = d.toLocaleDateString('pt-BR')
    const hora = d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
    return `${data} ${hora}`
  } catch {
    return '-'
  }
}
