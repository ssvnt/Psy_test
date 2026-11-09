#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
php -l src/MailJourneyPolicy.php
php -l src/MailPreviewRenderer.php
php -l scripts/render_previews.php
php tests/test_policy.php
php tests/test_renderer.php
python3 -B -m unittest discover -s tests -p test_copy.py -v
php scripts/render_previews.php
printf 'RC3_OFFLINE_TESTS_PASS POLICY=40 RENDERER=11 COPY=6 SMTP=0 PAYMENTS=0 MODEL=0\n'
