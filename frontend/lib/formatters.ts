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
