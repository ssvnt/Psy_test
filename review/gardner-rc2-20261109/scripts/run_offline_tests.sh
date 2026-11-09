#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd -P)"
cd "$ROOT"
export PYTHONPATH="$ROOT/src"
python3 -B -m unittest discover -s tests -v
python3 -B -m dz_rc2.simulator --quality-json "$ROOT/fixtures/QA_106_SYNTHETIC_181_51_FAIL.json"
php -l "$ROOT/php/App/Services/Children/V2/GardnerV22/Recovery/GardnerRc2StopPolicy.php"
php -l "$ROOT/php/App/Services/Children/V2/GardnerV22/Recovery/GardnerRc2QualityPlan.php"
php "$ROOT/tests/test_php_policy.php"
printf 'RC2_OFFLINE_QA_AND_RECOVERY_TESTS=PASS\n'
printf 'SITE_WRITES=0 MODEL_CALLS=0 PAYMENT_CALLS=0 MAIL_SENDS=0 MAX_SENDS=0\n'
