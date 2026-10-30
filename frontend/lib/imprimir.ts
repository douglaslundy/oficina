export type ModoImpressao = 'PDF' | 'DIALOGO' | 'DIRETO'

export interface ConfigImpressao {
  impressora_cupom: '80MM' | 'A4'
  tipo_cupom: 'FISCAL' | 'NAO_FISCAL'
  modo_impressao: ModoImpressao
  imprimir_automaticamente: boolean
}

/** Busca um arquivo da API (com a sessão e o tenant); erro de API vira Error com a mensagem do backend. */
async function buscar(caminhoApi: string): Promise<Response> {
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
  return res
}

/** Imprime um blob (PDF ou HTML) por um iframe oculto; sem permissão do navegador, abre em aba nova. */
function imprimirBlob(url: string) {
  try {
    const iframe = document.createElement('iframe')
    iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:80mm;height:100px;border:0;opacity:0;pointer-events:none'
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
}

/**
 * Busca um PDF da API e manda pra impressão. Devolve o `X-Cupom-Tipo` que o
 * backend mandou (FISCAL | NAO_FISCAL), quando existir.
 */
export async function imprimirPdf(caminhoApi: string): Promise<string | null> {
  const res = await buscar(caminhoApi)
  imprimirBlob(URL.createObjectURL(await res.blob()))
  return res.headers.get('X-Cupom-Tipo')
}

/**
 * Cupom da OS conforme Configurações > Impressão do cupom.
 * - PDF: abre o PDF numa aba, pra conferir antes de imprimir.
 * - DIALOGO / DIRETO: imprime. Na térmica 80 mm usa o cupom em HTML, que fixa o
 *   papel (`@page size`); o PDF no visualizador do navegador saía cortado.
 *   A confirmação do DIRETO é do chamador (modal). O diálogo do navegador só
 *   some se o Chrome estiver com `--kiosk-printing`.
 */
export async function imprimirCupomOs(osId: string, cfg: ConfigImpressao): Promise<void> {
  const caminho = `/os/${osId}/cupom`

  if (cfg.modo_impressao === 'PDF') {
    const aba = window.open('', '_blank')
    if (!aba) throw new Error('O navegador bloqueou a nova aba. Permita pop-ups para este site.')
    try {
      aba.location.href = URL.createObjectURL(await (await buscar(caminho)).blob())
    } catch (e) {
      aba.close()
      throw e
    }
    return
  }

  if (cfg.impressora_cupom === 'A4') {
    await imprimirPdf(caminho)
    return
  }

  const res = await buscar(`${caminho}?formato=html`)
  imprimirBlob(URL.createObjectURL(new Blob([await res.text()], { type: 'text/html;charset=utf-8' })))
}
