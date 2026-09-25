/**
 * Nome do arquivo baixado de um documento fiscal. O backend já nomeia certo no
 * Content-Disposition (NFSe-15.pdf, NFe-17.xml, NFCe-3.pdf); o frontend NÃO deve
 * inventar outro nome (antes forçava "NF-<número>" pra tudo, sem distinguir
 * serviço de produto). Fallback pelo modelo quando o header não vem.
 */
const PREFIXO_POR_MODELO: Record<string, string> = {
  'NFS-e': 'NFSe',
  'NF-e': 'NFe',
  'NFC-e': 'NFCe',
}

export function nomeArquivoFiscal(
  res: Response,
  modelo: string | undefined,
  numero: number | string | null | undefined,
  extensao: 'pdf' | 'xml',
): string {
  const header = res.headers.get('Content-Disposition') ?? ''
  const doServidor = /filename="?([^";]+)"?/i.exec(header)?.[1]
  if (doServidor) return doServidor

  const prefixo = (modelo && PREFIXO_POR_MODELO[modelo]) || 'NF'
  return `${prefixo}-${numero ?? 'nota'}.${extensao}`
}
