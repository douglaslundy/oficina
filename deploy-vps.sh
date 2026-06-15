#!/bin/bash
set -e

DEPLOY_DIR="/home/lundy/mecanicapro"
PROJECT="mecanicapro"

echo "=============================================="
echo "  MecânicaPro - Deploy na VPS"
echo "=============================================="

cd "$DEPLOY_DIR"

# Gerar APP_KEY se não existir
if [ ! -f .app_key ]; then
    echo "[1/6] Gerando APP_KEY..."
    APP_KEY=$(php -r "echo 'base64:'.base64_encode(random_bytes(32));" 2>/dev/null || \
              docker run --rm php:8.4-cli-alpine php -r "echo 'base64:'.base64_encode(random_bytes(32));")
    echo "$APP_KEY" > .app_key
    echo "APP_KEY gerado e salvo em .app_key"
else
    APP_KEY=$(cat .app_key)
    echo "[1/6] APP_KEY existente carregado."
fi

export APP_KEY="$APP_KEY"

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
echo "[6/6] Sistema em execução."
echo ""
echo "=============================================="
echo "  SISTEMA DISPONÍVEL:"
echo "  http://192.168.0.115:8080"
echo ""
echo "  CREDENCIAIS:"
echo "  Admin:      admin@mecanicapro.com / admin123"
echo "  Mecânico:   mecanico@mecanicapro.com / mec123"
echo "  SuperAdmin: superadmin@mecanicapro.com / super123"
echo "=============================================="
