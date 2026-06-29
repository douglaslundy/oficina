import { NextResponse } from 'next/server'
import type { NextRequest } from 'next/server'

const PUBLIC_PATHS = ['/login', '/forgot-password', '/reset-password', '/orcamento']

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl

  // --- SaaS Admin routes ---
  if (pathname.startsWith('/saas-admin')) {
    const SAAS_PUBLIC = ['/saas-admin/login', '/saas-admin/forgot-password', '/saas-admin/reset-password']
    if (SAAS_PUBLIC.some(p => pathname.startsWith(p))) {
      return NextResponse.next()
    }
    const saasToken = request.cookies.get('saas_token')?.value
    if (!saasToken) {
      return NextResponse.redirect(new URL('/saas-admin/login', request.url))
    }
    return NextResponse.next()
  }

  // --- Regular tenant routes ---
  const isPublic = PUBLIC_PATHS.some(p => pathname.startsWith(p))

  // Check for token in cookies
  const token = request.cookies.get('auth_token')?.value

  if (!token && !isPublic) {
    return NextResponse.redirect(new URL('/login', request.url))
  }

  if (token && pathname === '/login') {
    return NextResponse.redirect(new URL('/', request.url))
  }

  return NextResponse.next()
}

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico|api).*)'],
}
