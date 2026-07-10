#!/usr/bin/env bash
# 一键部署脚本 —— SSH 恢复后直接运行： bash deploy.sh
# 前提：本地已执行 npm run build 与 php artisan test（均通过）。
set -euo pipefail

KEY=~/.ssh/cloud_server_ed25519
HOST=ubuntu@62.234.53.54
REMOTE=/var/www/palantir
SSH_OPTS="-o BindInterface=en0 -i ${KEY}"

echo "==> 1/5 本地构建与测试"
VITE_REVERB_APP_KEY="${VITE_REVERB_APP_KEY:-xyc-palantir}" \
VITE_REVERB_HOST="${VITE_REVERB_HOST:-palantir.umb.ink}" \
VITE_REVERB_PORT="${VITE_REVERB_PORT:-443}" \
VITE_REVERB_SCHEME="${VITE_REVERB_SCHEME:-https}" \
npm run build
npm run test:ui
php artisan test

echo "==> 2/5 rsync 同步代码到服务器（不覆盖 .env / storage 上传件）"
rsync -az --delete -e "ssh ${SSH_OPTS}" \
  --exclude='.env' \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='tests' \
  --exclude='*.png' \
  --exclude='storage/app/public/attachments/*' \
  --exclude='storage/logs/*' \
  --exclude='storage/framework/cache/data/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  ./ ${HOST}:${REMOTE}/

echo "==> 3/5 服务器：迁移、同步元数据和缓存"
ssh ${SSH_OPTS} ${HOST} "cd ${REMOTE} && \
/usr/local/bin/composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader && \
php artisan migrate --force && \
php artisan db:seed --class=XycPrototypeSeeder --force && \
php artisan optimize:clear && \
php artisan route:cache && \
php artisan config:cache && \
php artisan view:cache && \
sudo find ${REMOTE} -type d -exec chmod 755 {} + && \
sudo find ${REMOTE} -type f -exec chmod 644 {} + && \
sudo chown -R ubuntu:www-data ${REMOTE}/storage ${REMOTE}/bootstrap/cache && \
sudo chmod -R ug+rwX ${REMOTE}/storage ${REMOTE}/bootstrap/cache && \
sudo find ${REMOTE}/storage ${REMOTE}/bootstrap/cache -type d -exec chmod g+s {} +"

echo "==> 4/5 安装并重启 AI worker 与 Reverb"
ssh ${SSH_OPTS} ${HOST} "cd ${REMOTE} && \
sudo install -m 0644 deploy/systemd/palantir-ai-worker@.service /etc/systemd/system/palantir-ai-worker@.service && \
sudo install -m 0644 deploy/systemd/palantir-reverb.service /etc/systemd/system/palantir-reverb.service && \
sudo install -m 0644 deploy/nginx/palantir-reverb.conf /etc/nginx/snippets/palantir-reverb.conf && \
sudo systemctl daemon-reload && \
sudo systemctl enable palantir-ai-worker@1 palantir-ai-worker@2 palantir-reverb && \
php artisan queue:restart && \
sudo systemctl restart palantir-ai-worker@1 palantir-ai-worker@2 palantir-reverb php8.4-fpm && \
sudo nginx -t && \
sudo systemctl reload nginx"

echo "==> 5/5 部署后检查"
curl -I https://palantir.umb.ink
curl --fail --silent --show-error https://palantir.umb.ink/up
ssh ${SSH_OPTS} ${HOST} "systemctl is-active palantir-ai-worker@1 palantir-ai-worker@2 palantir-reverb && cd ${REMOTE} && php artisan ai:harness-health"
echo "部署完成。"
