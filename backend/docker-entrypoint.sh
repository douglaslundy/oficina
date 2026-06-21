#!/bin/sh
set -e

echo "=== MecânicaPro Backend Starting ==="

# Generate APP_KEY if not set
if [ -z "$APP_KEY" ]; then
    echo "Generating APP_KEY..."
    export APP_KEY=$(php -r "echo 'base64:'.base64_encode(random_bytes(32));")
fi

# Clear config cache (may fail on first run, that's OK)
php artisan config:clear 2>/dev/null || true

# Wait for database to be reachable (not for migrations table)
echo "Waiting for database connection..."
MAX_TRIES=30
TRY=0
until php -r "
\$host = getenv('DB_HOST') ?: 'postgres';
\$port = getenv('DB_PORT') ?: '5432';
\$db   = getenv('DB_DATABASE') ?: 'mecanicapro';
\$user = getenv('DB_USERNAME') ?: 'mecanicapro';
\$pass = getenv('DB_PASSWORD') ?: '';
try {
    new PDO(\"pgsql:host=\$host;port=\$port;dbname=\$db\", \$user, \$pass);
    exit(0);
} catch (Exception \$e) {
    exit(1);
}
" 2>/dev/null; do
    TRY=$((TRY+1))
    if [ $TRY -ge $MAX_TRIES ]; then
        echo "ERROR: Could not connect to database after $MAX_TRIES attempts"
        exit 1
    fi
    echo "Database not ready, attempt $TRY/$MAX_TRIES..."
    sleep 3
done

echo "Database connected!"

# Papel "worker": apenas processa as filas (sem migrate/seed/serve — o container
# web cuida disso). Mantém o wait-for-DB e o APP_KEY já tratados acima.
if [ "${CONTAINER_ROLE:-web}" = "worker" ]; then
    echo "=== Starting queue worker (whatsapp,default) ==="
    exec php artisan queue:work redis --queue=whatsapp,default --tries=3 --sleep=3 --timeout=120 --backoff=30
fi

# Run migrations (creates migrations table if needed, then runs all)
echo "Running migrations..."
php artisan migrate --force --no-interaction

# Seed database (idempotent - uses firstOrCreate/updateOrCreate)
echo "Seeding database..."
php artisan db:seed --class=DatabaseSeeder --force --no-interaction

# Cache config and routes for production performance
php artisan config:cache
php artisan route:cache

echo "=== Starting Laravel server on 0.0.0.0:8000 ==="
exec php artisan serve --host=0.0.0.0 --port=8000
