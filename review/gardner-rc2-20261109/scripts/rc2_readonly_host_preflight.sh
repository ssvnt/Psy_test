#!/usr/bin/env bash
set -Eeuo pipefail
# All checks read the live site without executing its app.php or making DB/network calls.
RC1=/root/dz-gardner-rc1-staged-20261109
printf '%s\n' 'RC2_HOST_PREFLIGHT_READONLY=START'
if [[ -f "$RC1/manifest.json" && -f "$RC1/RC1_REVIEW_ONLY.patch" ]]; then
  printf '%s\n' 'RC1_STAGED=YES'
else printf '%s\n' 'RC1_STAGED=MISSING'; fi
if command -v php >/dev/null 2>&1 && [[ -d "$RC1/candidate" ]]; then
  count=0
  while IFS= read -r -d '' p; do
    php -l "$p" >/dev/null
    ((count+=1))
  done < <(find "$RC1/candidate" -type f -name '*.php' ! -name '*.blade.php' -print0)
  printf 'RC1_PHP_SYNTAX_FILES=%s\n' "$count"
fi
printf 'LIVE_LARAVEL_SOURCE=%s\n' "$([[ -f /var/www/bribon/artisan ]] && echo PRESENT || echo MISSING)"
printf 'LIVE_MIGRATIONS_READ_ONLY=%s\n' "$([[ -d /var/www/bribon/database/migrations ]] && echo PRESENT || echo MISSING)"
printf 'ACTUAL_TIMEOUT_SOURCE_SHA256='; sha256sum /var/www/bribon/app/Services/Children/V2/GardnerV22/Production/GardnerV22DeepSeekHttpTransport.php 2>/dev/null | awk '{print $1}' || true
for unit in dz-gardner-r27-queue.service bribon-gardner-v22-queue.service dz-gardner-r27-paid.timer dz-gardner-r27-finalize.timer dz-gardner-r27-one-retry.timer dz-gardner-commerce.timer; do
 printf 'UNIT=%s\n' "$unit"
 systemctl show "$unit" --no-pager -p LoadState -p ActiveState -p SubState -p NRestarts -p ExecMainStatus -p UnitFileState 2>/dev/null || true
done
printf 'FILESYSTEM\n'; df -h /var/www/bribon /root 2>/dev/null || true
printf '%s\n' 'RC2_HOST_PREFLIGHT_READONLY=COMPLETE'
printf '%s\n' 'LIVE_SITE_WRITES=0 BANK_CALLS=0 MODEL_CALLS=0 SMTP_SENDS=0 MAX_SENDS=0'
