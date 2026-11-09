from pathlib import Path
import json, re, hashlib
root=Path(__file__).resolve().parents[1]
copy=json.loads((root/'laravel/resources/mail-journeys/copy-v1.proposed.json').read_text('utf-8'))
assert set(copy)=={'gardner','teen'}
for prod,items in copy.items():
    assert set(items)=={'test_completed_offer','unpaid_12h','payment_confirmed','report_ready'}
    for event,data in items.items():
        assert data['subject'] and data['title'] and data['paragraphs']
        keys=[x['key'] for x in data['buttons']]
        assert keys==(['report_url','consultation_url'] if event=='report_ready' else ['account_url'])
        if event in {'test_completed_offer','unpaid_12h'}:
            assert '{{unsubscribe_url}}' in data['footer']
        else:
            assert '{{unsubscribe_url}}' not in data['footer']
        assert 'access_token' not in json.dumps(data,ensure_ascii=False)
assert (root/'laravel/config/dz_mail_journeys.php').read_text().count("env('DZ_MAIL_RC4_")>=10
for p in (root/'laravel/app').rglob('*.php'):
    s=p.read_text()
    assert "DB::raw('UPDATE" not in s
    assert 'curl ' not in s
ctx=(root/'laravel/app/Services/MailJourneys/LaravelJourneyContextProvider.php').read_text()
assert "'legacy_event_reserved'=>$legacy" in ctx
assert 'receipt->verification_proof_sha256' in ctx
assert "'qa_pass'=>$qa" in ctx
hooks=(root/'laravel/app/Services/MailJourneys/LaravelJourneyHooks.php').read_text()
assert "recordMarketingConsent" in hooks and "verifiedPayment" in hooks and "reportPublished" in hooks
policy=(root/'src/MailJourneyPolicy.php').read_text()
assert 'FOLLOWUP_DELAY_SECONDS = 43200' in policy
assert "'REPORT_NOT_PUBLISHED_OR_ACCESS_UNVERIFIED'" in policy
assert "'PAYMENT_OR_EXISTING_ORDER'" in policy
unsub=(root/'laravel/app/Http/Controllers/DzMailUnsubscribeController.php').read_text()
assert 'public function show' in unsub and 'public function confirm' in unsub
assert 'hasValidSignature' in unsub and 'revoked_at' in unsub
print('RC4_SOURCE_CONTRACT_PASS')
