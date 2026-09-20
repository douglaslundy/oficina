import { NextResponse } from 'next/server'
import type { NextRequest } from 'next/server'
import { papelPermitido, ROLES_CONHECIDOS } from './lib/roleRules'

const PUBLIC_PATHS = ['/login', '/forgot-password', '/reset-password', '/orcamento']

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl
  const host = request.headers.get('host') ?? ''

  // Falha de segurança grave corrigida em 2026-09-14: a autenticação real
  // agora é uma sessão httpOnly (Sanctum SPA) — este proxy nunca teve, e
  // continua sem ter, acesso ao cookie de sessão de verdade (é httpOnly de
  // propósito). `oficina_logado`/`saas_logado` são cookies de PRESENÇA, sem
  // nenhum valor de credencial (não autenticam nada sozinhos) — servem só
  // pra decidir redirecionar sem bater no backend a cada navegação. A
  // autorização de verdade continua 100% no servidor, em toda requisição.

  // --- saas.dlsistemas.com.br → redireciona tudo para /saas-admin ---
  if (host.startsWith('saas.')) {
    if (!pathname.startsWith('/saas-admin')) {
      const saasLogado = request.cookies.get('saas_logado')?.value
      const dest = saasLogado ? '/saas-admin' : '/saas-admin/login'
      return NextResponse.redirect(new URL(dest, request.url))
    }
  }

  // --- SaaS Admin routes ---
  if (pathname.startsWith('/saas-admin')) {
    const SAAS_PUBLIC = ['/saas-admin/login', '/saas-admin/forgot-password', '/saas-admin/reset-password']
    if (SAAS_PUBLIC.some(p => pathname.startsWith(p))) {
      return NextResponse.next()
    }
    const saasLogado = request.cookies.get('saas_logado')?.value
    if (!saasLogado) {
      return NextResponse.redirect(new URL('/saas-admin/login', request.url))
    }
    return NextResponse.next()
  }

  // --- Regular tenant routes ---
  const isPublic = PUBLIC_PATHS.some(p => pathname.startsWith(p))

  const logado = request.cookies.get('oficina_logado')?.value

  if (!logado && !isPublic) {
    return NextResponse.redirect(new URL('/login', request.url))
  }

  if (logado && pathname === '/login') {
    return NextResponse.redirect(new URL('/', request.url))
  }

  // Bloqueio por role — só pras telas cuja LEITURA já é role-restrita no
  // backend (ver ROLE_RULES em lib/roleRules.ts). Defesa de UX/camada
  // extra: a autorização de verdade continua 100% no servidor (middleware
  // `role:` em toda rota da API); mesmo que este cookie fosse forjado, o
  // backend recusaria qualquer chamada real.
  if (logado) {
    const role = request.cookies.get('oficina_role')?.value
    // Só age sobre um role reconhecido: um valor ilegível (ex: cookie antigo
    // criptografado pelo Laravel, de antes do fix de 2026-09-20) não pode
    // bloquear ninguém — o backend continua autorizando de verdade.
    if (role && ROLES_CONHECIDOS.includes(role) && !papelPermitido(pathname, role)) {
      return NextResponse.redirect(new URL('/?acesso=negado', request.url))
    }
  }

  return NextResponse.next()
}

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico|api).*)'],
}
