export function validarCPF(cpf: string): boolean {
  const digits = cpf.replace(/\D/g, '')
  if (digits.length !== 11 || /^(\d)\1+$/.test(digits)) return false
  for (let t = 9; t < 11; t++) {
    let sum = 0
    for (let i = 0; i < t; i++) sum += parseInt(digits[i]) * (t + 1 - i)
    const rem = (sum * 10) % 11
    if (parseInt(digits[t]) !== (rem >= 10 ? 0 : rem)) return false
  }
  return true
}

export function validarCNPJ(cnpj: string): boolean {
  const digits = cnpj.replace(/\D/g, '')
  if (digits.length !== 14 || /^(\d)\1+$/.test(digits)) return false
  const calc = (len: number): number => {
    let sum = 0, pos = len - 7
    for (let i = len; i >= 1; i--) {
      sum += parseInt(digits[len - i]) * pos--
      if (pos < 2) pos = 9
    }
    const rem = sum % 11
    return rem < 2 ? 0 : 11 - rem
  }
  return parseInt(digits[12]) === calc(12) && parseInt(digits[13]) === calc(13)
}

export function validarCPFouCNPJ(value: string): boolean {
  const d = value.replace(/\D/g, '')
  return d.length === 14 ? validarCNPJ(value) : validarCPF(value)
}
