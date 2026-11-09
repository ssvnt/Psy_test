#!/usr/bin/env bash
# Read-only infra inventory: no .env, no customer records, no raw logs.
set -u
printf '%s\n' 'DZ_RC1_HOST_PREFLIGHT_READONLY=YES'
printf '\n== RUNTIMES ==\n'
for command in php python3 node npm; do
  if command -v "$command" >/dev/null 2>&1; then printf 'AVAILABLE=%s\n' "$command";
  else printf 'MISSING=%s\n' "$command"; fi
done
printf '\n== VOLUME AND INODES ==\n'
df -h /var/www/bribon /opt /root 2>/dev/null || true
df -i /var/www/bribon /opt /root 2>/dev/null || true
printf '\n== INSTALLED UNITS ==\n'
systemctl list-unit-files 'dz-gardner*' 'dz-r27*' '*queue*' --no-pager --no-legend 2>/dev/null | head -n 100 || true
printf '\n== SCHEDULED TIMERS ==\n'
systemctl list-timers --all --no-pager --no-legend 2>/dev/null | grep -Ei 'dz-gardner|dz-r27|laravel|queue' || true
printf '\n== CORE SERVICES ==\n'
for u in dz-gardner-r27-queue.service dz-gardner-r27-dispatch.service dz-gardner-r27-paid-bridge.service dz-gardner-r27-recovery.service dz-gardner-r27-finalize.service dz-gardner-r27-delivery.service dz-gardner-r27-max-progress.service; do
  printf '%s\n' "UNIT=$u"
  systemctl show "$u" --no-pager -p LoadState -p ActiveState -p SubState -p UnitFileState -p ExecMainStatus -p NRestarts 2>/dev/null || true
done
printf '\nSITE_WRITES=0 MODEL_CALLS=0 PAYMENT_CALLS=0 MAIL_SENDS=0 MAX_SENDS=0 SERVICE_RESTARTS=0\n'
