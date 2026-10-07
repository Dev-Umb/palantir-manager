#!/usr/bin/env bash
set -euo pipefail
: "${PALANTIR_BACKEND_DIR:?Set PALANTIR_BACKEND_DIR to the Laravel checkout with existing Web routes and local test fixtures}"
cd "$PALANTIR_BACKEND_DIR"
test -f routes/web.php
export APP_ENV=local APP_DEBUG=false DB_CONNECTION=sqlite
export DB_DATABASE="$PWD/database/mobile.sqlite"
export SESSION_DRIVER=file CACHE_STORE=array QUEUE_CONNECTION=database MAIL_MAILER=log AI_HARNESS_V2=true
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
if [ -z "${PALANTIR_MOBILE_PASSWORD:-}" ]; then
  read -r -s -p '本地 mobile.local@example.test 登录密码: ' PALANTIR_MOBILE_PASSWORD
  echo
fi
export PALANTIR_MOBILE_PASSWORD
touch "$DB_DATABASE"
php artisan migrate --force --no-interaction
php artisan db:seed --class=MobileDevelopmentSeeder --force --no-interaction
php artisan queue:work --sleep=1 --tries=1 &
queue_pid=$!
trap 'kill "$queue_pid" 2>/dev/null || true' EXIT
php artisan serve --host=127.0.0.1 --port=8765
