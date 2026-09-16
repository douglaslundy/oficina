import { NextResponse } from 'next/server'
import type { NextRequest } from 'next/server'

const PUBLIC_PATHS = ['/login', '/forgot-password', '/reset-password', '/orcamento']

// Telas cujas rotas de LEITURA no backend já são restritas por role (não é
// só a mutação) — ver routes/api.php. Nunca duplicar telas cuja leitura é
// aberta a todos os roles (ex: clientes, produtos, OS): lá a proteção real
// já é 100% no backend, via middleware `role:` nas rotas de escrita, e
// bloquear a tela inteira aqui seria mais restritivo que o próprio backend.
// Ordem importa: prefixos mais específicos primeiro (ex: categorias-fiscais
// antes de configuracoes, que tem uma role diferente).
const ROLE_RULES: { prefix: string; roles: string[] }[] = [
  { prefix: '/configuracoes/categorias-fiscais', roles: ['ADMIN', 'ATENDENTE'] },
  { prefix: '/configuracoes', roles: ['ADMIN'] },
  { prefix: '/empresa', roles: ['ADMIN'] },
  { prefix: '/usuarios', roles: ['ADMIN'] },
  { prefix: '/auditoria', roles: ['ADMIN'] },
  { prefix: '/fiscal', roles: ['ADMIN', 'FINANCEIRO'] },
  { prefix: '/relatorios', roles: ['ADMIN', 'FINANCEIRO'] },
  { prefix: '/minhas-faturas', roles: ['ADMIN', 'FINANCEIRO'] },
  { prefix: '/alertas', roles: ['ADMIN', 'ATENDENTE'] },
]

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
  // backend (ver ROLE_RULES acima). Defesa de UX/camada extra: a
  // autorização de verdade continua 100% no servidor (middleware `role:`
  // em toda rota da API); mesmo que este cookie fosse forjado, o backend
  // recusaria qualquer chamada real.
  if (logado) {
    const role = request.cookies.get('oficina_role')?.value
    const regra = ROLE_RULES.find(r => pathname.startsWith(r.prefix))
    if (regra && role && !regra.roles.includes(role)) {
      return NextResponse.redirect(new URL('/?acesso=negado', request.url))
    }
  }

  return NextResponse.next()
}

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico|api).*)'],
}
