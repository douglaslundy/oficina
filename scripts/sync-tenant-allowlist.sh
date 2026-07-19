#!/bin/bash
set -euo pipefail

MAP_FILE="/opt/mecanicapro/docker/nginx/tenant-slugs.map"
TMP_FILE="$(mktemp)"

docker exec mecanicapro-postgres-1 psql -U mecanicapro -d mecanicapro -tAc   "SELECT slug FROM oficinas;" | while IFS= read -r slug; do
    slug="$(echo "$slug" | tr -d '[:space:]')"
    if [[ "$slug" =~ ^[a-z0-9-]+$ ]]; then
      echo "${slug}.dlsistemas.com.br 1;"
    fi
done > "$TMP_FILE"

if ! cmp -s "$TMP_FILE" "$MAP_FILE" 2>/dev/null; then
  # Escreve no MESMO inode (nunca mv/rename) — o nginx recebe este arquivo via
  # bind mount de arquivo único, que trava no inode montado no start do
  # container. Um mv troca o inode e quebra o mount silenciosamente: o
  # arquivo no host fica correto, mas o nginx nunca mais enxerga mudança
  # nenhuma até o container ser recriado. `cat >` regrava o conteúdo do
  # inode existente, então o bind mount continua válido.
  cat "$TMP_FILE" > "$MAP_FILE"
  rm -f "$TMP_FILE"
  docker exec mecanicapro-nginx-1 nginx -s reload
  echo "$(date -Iseconds) allowlist atualizada, nginx recarregado"
else
  rm -f "$TMP_FILE"
fi
