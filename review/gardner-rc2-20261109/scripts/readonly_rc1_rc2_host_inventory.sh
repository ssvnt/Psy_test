#!/usr/bin/env bash
# READ-ONLY evidence; never run artisan, alter .env, bank, model, mail or MAX.
set -Eeuo pipefail
printf 'DZ_RC2_HOST_INVENTORY=READ_ONLY\n'
for unit in dz-gardner-r27-queue.service bribon-gardner-v22-queue.service dz-gardner-r27-one-retry.timer dz-gardner-r27-paid.timer dz-gardner-r27-finalize.timer dz-gardner-commerce.timer; do
 printf 'UNIT=%s\n' "$unit"
 systemctl show "$unit" --no-pager -p LoadState -p ActiveState -p SubState -p NRestarts -p ExecMainStatus -p UnitFileState 2>/dev/null || true
done
printf 'VOLUME\n'; df -h /var/www/bribon /root 2>/dev/null || true
printf 'PROD_PHP_LINT=NOT_RUN TEST_DATABASE=NOT_TOUCHED\n'
printf 'LIVE_SITE_WRITES=0 BANK_CALLS=0 MODEL_CALLS=0 MAIL_SENDS=0 MAX_SENDS=0\n'
