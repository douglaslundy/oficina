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
  mv "$TMP_FILE" "$MAP_FILE"
  docker exec mecanicapro-nginx-1 nginx -s reload
  echo "$(date -Iseconds) allowlist atualizada, nginx recarregado"
else
  rm -f "$TMP_FILE"
fi
