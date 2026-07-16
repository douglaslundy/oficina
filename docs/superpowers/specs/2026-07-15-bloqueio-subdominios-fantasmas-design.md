# Bloqueio de Subdomínios Fantasmas

## Contexto

O Traefik da VPS compartilhada (144.91.92.70) tem uma rota dinâmica em `/opt/traefik/dynamic/mecanicapro.yml` que encaminha **qualquer** `{subdomain}.dlsistemas.com.br` (regex `[a-z0-9-]+`) para o nginx do mecanicapro (`http://172.17.0.1:8080`). Essa rota é intencional — o mecanicapro é multi-tenant por subdomínio (cada oficina tem seu `slug.dlsistemas.com.br`, ex: `stuntmotos.dlsistemas.com.br`) — mas o Traefik não valida se o subdomínio corresponde a uma oficina real.

O nginx do mecanicapro (`docker/nginx/mecanicapro.conf`) hoje não verifica o `Host` em nenhum ponto: qualquer requisição, válida ou não, é encaminhada para o frontend Next.js (renderização completa) ou para o backend Laravel. Só o backend, em rotas `/api/*` e apenas quando o frontend já enviou um header `X-Tenant` (lido do `localStorage`, setado após login), consulta a tabela `oficinas` (coluna `slug`, única) e retorna 404/403/402 conforme o caso. Ou seja: hoje qualquer subdomínio "fantasma" (nunca existiu, ou foi de uma oficina removida, ou é um scan de bot testando strings aleatórias) atravessa Traefik → nginx → renderização completa do Next.js antes de qualquer checagem acontecer. Hoje há apenas 1 oficina com `status = ATIVA` no banco.

## Objetivo

Rejeitar subdomínios que não correspondem a nenhuma oficina cadastrada o mais cedo possível (no nginx do mecanicapro, antes de tocar Next.js/Laravel/Postgres), reduzindo carga desnecessária e mitigando tentativas de scanning, sem mexer na config compartilhada do Traefik (usada por ~30 outros containers na mesma VPS).

## Fora de escopo

- Qualquer alteração em `/opt/traefik/dynamic/mecanicapro.yml` ou outra config do Traefik.
- Qualquer alteração no comportamento do backend Laravel para oficinas com `status` diferente de "não existe" (CANCELADA/SUSPENSA/INADIMPLENTE continuam sendo tratadas pelo Laravel exatamente como hoje — 403/402 com mensagem específica). O nginx só filtra subdomínios com **zero linhas** na tabela `oficinas`.
- Autenticação, backend, frontend do mecanicapro — nenhuma mudança de código de aplicação.

## Design

### Componente 1 — filtro de allowlist no nginx (`docker/nginx/mecanicapro.conf`)

Adicionar no topo do arquivo (fora do bloco `server{}`, mesmo nível do `map $http_upgrade` já existente):

```nginx
map $host $tenant_valid {
    hostnames;
    default 0;
    oficina.dlsistemas.com.br 1;
    saas.dlsistemas.com.br 1;
    include /etc/nginx/conf.d/tenant-slugs.map;
}

map $tenant_valid $tenant_invalid {
    0 1;
    1 0;
}

log_format ghost '$remote_addr - [$time_local] "$request" host="$host" status=$status';
```

E logo no início do bloco `server { listen 80; server_name _; ... }`, antes de `location /api/`:

```nginx
access_log /var/log/mecanicapro/ghost_subdomains.log ghost if=$tenant_invalid;

if ($tenant_valid = 0) {
    return 444;
}
```

`return 444` fecha a conexão sem enviar resposta (padrão nginx para tráfego indesejado/scanning) — mais barato que um 404 completo e não dá pistas úteis a um scanner.

**Importante:** o log de rejeições vai para `/var/log/mecanicapro/ghost_subdomains.log` — um caminho **separado** de `/var/log/nginx/`. A imagem oficial `nginx:1.27-alpine` symlinka `/var/log/nginx/access.log`/`error.log` para `/dev/stdout`/`/dev/stderr`; montar um volume do host diretamente em `/var/log/nginx` substituiria esses symlinks e quebraria `docker logs mecanicapro-nginx-1`. Por isso o log de rejeição usa um diretório próprio, sem sobrepor nada existente.

### Componente 2 — arquivo de allowlist e sincronização

`docker/nginx/tenant-slugs.map` — um arquivo novo, versionado no repo, com uma linha por oficina existente:

```
stuntmotos.dlsistemas.com.br 1;
```

(seed inicial com a única oficina ativa hoje, pra não ficar vazio até o primeiro cron rodar)

Script `scripts/sync-tenant-allowlist.sh` (novo, roda no **host**, fora de qualquer container):

```bash
#!/bin/bash
set -euo pipefail

MAP_FILE="/opt/mecanicapro/docker/nginx/tenant-slugs.map"
TMP_FILE="$(mktemp)"

docker exec mecanicapro-postgres-1 psql -U mecanicapro -d mecanicapro -tAc \
  "SELECT slug FROM oficinas;" | while IFS= read -r slug; do
    slug="$(echo "$slug" | tr -d '[:space:]')"
    if [[ "$slug" =~ ^[a-z0-9-]+$ ]]; then
      echo "${slug}.dlsistemas.com.br 1;"
    fi
done > "$TMP_FILE"

if ! cmp -s "$TMP_FILE" "$MAP_FILE" 2>/dev/null; then
  mv "$TMP_FILE" "$MAP_FILE"
  docker exec mecanicapro-nginx-1 nginx -s reload
  echo "$(date -Iseconds) allowlist atualizada, nginx recarregado"
else
  rm -f "$TMP_FILE"
fi
```

A query inclui **todas** as oficinas independente do `status` (ATIVA/CANCELADA/SUSPENSA/INADIMPLENTE) — o nginx só precisa saber se a oficina existe; o Laravel já trata o status certo. O filtro `^[a-z0-9-]+$` evita que um slug malformado corrompa a sintaxe do `map` do nginx.

Cron no host (`crontab -e` do root, já que os outros scripts operacionais da VPS rodam como root):

```
*/5 * * * * /opt/mecanicapro/scripts/sync-tenant-allowlist.sh >> /var/log/sync-tenant-allowlist.log 2>&1
```

### Componente 3 — volumes no `docker-compose.prod.yml`

No serviço `nginx`, adicionar:

```yaml
    volumes:
      - ./docker/nginx/mecanicapro.conf:/etc/nginx/conf.d/default.conf:ro
      - ./docker/nginx/tenant-slugs.map:/etc/nginx/conf.d/tenant-slugs.map:ro
      - ./docker/nginx/logs:/var/log/mecanicapro
      - /etc/localtime:/etc/localtime:ro
```

`./docker/nginx/logs` precisa existir no host com permissão de escrita para o usuário não-root que o worker do nginx usa dentro do container (`nginx`, uid 101 na imagem alpine) — criar o diretório com `chmod 777` antes de subir o container é a forma mais simples de garantir isso sem descobrir o UID exato do usuário do processo.

### Componente 4 — fail2ban (mitigação de scanning)

Fail2ban já está instalado e ativo na VPS (hoje só protege SSH, `banaction = nftables`).

Filtro novo `/etc/fail2ban/filter.d/mecanicapro-ghost-subdomain.conf`:

```ini
[Definition]
failregex = ^<HOST> - \[.*\] ".*" host=".*" status=444$
ignoreregex =
```

Jail novo `/etc/fail2ban/jail.d/mecanicapro-ghost-subdomain.local`:

```ini
[mecanicapro-ghost-subdomain]
enabled = true
backend = auto
filter = mecanicapro-ghost-subdomain
logpath = /opt/mecanicapro/docker/nginx/logs/ghost_subdomains.log
maxretry = 5
findtime = 10m
bantime = 1h
banaction = nftables
```

5 tentativas de subdomínio inválido em 10 minutos → IP banido por 1 hora via nftables (mesmo mecanismo já usado pelo jail de SSH).

## Testes / verificação

Não há suíte de testes automatizada para infraestrutura neste projeto. Verificação manual pós-deploy:

1. `curl -I -H "Host: stuntmotos.dlsistemas.com.br" http://144.91.92.70:8080/` → deve continuar respondendo normalmente (200/302, não 444) — confirma que a oficina real não foi bloqueada.
2. `curl -I -H "Host: subdominio-que-nao-existe-123.dlsistemas.com.br" http://144.91.92.70:8080/` → deve retornar conexão fechada (444, sem corpo).
3. Conferir que `ghost_subdomains.log` recebeu a linha da requisição do passo 2.
4. Rodar `sync-tenant-allowlist.sh` manualmente uma vez e confirmar que `nginx -s reload` não gera erro (`docker logs mecanicapro-nginx-1 --tail 20`).
5. Repetir o passo 2 6 vezes seguidas do mesmo IP dentro de 10 minutos e confirmar com `fail2ban-client status mecanicapro-ghost-subdomain` que o IP foi banido.
6. Confirmar que `docker logs mecanicapro-nginx-1` continua mostrando access/error log normalmente (symlinks para stdout/stderr não foram afetados).

## Arquivos afetados (no repositório `/opt/mecanicapro`)

- **Modificado:** `docker/nginx/mecanicapro.conf`
- **Modificado:** `docker-compose.prod.yml`
- **Novo:** `docker/nginx/tenant-slugs.map`
- **Novo:** `scripts/sync-tenant-allowlist.sh`
- **Fora do repo, direto na VPS:** `/etc/fail2ban/filter.d/mecanicapro-ghost-subdomain.conf`, `/etc/fail2ban/jail.d/mecanicapro-ghost-subdomain.local`, crontab do root, diretório `docker/nginx/logs/` (criado localmente, não versionado — deve entrar no `.gitignore` do projeto).
