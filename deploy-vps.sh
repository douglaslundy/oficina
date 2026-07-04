#!/bin/bash
set -e

# Diretório de deploy = onde este script está de fato (nunca hardcoded — evita
# quebrar quando o projeto muda de servidor/caminho, como já aconteceu antes).
DEPLOY_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
PROJECT="mecanicapro"

echo "=============================================="
echo "  MecânicaPro - Deploy na VPS"
echo "  Diretório: $DEPLOY_DIR"
echo "=============================================="

cd "$DEPLOY_DIR"

# APP_KEY vem do .env (lido automaticamente pelo Docker Compose) — nunca é
# gerada aqui. Gerar uma nova quebraria a descriptografia de dados já
# criptografados em produção (certificado A1, senhas). Só validamos que existe.
if [ ! -f .env ] || ! grep -q '^APP_KEY=base64:' .env; then
    echo "ERRO: .env ausente ou sem APP_KEY válida em $DEPLOY_DIR."
    echo "Este script NUNCA gera uma APP_KEY nova automaticamente — isso"
    echo "quebraria a descriptografia de dados já criptografados no banco."
    echo "Configure o .env manualmente antes de rodar o deploy."
    exit 1
fi
echo "[1/6] APP_KEY validada no .env."

echo "[2/6] Fazendo build das imagens Docker..."
docker compose -p $PROJECT -f docker-compose.prod.yml build --no-cache

echo "[3/6] Iniciando containers..."
docker compose -p $PROJECT -f docker-compose.prod.yml up -d

echo "[4/6] Aguardando sistema ficar saudável (pode levar ~2 min no primeiro deploy)..."
TRIES=0
MAX=40
until docker compose -p $PROJECT -f docker-compose.prod.yml exec -T backend \
    php -r "file_get_contents('http://localhost:8000/api/health') or exit(1);" 2>/dev/null; do
    TRIES=$((TRIES+1))
    if [ $TRIES -ge $MAX ]; then
        echo ""
        echo "ERRO: Backend não ficou pronto. Logs:"
        docker compose -p $PROJECT -f docker-compose.prod.yml logs backend --tail=50
        exit 1
    fi
    printf "."
    sleep 5
done

echo ""
echo "[5/6] Backend saudável. Aguardando frontend..."
TRIES=0
until docker compose -p $PROJECT -f docker-compose.prod.yml exec -T frontend \
    wget -qO- http://localhost:3000/ > /dev/null 2>&1; do
    TRIES=$((TRIES+1))
    if [ $TRIES -ge 20 ]; then
        echo ""
        echo "ERRO: Frontend não ficou pronto. Logs:"
        docker compose -p $PROJECT -f docker-compose.prod.yml logs frontend --tail=50
        exit 1
    fi
    printf "."
    sleep 5
done

echo ""
echo "[6/6] Containers saudáveis. Checando domínio público real..."

# Checagem de saúde interna dos containers NÃO garante que o domínio público
# está acessível de fato (proxy reverso compartilhado, DNS, roteamento por
# tenant) — só isso já causou uma indisponibilidade não detectada em produção.
# Ajuste PUBLIC_HEALTH_URL abaixo para um domínio real e acessível deste ambiente.
PUBLIC_HEALTH_URL="${PUBLIC_HEALTH_URL:-$(grep -m1 '^FRONTEND_URL=' .env | cut -d= -f2-)/api/health}"

if [ -n "$PUBLIC_HEALTH_URL" ] && [ "$PUBLIC_HEALTH_URL" != "/api/health" ]; then
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 15 "$PUBLIC_HEALTH_URL" || echo "000")
    if [ "$HTTP_CODE" = "200" ]; then
        echo "Domínio público OK: $PUBLIC_HEALTH_URL respondeu 200."
    else
        echo ""
        echo "AVISO: $PUBLIC_HEALTH_URL respondeu $HTTP_CODE (esperado 200)."
        echo "Os containers estão saudáveis internamente, mas o domínio público"
        echo "pode não estar acessível (proxy reverso, DNS, certificado, etc)."
        echo "Verifique manualmente antes de considerar o deploy concluído."
    fi
else
    echo "AVISO: PUBLIC_HEALTH_URL/FRONTEND_URL não configurado — pulando checagem"
    echo "do domínio público. Defina FRONTEND_URL no .env ou exporte"
    echo "PUBLIC_HEALTH_URL=https://seu-dominio/api/health antes de rodar este script."
fi

echo ""
echo "=============================================="
echo "  Deploy concluído. Containers no ar."
echo "=============================================="
