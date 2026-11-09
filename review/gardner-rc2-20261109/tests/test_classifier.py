import unittest
from dz_rc2.policy import classify,Evidence,Action

class ClassifierTests(unittest.TestCase):
    def test_quality_to_repair(self):
        d=classify(Evidence('quality_rejected'))
        self.assertEqual(d.action,Action.ENQUEUE_QA_FIELD_REPAIRS)
        self.assertFalse(d.publish_allowed)
    def test_local_fail_can_restart_only_local(self):
        d=classify(Evidence('queue_dispatch_failed',attempts_in_scope=1))
        self.assertEqual(d.action,Action.RETRY_LOCAL)
        self.assertFalse(d.new_billable_request_authorized)
    def test_proven_unsent_can_retry(self):
        d=classify(Evidence('provider_transport_failed',request_not_sent_proven=True))
        self.assertEqual(d.action,Action.RETRY_PROVEN_FREE)
        self.assertFalse(d.new_billable_request_authorized)
    def test_transport_after_send_is_unknown(self):
        self.assertEqual(classify(Evidence('provider_transport_failed')).action,Action.RECONCILE_UNKNOWN_BILLING)
    def test_stream_incomplete_is_unknown(self):
        self.assertEqual(classify(Evidence('provider_stream_incomplete')).action,Action.RECONCILE_UNKNOWN_BILLING)
    def test_proven_response_replayed_without_network(self):
        self.assertEqual(classify(Evidence('provider_transport_failed',verified_saved_response=True)).action,Action.REPLAY_VERIFIED_RECEIPT)
    def test_http_429_not_assumed_free(self):
        self.assertEqual(classify(Evidence('provider_http_429')).action,Action.RECONCILE_UNKNOWN_BILLING)
    def test_http_429_verified_no_charge(self):
        self.assertEqual(classify(Evidence('provider_http_429',provider_no_charge_proven=True)).action,Action.RETRY_PROVEN_FREE)
    def test_http_429_limit(self):
        self.assertEqual(classify(Evidence('provider_http_429',provider_no_charge_proven=True,attempts_in_scope=12)).action,Action.OWNER_ATTENTION)
    def test_budget_is_hard(self):
        for code in ('report_request_budget_exhausted','report_cost_budget_exhausted','report_token_budget_exhausted'):
            with self.subTest(code):
                d=classify(Evidence(code,retry_budget_authorized=True,remaining_cost_micro_rub=10**12))
                self.assertEqual(d.action,Action.FALLBACK_OR_OWNER)
                self.assertFalse(d.new_billable_request_authorized)
    def test_lease_unknown(self):
        self.assertEqual(classify(Evidence('generation_lease_lost',ambiguous_attempt_exists=True)).action,Action.RECONCILE_UNKNOWN_BILLING)
    def test_lease_unambiguous(self):
        self.assertEqual(classify(Evidence('generation_lease_lost')).action,Action.RESTORE_EXPIRED_LEASE)
    def test_active_lease_prevents_double_worker(self):
        self.assertEqual(classify(Evidence('generation_lease_lost',active_lease=True)).action,Action.WAIT_EXTERNAL_SERVICE)
    def test_integrity_never_retry_billed(self):
        self.assertEqual(classify(Evidence('checkpoint_integrity')).action,Action.OWNER_ATTENTION)
    def test_quality_scanner_down_pauses_qa_not_generation(self):
        d=classify(Evidence('quality_unavailable'))
        self.assertEqual(d.action,Action.WAIT_EXTERNAL_SERVICE)
        self.assertEqual(d.scope,'qa')
    def test_config_error_owner(self):
        self.assertEqual(classify(Evidence('provider_http_401')).action,Action.OWNER_ATTENTION)
    def test_content_schema_addressable(self):
        self.assertEqual(classify(Evidence('r27_schema_retry_exhausted')).action,Action.REPAIR_SINGLE_FIELD)
    def test_unknown_code_owner_attention(self):
        self.assertEqual(classify(Evidence('not-from-real-contract')).reason,'UNKNOWN_STOP_CODE')
    def test_timeout_bound(self):
        self.assertEqual(classify(Evidence('queue_dispatch_failed',wait_seconds=100000)).retry_after_seconds,3600)
    def test_no_charges_from_any_decision(self):
        sample=['provider_http_429','provider_transport_failed','quality_rejected','report_cost_budget_exhausted',
                'generation_lease_lost','quality_unavailable','queue_dispatch_failed','checkpoint_integrity']
        self.assertFalse(any(classify(Evidence(s)).new_billable_request_authorized for s in sample))
