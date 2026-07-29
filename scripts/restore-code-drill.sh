#!/usr/bin/env bash
# 仅在临时目录演练代码精确恢复，不连接或修改生产环境。
set -Eeuo pipefail
umask 077

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RESTORE_SCRIPT="${ROOT}/deploy/restore-code-backup.sh"
test -x "$RESTORE_SCRIPT" || test -f "$RESTORE_SCRIPT"

for command_name in tar rsync mktemp; do
  command -v "$command_name" >/dev/null
done

DRILL_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/palantir-restore-drill.XXXXXX")"
cleanup() {
  rm -rf -- "$DRILL_ROOT"
}
trap cleanup EXIT

APP_NAME="xyc-restore-drill"
SOURCE_PARENT="${DRILL_ROOT}/before"
SOURCE_APP="${SOURCE_PARENT}/${APP_NAME}"
TARGET_APP="${DRILL_ROOT}/live/${APP_NAME}"
ARCHIVE="${DRILL_ROOT}/code-before.tar.gz"

mkdir -p \
  "$SOURCE_APP/app" \
  "$SOURCE_APP/storage/app/private/attachments" \
  "$SOURCE_APP/storage/app/private/backups" \
  "$SOURCE_APP/storage/logs" \
  "$SOURCE_APP/storage/framework"
touch "$SOURCE_APP/artisan"
printf '%s\n' 'before-release' > "$SOURCE_APP/app/version.txt"
printf '%s\n' 'archive-env-must-not-restore' > "$SOURCE_APP/.env"
printf '%s\n' 'archive-attachment' > "$SOURCE_APP/storage/app/private/attachments/file.txt"
printf '%s\n' 'archive-backup' > "$SOURCE_APP/storage/app/private/backups/data.gz"
printf '%s\n' 'archive-log' > "$SOURCE_APP/storage/logs/laravel.log"
printf '%s\n' 'archive-runtime' > "$SOURCE_APP/storage/framework/runtime.cache"

tar -czf "$ARCHIVE" \
  --exclude="${APP_NAME}/.env" \
  --exclude="${APP_NAME}/storage/framework" \
  --exclude="${APP_NAME}/storage/logs" \
  --exclude="${APP_NAME}/storage/app/private/backups" \
  --exclude="${APP_NAME}/storage/app/private/attachments" \
  -C "$SOURCE_PARENT" "$APP_NAME"

mkdir -p "$TARGET_APP"
cp -R "$SOURCE_APP/." "$TARGET_APP/"
printf '%s\n' 'failed-release' > "$TARGET_APP/app/version.txt"
printf '%s\n' 'must-be-deleted' > "$TARGET_APP/app/new-release-only.txt"
printf '%s\n' 'production-env' > "$TARGET_APP/.env"
printf '%s\n' 'production-attachment' > "$TARGET_APP/storage/app/private/attachments/file.txt"
printf '%s\n' 'production-backup' > "$TARGET_APP/storage/app/private/backups/data.gz"
printf '%s\n' 'production-log' > "$TARGET_APP/storage/logs/laravel.log"
printf '%s\n' 'maintenance' > "$TARGET_APP/storage/framework/down"

bash "$RESTORE_SCRIPT" "$ARCHIVE" "$TARGET_APP" >/dev/null

[[ "$(<"$TARGET_APP/app/version.txt")" == "before-release" ]]
test ! -e "$TARGET_APP/app/new-release-only.txt"
[[ "$(<"$TARGET_APP/.env")" == "production-env" ]]
[[ "$(<"$TARGET_APP/storage/app/private/attachments/file.txt")" == "production-attachment" ]]
[[ "$(<"$TARGET_APP/storage/app/private/backups/data.gz")" == "production-backup" ]]
[[ "$(<"$TARGET_APP/storage/logs/laravel.log")" == "production-log" ]]
[[ "$(<"$TARGET_APP/storage/framework/down")" == "maintenance" ]]

echo "RESTORE_DRILL_OK exact_code=restored runtime=preserved"
