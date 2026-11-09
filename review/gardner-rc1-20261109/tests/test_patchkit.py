#!/usr/bin/env python3
"""Offline structural tests for the RC1 patch specifications.
No production source, database, queue, model or network is accessed.
This is NOT a substitute for PHPUnit, Vite, browser, mail or MAX integration tests.
"""
from __future__ import annotations
import sys
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'builder'))
from spec import TARGETS
from stage_candidate import apply_one


class CandidateGuardTests(unittest.TestCase):
    def test_unique_paths_and_hash_pins(self):
        seen=set()
        for item in TARGETS:
            self.assertNotIn(item.relative,seen)
            self.assertRegex(item.sha256,r'^[a-f0-9]{64}$')
            self.assertFalse(item.relative.startswith('/'))
            self.assertNotIn('..',item.relative)
            seen.add(item.relative)
        self.assertEqual(len(seen),13)

    def test_every_individual_anchor_is_exact_and_changes_content(self):
        for item in TARGETS:
            for change in item.ops:
                with self.subTest(item=item.relative,label=change.label):
                    self.assertTrue(change.before)
                    self.assertNotEqual(change.before,change.after)
                    source=change.before * change.count
                    self.assertEqual(source.count(change.before),change.count)
                    patched,events=apply_one(source,[change],item.relative)
                    self.assertNotEqual(source,patched)
                    self.assertEqual(events[0]['occurrences'],change.count)

    def test_anchor_mismatch_fails_closed(self):
        for item in TARGETS:
            with self.subTest(item=item.relative):
                with self.assertRaises(RuntimeError):
                    apply_one('CHANGED_BASELINE_NONE_OF_THE_ANCHORS',item.ops,item.relative)

    def test_no_production_deployer(self):
        builder=(ROOT/'builder/stage_candidate.py').read_text()
        self.assertIn('STAGING_MUST_NOT_BE_INSIDE_LIVE_PROJECT',builder)
        self.assertIn('STAGING_DESTINATION_EXISTS_NO_OVERWRITE',builder)
        self.assertIn('BASELINE_SHA256_MISMATCH',builder)
        for marker in ('artisan migrate','systemctl restart','--send','curl -X POST', 'queue:work'):
            self.assertNotIn(marker,builder)

    def test_immutable_answers_before_existing_upsert(self):
        ops={x.label:x.after for t in TARGETS for x in t.ops}
        self.assertIn("$session->status === 'completed'",ops['immutable_completed_answers'])
        self.assertIn("$row->value",ops['immutable_completed_answers'])
        self.assertIn("return $session;",ops['immutable_completed_answers'])
        self.assertIn("$session->payment_status === 'paid'",ops['immutable_completed_answers'])

    def test_email_guard_and_no_token_in_mail_templates(self):
        ops={x.label:x.after for t in TARGETS for x in t.ops}
        body=ops['free_offer_and_verified_registered_buyer']
        for term in ("free_offer_mail_enabled","allow_communications","status !== 'completed'",
                     'registered_email_service_notice_enabled','operation_id'):
            self.assertIn(term,body)
        for f in (ROOT/'additions/resources/views/emails').glob('*'):
            text=f.read_text()
            self.assertIn('$accountUrl',text)
            self.assertNotIn('access_token',text)
            self.assertNotIn('child_name',text)

    def test_r27_qa_no_bypass(self):
        ops={x.label:x.after for t in TARGETS for x in t.ops}
        self.assertIn("if($decision==='APPROVED' && $hasWarnings)abort(422",ops['owner_approval_http_gate'])
        self.assertIn("if qa_data['blocked']>0",ops['r27_stage_requires_clean_qa'])
        self.assertIn('$acceptedWarnings=$warnings===0;',ops['r27_send_requires_clean_qa'])

    def test_independent_max_and_mail_recovery(self):
        ops={x.label:x.after for t in TARGETS for x in t.ops}
        self.assertIn('try { $outbox->ready($session); }',ops['paid_max_retry_and_order_isolation'])
        self.assertIn('notifyPaymentAccepted',ops['paid_max_retry_and_order_isolation'])
        self.assertIn('try { $outbox->deliver($row->id); }',ops['isolate_mail_outbox_delivery_errors'])
        self.assertIn('MAX_API_HTTP_',ops['max_http_body_redaction'])
        self.assertNotIn('Str::limit($raw',ops['max_http_body_redaction'])

    def test_r27_link_is_strict_and_feature_gated(self):
        link=(ROOT/'additions/app/Services/GardnerCommerce/R27AccountLink.php').read_text()
        for phrase in ("r27_account_link_enabled","app(Orders::class)->paid", "'APPROVED'",
                       "'blocked'", 'hash_file', 'getByName', 'hash_equals', "'token_sha256'"):
            self.assertIn(phrase,link)
        self.assertNotIn('DB::table',link)
        self.assertNotIn('Mail::',link)

    def test_no_historic_max_spam_or_free_offer_backfill_starvation(self):
        ops={x.label:x.after for t in TARGETS for x in t.ops}
        free=ops['reconcile_free_completed_with_bounded_limit']
        self.assertIn("leftJoin('gardner_orders as o'",free)
        self.assertIn("whereNull('o.id')",free)
        paid=ops['paid_max_retry_and_order_isolation']
        self.assertIn("owner_paid_max_reconcile_enabled",paid)
        self.assertIn("owner_paid_max_reconcile_from",paid)
        self.assertIn("$session->created_at->gte",paid)

    def test_default_gates_are_off(self):
        operations={x.label:x.after for t in TARGETS for x in t.ops}
        for flag in ('GARDNER_FREE_OFFER_MAIL_ENABLED','GARDNER_REGISTERED_EMAIL_SERVICE_NOTICE_ENABLED',
                     'GARDNER_R27_ACCOUNT_LINK_ENABLED',
                     'GARDNER_OWNER_PAID_MAX_RECONCILE_ENABLED'):
            self.assertIn("env('"+flag+"', false)",operations['release_gates'])


if __name__=='__main__':unittest.main(verbosity=2)
