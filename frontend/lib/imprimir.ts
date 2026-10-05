/**
 * Busca um PDF da API (com a sessão e o tenant) e manda pra impressão.
 * Tenta imprimir direto por um iframe oculto; se o navegador não deixar,
 * abre o PDF numa aba nova (o usuário imprime de lá). Devolve o `X-Cupom-Tipo`
 * que o backend mandou (FISCAL | NAO_FISCAL), quando existir.
 */
export async function imprimirPdf(caminhoApi: string): Promise<string | null> {
  const slug = localStorage.getItem('oficina_slug')
  const res = await fetch(`${window.location.origin}/api${caminhoApi}`, {
    credentials: 'include',
    headers: { 'X-Tenant': slug ?? '' },
  })

  if (!res.ok) {
    let mensagem = 'Erro ao gerar o documento para impressão.'
    try {
      const corpo = await res.json() as { message?: string }
      if (corpo.message) mensagem = corpo.message
    } catch { /* corpo não era JSON */ }
    throw new Error(mensagem)
  }

  const url = URL.createObjectURL(await res.blob())
  const tipo = res.headers.get('X-Cupom-Tipo')

  try {
    const iframe = document.createElement('iframe')
    iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0'
    iframe.src = url
    iframe.onload = () => {
      try {
        iframe.contentWindow?.focus()
        iframe.contentWindow?.print()
      } catch {
        window.open(url, '_blank')
      }
    }
    document.body.appendChild(iframe)
    // Libera o iframe e o blob depois de dar tempo do diálogo de impressão abrir.
    setTimeout(() => { iframe.remove(); URL.revokeObjectURL(url) }, 60_000)
  } catch {
    window.open(url, '_blank')
  }

  return tipo
}
