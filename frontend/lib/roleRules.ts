export interface RoleRule {
  prefix: string
  roles: string[]
}

// Telas cujas rotas de LEITURA no backend já são restritas por role (não é
// só a mutação) — ver backend/routes/api.php. Nunca duplicar telas cuja
// leitura é aberta a todos os roles (ex: clientes, produtos, OS): lá a
// proteção real já é 100% no backend, via middleware `role:` nas rotas de
// escrita, e bloquear a tela inteira aqui seria mais restritivo que o
// próprio backend. Ordem importa: prefixos mais específicos primeiro (ex:
// categorias-fiscais antes de configuracoes, que tem uma role diferente).
export const ROLE_RULES: RoleRule[] = [
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

export function regraRoleDe(pathname: string): RoleRule | undefined {
  return ROLE_RULES.find(r => pathname.startsWith(r.prefix))
}

export function papelPermitido(pathname: string, role: string): boolean {
  const regra = regraRoleDe(pathname)
  return !regra || regra.roles.includes(role)
}
