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
              docker run --rm php:8.3-cli-alpine php -r "echo 'base64:'.base64_encode(random_bytes(32));")
    echo "$APP_KEY" > .app_key
    echo "APP_KEY gerado e salvo em .app_key"
else
    APP_KEY=$(cat .app_key)
    echo "[1/6] APP_KEY existente carregado."
fi

# Injetar APP_KEY no docker-compose.prod.yml via variável de ambiente
export APP_KEY="$APP_KEY"

echo "[2/6] Fazendo build das imagens Docker..."
docker compose -p $PROJECT -f docker-compose.prod.yml build

echo "[3/6] Iniciando containers..."
docker compose -p $PROJECT -f docker-compose.prod.yml up -d

echo "[4/6] Aguardando backend ficar saudável..."
TRIES=0
MAX=60
until docker compose -p $PROJECT -f docker-compose.prod.yml exec -T backend php artisan migrate:status --no-interaction 2>/dev/null; do
    TRIES=$((TRIES+1))
    if [ $TRIES -ge $MAX ]; then
        echo "ERRO: Backend não ficou pronto a tempo."
        docker compose -p $PROJECT -f docker-compose.prod.yml logs backend
        exit 1
    fi
    echo "Aguardando backend... ($TRIES/$MAX)"
    sleep 5
done

echo "[5/6] Sistema em execução."
echo ""
echo "=============================================="
echo "  SISTEMA DISPONÍVEL:"
echo "  http://192.168.0.115:8080"
echo ""
echo "  CREDENCIAIS PADRÃO:"
echo "  Admin:     admin@mecanicapro.com / admin123"
echo "  Mecânico:  mecanico@mecanicapro.com / mec123"
echo "  SuperAdmin: superadmin@mecanicapro.com / super123"
echo "=============================================="
