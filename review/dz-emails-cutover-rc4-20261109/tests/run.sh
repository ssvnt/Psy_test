#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd -P)"
cd "$ROOT"
for phpfile in $(find src laravel -type f -name '*.php' | sort); do php -l "$phpfile" >/dev/null; done
php tests/test_coordinator.php
php tests/test_hard_cases.php
python3 -B tests/test_source_contract.py
printf 'RC4_OFFLINE_INTEGRATION_CONTRACT_PASS NO_LIVE_MAIL NO_BANK NO_MODEL\n'
