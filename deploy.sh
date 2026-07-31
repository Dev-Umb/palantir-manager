#!/usr/bin/env bash
# 一键部署脚本 —— SSH 恢复后直接运行： bash deploy.sh
# 前提：本地已执行 npm run build 与 php artisan test（均通过）。
set -euo pipefail

KEY=~/.ssh/cloud_server_ed25519
HOST=ubuntu@62.234.53.54
REMOTE=/var/www/palantir
SSH_OPTS="-o BindInterface=en0 -i ${KEY}"
ASSET_CACHE_MARKER='max-age=31536000, immutable'

echo "==> 1/5 本地构建与测试"
npm run build
php artisan test

echo "==> 2/5 rsync 同步代码到服务器（不覆盖 .env / storage 上传件）"
rsync -az --delete -e "ssh ${SSH_OPTS}" \
  --exclude='.env*' \
  --exclude='.git' \
  --exclude='.agents' \
  --exclude='.claude' \
  --exclude='.cloudstudio' \
  --exclude='.codex' \
  --exclude='.DS_Store' \
  --exclude='.mcp.json' \
  --exclude='.phpunit.result.cache' \
  --exclude='.superpowers' \
  --exclude='AGENTS.md' \
  --exclude='CLAUDE.md' \
  --exclude='deploy' \
  --exclude='docs' \
  --exclude='frontend' \
  --exclude='node_modules' \
  --exclude='tests' \
  --exclude='vendor' \
  --exclude='*.png' \
  --exclude='bootstrap/cache/*' \
  --exclude='database/database.sqlite' \
  --exclude='storage/app/deploy-backups/***' \
  --exclude='storage/app/private/***' \
  --exclude='storage/app/public/***' \
  --exclude='storage/logs/*' \
  --exclude='storage/framework/cache/data/*' \
  --exclude='storage/framework/down' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/testing/*' \
  --exclude='storage/framework/views/*' \
  ./ ${HOST}:${REMOTE}/

echo "==> 3/5 检查 nginx 静态资源压缩与缓存配置（只读，不自动修改）"
if ssh ${SSH_OPTS} ${HOST} "sudo nginx -T 2>/dev/null | grep -Fq '${ASSET_CACHE_MARKER}'"; then
  echo "nginx 已启用指纹资源长缓存配置。"
else
  echo "nginx 尚未启用 deploy/nginx/palantir-assets.conf。部署后请先备份现有站点配置，再手动安装并 include 该片段，执行 sudo nginx -t 成功后方可 reload。"
fi

echo "==> 4/5 服务器：同步元数据、清缓存、重启服务"
ssh ${SSH_OPTS} ${HOST} "cd ${REMOTE} && \
php artisan optimize:clear && \
php artisan migrate --force && \
php artisan db:seed --class=XycPrototypeSeeder --force && \
php artisan route:cache && \
php artisan config:cache && \
php artisan view:cache && \
sudo find ${REMOTE} -type d -exec chmod 755 {} + && \
sudo find ${REMOTE} -type f -exec chmod 644 {} + && \
sudo chown -R ubuntu:www-data ${REMOTE}/storage ${REMOTE}/bootstrap/cache && \
sudo chmod -R ug+rwX ${REMOTE}/storage ${REMOTE}/bootstrap/cache && \
sudo find ${REMOTE}/storage ${REMOTE}/bootstrap/cache -type d -exec chmod g+s {} + && \
sudo systemctl restart php8.4-fpm && \
sudo systemctl reload nginx"

echo "==> 5/5 部署后检查（预期未登录返回 302 /login）"
curl -I https://palantir.umb.ink
echo "部署完成。"
