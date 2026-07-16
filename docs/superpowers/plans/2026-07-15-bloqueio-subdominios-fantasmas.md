# Bloqueio de Subdomínios Fantasmas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Rejeitar, no nginx do mecanicapro (VPS 144.91.92.70), qualquer subdomínio `*.dlsistemas.com.br` que não corresponda a uma oficina cadastrada, antes de chegar no Next.js/Laravel/Postgres — reduzindo carga e mitigando scanning, sem tocar na config compartilhada do Traefik.

**Architecture:** Um `map` no nginx do mecanicapro (`docker/nginx/mecanicapro.conf`) checa `$host` contra um arquivo `tenant-slugs.map` gerado a partir da tabela `oficinas`; requisições fora da allowlist recebem `return 444` e são logadas separadamente em `/var/log/mecanicapro/ghost_subdomains.log`. Um script no host, agendado via cron, mantém o `tenant-slugs.map` sincronizado com o Postgres. Um jail novo do fail2ban (já instalado na VPS) lê esse log e bane IPs repetidamente rejeitados.

**Tech Stack:** nginx (dentro do container `mecanicapro-nginx-1`), bash (script no host), cron (root, no host), fail2ban (já instalado na VPS), Postgres (consulta read-only via `docker exec`).

## Global Constraints

- Não alterar `/opt/traefik/dynamic/mecanicapro.yml` nem qualquer outra config do Traefik.
- Não alterar o comportamento do Laravel para oficinas CANCELADA/SUSPENSA/INADIMPLENTE — o nginx só filtra subdomínios sem nenhuma linha correspondente em `oficinas`.
- O log de rejeição do nginx vai em `/var/log/mecanicapro/ghost_subdomains.log` — **nunca** montar um volume do host direto em `/var/log/nginx` (quebraria os symlinks para stdout/stderr da imagem oficial e o `docker logs` pararia de mostrar access/error log).
- O jail do fail2ban deve restringir a porta (`port = http,https`), igual ao jail de `sshd` já existente restringir a `ssh` — nunca banir todas as portas de um IP a partir desse jail (risco de bloquear acesso SSH acidentalmente).
- Todo o trabalho é no repositório `/opt/mecanicapro` na VPS (via SSH) — não há checkout local. Cada task commita direto lá.
- Nenhuma automated test suite existe para infraestrutura neste projeto — verificação é sempre via `curl`/`docker logs`/`fail2ban-client` reais, listados em cada task.

---

### Task 1: Filtro de allowlist no nginx (conf + map + volumes) + deploy

**Files (no repo `/opt/mecanicapro`, via SSH):**
- Modify: `docker/nginx/mecanicapro.conf`
- Create: `docker/nginx/tenant-slugs.map`
- Modify: `docker-compose.prod.yml` (serviço `nginx`)
- Modify: `.gitignore` (ignorar `docker/nginx/logs/`)

**Interfaces:**
- Produces: arquivo `/etc/nginx/conf.d/tenant-slugs.map` dentro do container `mecanicapro-nginx-1`, consumido pelo script da Task 2 (que reescreve esse mesmo arquivo no host em `docker/nginx/tenant-slugs.map`).
- Produces: log `/var/log/mecanicapro/ghost_subdomains.log` (mapeado do host em `docker/nginx/logs/ghost_subdomains.log`), consumido pelo fail2ban da Task 3.

- [x] **Step 1: Ler o `mecanicapro.conf` atual pra confirmar que nada mudou desde a spec**

```bash
ssh root@144.91.92.70 "cat /opt/mecanicapro/docker/nginx/mecanicapro.conf"
```

Confirme que o arquivo ainda começa com o `map $http_upgrade $connection_upgrade { ... }` e o `server { listen 80; server_name _; ... }` documentados na spec (`docs/superpowers/specs/2026-07-15-bloqueio-subdominios-fantasmas-design.md`). Se divergir, pare e reavalie antes de continuar.

- [x] **Step 2: Criar `docker/nginx/tenant-slugs.map` com o seed inicial**

```bash
ssh root@144.91.92.70 "cat > /opt/mecanicapro/docker/nginx/tenant-slugs.map << 'EOF'
stuntmotos.dlsistemas.com.br 1;
EOF"
```

- [x] **Step 3: Editar `docker/nginx/mecanicapro.conf`**

Sobrescrever o arquivo inteiro (conteúdo atual + adições, confirmado no Step 1) com:

```bash
ssh root@144.91.92.70 "cat > /opt/mecanicapro/docker/nginx/mecanicapro.conf << 'EOF'
map \$host \$tenant_valid {
    hostnames;
    default 0;
    oficina.dlsistemas.com.br 1;
    saas.dlsistemas.com.br 1;
    include /etc/nginx/conf.d/tenant-slugs.map;
}

map \$tenant_valid \$tenant_invalid {
    0 1;
    1 0;
}

log_format ghost '\$remote_addr - [\$time_local] \"\$request\" host=\"\$host\" status=\$status';

map \$http_upgrade \$connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 80;
    server_name _;

    # Docker's internal DNS — re-resolve hostnames quando containers reiniciam
    resolver 127.0.0.11 valid=5s ipv6=off;

    client_max_body_size 20M;
    proxy_read_timeout 120s;
    proxy_connect_timeout 10s;

    access_log /var/log/mecanicapro/ghost_subdomains.log ghost if=\$tenant_invalid;

    if (\$tenant_valid = 0) {
        return 444;
    }

    # Backend API routes
    location /api/ {
        set \$backend_host backend:8000;
        proxy_pass http://\$backend_host;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    # Sanctum CSRF cookie
    location /sanctum/ {
        set \$backend_host backend:8000;
        proxy_pass http://\$backend_host;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    # Next.js frontend (includes _next/static, _next/image, etc.)
    location / {
        set \$frontend_host frontend:3000;
        proxy_pass http://\$frontend_host;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection \$connection_upgrade;
    }
}
EOF"
```

Note: os `\$` escapam o `$` pra não ser expandido pelo shell local antes de chegar no SSH — dentro do heredoc remoto (delimitador `'EOF'` quotado) eles viram `$` literais nginx, como esperado.

Não valide a sintaxe ainda com `nginx -t` neste ponto — o container antigo ainda não tem o volume de `tenant-slugs.map` montado (isso só acontece no Step 7, depois do `--force-recreate`), então o `include` do map falharia por arquivo ausente mesmo com a sintaxe correta. A validação de sintaxe entra logo após o recreate, no Step 7.

- [x] **Step 4: Editar `docker-compose.prod.yml` — volumes do serviço `nginx`**

No serviço `nginx`, o bloco `volumes:` atual é:

```yaml
    volumes:
      - ./docker/nginx/mecanicapro.conf:/etc/nginx/conf.d/default.conf:ro
      - /etc/localtime:/etc/localtime:ro
```

Trocar por:

```yaml
    volumes:
      - ./docker/nginx/mecanicapro.conf:/etc/nginx/conf.d/default.conf:ro
      - ./docker/nginx/tenant-slugs.map:/etc/nginx/conf.d/tenant-slugs.map:ro
      - ./docker/nginx/logs:/var/log/mecanicapro
      - /etc/localtime:/etc/localtime:ro
```

- [x] **Step 5: Criar o diretório de logs no host com permissão de escrita**

```bash
ssh root@144.91.92.70 "mkdir -p /opt/mecanicapro/docker/nginx/logs && chmod 777 /opt/mecanicapro/docker/nginx/logs"
```

- [x] **Step 6: Ignorar o diretório de logs no git**

```bash
ssh root@144.91.92.70 "grep -qxF 'docker/nginx/logs/' /opt/mecanicapro/.gitignore || echo 'docker/nginx/logs/' >> /opt/mecanicapro/.gitignore"
```

- [x] **Step 7: Recriar só o container `nginx` do mecanicapro (não a stack inteira)**

```bash
ssh root@144.91.92.70 "cd /opt/mecanicapro && docker compose -f docker-compose.prod.yml up -d --force-recreate nginx"
```

Expected: `mecanicapro-nginx-1` recriado e saudável. Confirme:

```bash
ssh root@144.91.92.70 "docker ps --filter name=mecanicapro-nginx-1 --format '{{.Status}}'"
```

Expected: status `Up ...` (sem `Restarting`). Se aparecer `Restarting`, a config tem erro de sintaxe — rode `docker logs mecanicapro-nginx-1 --tail 30` pra ver o erro do nginx antes de continuar.

Com o container já recriado (volumes de `tenant-slugs.map` e `logs` agora montados), confirme a sintaxe explicitamente:

```bash
ssh root@144.91.92.70 "docker exec mecanicapro-nginx-1 nginx -t"
```

Expected: `nginx: configuration file /etc/nginx/nginx.conf test is successful`.

- [x] **Step 8: Verificar que a oficina real continua respondendo**

```bash
curl -sI -H "Host: stuntmotos.dlsistemas.com.br" http://144.91.92.70:8080/
```

Expected: um status HTTP normal (200, 301, 302 ou similar) — **não** conexão fechada.

- [x] **Step 9: Verificar que um subdomínio fantasma é rejeitado**

```bash
curl -sI -H "Host: subdominio-fantasma-teste-9f3a.dlsistemas.com.br" http://144.91.92.70:8080/ ; echo "exit code: $?"
```

Expected: `curl` falha com erro de conexão fechada pelo peer (`exit code` diferente de 0, tipicamente 52 ou 56 — "Empty reply from server"), confirmando o `return 444`.

- [x] **Step 10: Confirmar que a rejeição foi logada**

```bash
ssh root@144.91.92.70 "tail -5 /opt/mecanicapro/docker/nginx/logs/ghost_subdomains.log"
```

Expected: uma linha contendo `host="subdominio-fantasma-teste-9f3a.dlsistemas.com.br"` e `status=444`.

- [x] **Step 11: Confirmar que os logs padrão do nginx não quebraram**

```bash
ssh root@144.91.92.70 "docker logs mecanicapro-nginx-1 --tail 20"
```

Expected: linhas de access/error log do nginx aparecem normalmente (prova de que os symlinks para stdout/stderr continuam intactos, já que só montamos `/var/log/mecanicapro`, não `/var/log/nginx`).

- [x] **Step 12: Commit**

```bash
ssh root@144.91.92.70 "cd /opt/mecanicapro && git add docker/nginx/mecanicapro.conf docker/nginx/tenant-slugs.map docker-compose.prod.yml .gitignore && git -c user.name='Claude Sonnet 5' -c user.email='noreply@anthropic.com' commit -m 'feat: bloqueia subdominios sem oficina correspondente no nginx

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01MG966pAm4oEvvQvPrWuqKy'"
```

---

### Task 2: Script de sincronização da allowlist + cron

**Files:**
- Create: `scripts/sync-tenant-allowlist.sh`

**Interfaces:**
- Consumes: `docker exec mecanicapro-postgres-1 psql -U mecanicapro -d mecanicapro` (tabela `oficinas`, coluna `slug`) — mesma tabela/coluna documentada na spec.
- Consumes: `docker exec mecanicapro-nginx-1 nginx -s reload` (container já recriado na Task 1, com os volumes de `tenant-slugs.map` montados).
- Produces: reescreve `/opt/mecanicapro/docker/nginx/tenant-slugs.map` (mesmo arquivo criado/montado na Task 1).

- [x] **Step 1: Criar o script**

```bash
ssh root@144.91.92.70 "cat > /opt/mecanicapro/scripts/sync-tenant-allowlist.sh << 'SCRIPT_EOF'
#!/bin/bash
set -euo pipefail

MAP_FILE=\"/opt/mecanicapro/docker/nginx/tenant-slugs.map\"
TMP_FILE=\"\$(mktemp)\"

docker exec mecanicapro-postgres-1 psql -U mecanicapro -d mecanicapro -tAc \\
  \"SELECT slug FROM oficinas;\" | while IFS= read -r slug; do
    slug=\"\$(echo \"\$slug\" | tr -d '[:space:]')\"
    if [[ \"\$slug\" =~ ^[a-z0-9-]+\$ ]]; then
      echo \"\${slug}.dlsistemas.com.br 1;\"
    fi
done > \"\$TMP_FILE\"

if ! cmp -s \"\$TMP_FILE\" \"\$MAP_FILE\" 2>/dev/null; then
  mv \"\$TMP_FILE\" \"\$MAP_FILE\"
  docker exec mecanicapro-nginx-1 nginx -s reload
  echo \"\$(date -Iseconds) allowlist atualizada, nginx recarregado\"
else
  rm -f \"\$TMP_FILE\"
fi
SCRIPT_EOF
chmod +x /opt/mecanicapro/scripts/sync-tenant-allowlist.sh"
```

- [x] **Step 2: Rodar manualmente uma vez e verificar**

```bash
ssh root@144.91.92.70 "/opt/mecanicapro/scripts/sync-tenant-allowlist.sh"
```

Expected: sem erro. Primeira execução deve imprimir `allowlist atualizada, nginx recarregado` **ou** nada (se o conteúdo já bater com o seed do Step 2 da Task 1 — o que é esperado, já que hoje só existe a oficina `stuntmotos`).

- [x] **Step 3: Confirmar que o conteúdo bate com o banco**

```bash
ssh root@144.91.92.70 "docker exec mecanicapro-postgres-1 psql -U mecanicapro -d mecanicapro -tAc 'SELECT slug FROM oficinas;'"
ssh root@144.91.92.70 "cat /opt/mecanicapro/docker/nginx/tenant-slugs.map"
```

Expected: cada slug do primeiro comando aparece como `<slug>.dlsistemas.com.br 1;` no segundo.

- [x] **Step 4: Confirmar que o nginx recarregou sem erro**

```bash
ssh root@144.91.92.70 "docker logs mecanicapro-nginx-1 --tail 10"
```

Expected: nenhuma linha de erro relacionada a `nginx -s reload` (ex: `nginx: [emerg]`).

- [x] **Step 5: Adicionar o cron (root, a cada 5 minutos)**

```bash
ssh root@144.91.92.70 "(crontab -l 2>/dev/null | grep -vF 'sync-tenant-allowlist.sh'; echo '*/5 * * * * /opt/mecanicapro/scripts/sync-tenant-allowlist.sh >> /var/log/sync-tenant-allowlist.log 2>&1') | crontab -"
```

- [x] **Step 6: Confirmar que o cron foi registrado**

```bash
ssh root@144.91.92.70 "crontab -l | grep sync-tenant-allowlist"
```

Expected: uma linha `*/5 * * * * /opt/mecanicapro/scripts/sync-tenant-allowlist.sh >> /var/log/sync-tenant-allowlist.log 2>&1`.

- [x] **Step 7: Commit**

```bash
ssh root@144.91.92.70 "cd /opt/mecanicapro && git add scripts/sync-tenant-allowlist.sh && git -c user.name='Claude Sonnet 5' -c user.email='noreply@anthropic.com' commit -m 'feat: script de sincronizacao da allowlist de subdominios

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01MG966pAm4oEvvQvPrWuqKy'"
```

(o cron em si e o diretório `docker/nginx/logs/` não são versionados — cron fica só no crontab do host, logs estão no `.gitignore` desde a Task 1)

---

### Task 3: Jail do fail2ban para mitigar scanning

**Files (fora do repo, direto na VPS):**
- Create: `/etc/fail2ban/filter.d/mecanicapro-ghost-subdomain.conf`
- Create: `/etc/fail2ban/jail.d/mecanicapro-ghost-subdomain.local`

**Interfaces:**
- Consumes: `/opt/mecanicapro/docker/nginx/logs/ghost_subdomains.log`, no formato `$remote_addr - [$time_local] "$request" host="$host" status=$status` definido na Task 1.

- [x] **Step 1: Criar o filtro**

```bash
ssh root@144.91.92.70 "cat > /etc/fail2ban/filter.d/mecanicapro-ghost-subdomain.conf << 'EOF'
[Definition]
failregex = ^<HOST> - \[.*\] \".*\" host=\".*\" status=444\$
ignoreregex =
EOF"
```

- [x] **Step 2: Testar o filtro em modo dry-run (sem banir ninguém)**

```bash
ssh root@144.91.92.70 "fail2ban-regex /opt/mecanicapro/docker/nginx/logs/ghost_subdomains.log /etc/fail2ban/filter.d/mecanicapro-ghost-subdomain.conf"
```

Expected: na seção "Success" da saída, ao menos 1 match — a linha gravada no Step 10 da Task 1 (`subdominio-fantasma-teste-9f3a.dlsistemas.com.br`). Se aparecer 0 matches, o regex está errado — pare e ajuste antes de criar o jail (não prossiga com um filtro não verificado).

- [x] **Step 3: Criar o jail, restringindo a porta HTTP/HTTPS explicitamente**

```bash
ssh root@144.91.92.70 "cat > /etc/fail2ban/jail.d/mecanicapro-ghost-subdomain.local << 'EOF'
[mecanicapro-ghost-subdomain]
enabled = true
backend = auto
filter = mecanicapro-ghost-subdomain
logpath = /opt/mecanicapro/docker/nginx/logs/ghost_subdomains.log
port = http,https
maxretry = 5
findtime = 10m
bantime = 1h
banaction = nftables
EOF"
```

`port = http,https` garante que um ban desse jail nunca bloqueia a porta 22 (SSH) — mesmo padrão de isolamento por porta que o jail `sshd` já usa pra `ssh`.

- [x] **Step 4: Recarregar o fail2ban**

```bash
ssh root@144.91.92.70 "fail2ban-client reload"
```

- [x] **Step 5: Confirmar que o jail está ativo**

```bash
ssh root@144.91.92.70 "fail2ban-client status"
```

Expected: a lista de jails agora inclui `mecanicapro-ghost-subdomain` além de `sshd`.

```bash
ssh root@144.91.92.70 "fail2ban-client status mecanicapro-ghost-subdomain"
```

Expected: jail ativo, "Currently banned: 0" (nenhum IP real deve ter sido banido só por causa da Task 1 — o dry-run do Step 2 desta task não bane ninguém).

**Nota — não automatizar o teste de ban real:** confirmar que o jail efetivamente bane um IP exigiria disparar 5+ requisições de subdomínio fantasma em menos de 10 minutos a partir do MESMO IP usado para testar — se esse for o IP de onde você acessa a VPS via SSH (ex: testando via `curl` da sua própria máquina), um ban real, mesmo restrito a `http,https`, ainda merece cautela. Esse teste fica como verificação manual opcional, feita deliberadamente pelo usuário sabendo o IP de origem, não como parte automática deste plano.

- [x] **Step 6: Commit**

Não há arquivos a commitar no repo `/opt/mecanicapro` nesta task (filtro e jail do fail2ban ficam fora de qualquer repositório versionado, como já documentado na spec). Nenhuma ação de commit necessária.
