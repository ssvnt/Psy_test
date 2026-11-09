"""Explainable, bounded failure classifier for the Gardner RC2 staging prototype.

This is a *decision projection*, not a request sender. In particular, it never
claims that an unknown bill is zero or authorizes a second paid request.
"""
from __future__ import annotations
from dataclasses import dataclass
from enum import Enum

class Action(str, Enum):
    CONTINUE_INDEPENDENT = 'CONTINUE_INDEPENDENT'
    ENQUEUE_QA_FIELD_REPAIRS = 'ENQUEUE_QA_FIELD_REPAIRS'
    RETRY_LOCAL = 'RETRY_LOCAL'
    RETRY_PROVEN_FREE = 'RETRY_PROVEN_FREE'
    REPLAY_VERIFIED_RECEIPT = 'REPLAY_VERIFIED_RECEIPT'
    RESTORE_EXPIRED_LEASE = 'RESTORE_EXPIRED_LEASE'
    RECONCILE_UNKNOWN_BILLING = 'RECONCILE_UNKNOWN_BILLING'
    FALLBACK_OR_OWNER = 'FALLBACK_OR_OWNER'
    WAIT_EXTERNAL_SERVICE = 'WAIT_EXTERNAL_SERVICE'
    OWNER_ATTENTION = 'OWNER_ATTENTION'
    REPAIR_SINGLE_FIELD = 'REPAIR_SINGLE_FIELD'

@dataclass(frozen=True)
class Evidence:
    code: str
    phase: str = 'generation'
    request_not_sent_proven: bool = False
    provider_no_charge_proven: bool = False
    verified_saved_response: bool = False
    active_lease: bool = False
    ambiguous_attempt_exists: bool = False
    attempts_in_scope: int = 0
    maximum_free_attempts: int = 12
    wait_seconds: int = 60
    retry_budget_authorized: bool = False
    remaining_cost_micro_rub: int = 0
    all_chunks_checkpointed: bool = False

@dataclass(frozen=True)
class Decision:
    action: Action
    scope: str
    reason: str
    retry_after_seconds: int = 0
    new_billable_request_authorized: bool = False
    publish_allowed: bool = False

RETRYABLE_HTTP = {"provider_http_429", "provider_http_500", "provider_http_502", "provider_http_503", "provider_http_504"}
AMBIGUOUS = {
    'provider_transport_failed', 'provider_stream_incomplete', 'provider_outcome_unknown',
    'billing_reconciliation_required', 'provider_invalid_json', 'provider_incomplete_reply',
    'PROVIDER_TOTAL_DEADLINE_EXCEEDED', 'EDITOR_BILLING_UNKNOWN',
    'EDITOR_BILLING_RECONCILIATION_REQUIRED', 'provider_reply_too_large',
} | RETRYABLE_HTTP
HARD_BUDGET = {
    'report_request_budget_exhausted', 'report_token_budget_exhausted',
    'report_cost_budget_exhausted', 'R27_NEW_RUN_BUDGET_EXCEEDED',
    'GLOBAL_ALL_RUNS_BUDGET_EXCEEDED', 'LIVE_COST_OR_TOKEN_LIMIT_EXCEEDED',
    'PER_ORDER_BUDGET_OR_TOKEN_LIMIT_EXCEEDED', 'EDITOR_PAID_ATTEMPT_LIMIT',
}
QA_CODES = {'quality_rejected','EDITOR_PATCH_REJECTED','EDITOR_PATCH_VALIDATION_FAILED', 'editorial_quality_gate_failed'}
SCANNER_CIRCUIT = {'quality_unavailable','EDITOR_REQUIRED_AUDITORS_NOT_AVAILABLE'}
INTEGRITY_CODES = {'quality_source_integrity','checkpoint_integrity','checkpoint_source_receipt_missing',
    'session_inputs_changed','saved_provider_evidence_invalid','attempt_receipt_corrupt',
    'source_identity_changed','generation_identity_invalid','PROVIDER_RECEIPT_INVALID'}
LOCAL_FAILURES = {'queue_dispatch_failed','LOCAL_RESOURCE_TEMPORARILY_UNAVAILABLE',
                  'SQLITE_BUSY_OR_LOCKED','LOCAL_HELPER_TIMEOUT','LOCAL_STORAGE_FULL',
                  'PDF_PREFLIGHT_FAILED', 'PDF_QA_FAILED', 'CHILD_PROCESS_GROUP_NOT_QUIESCENT'}
CONFIG_CODES = {'pipeline_disabled','payment_not_confirmed','cloudru_credentials_unavailable',
                'provider_credentials_missing','provider_http_401','provider_http_403',
                'spending_policy_not_configured','spending_policy_invalid',
                'GARDNER_R27_DISPATCH_BINDING_NOT_VERIFIED'}

def classify(e: Evidence) -> Decision:
    c=e.code.strip()
    if not c or len(c)>120:
        return Decision(Action.OWNER_ATTENTION, 'order', 'UNKNOWN_STOP_CODE')
    if c in QA_CODES:
        return Decision(Action.ENQUEUE_QA_FIELD_REPAIRS, 'qa', c)
    if c in HARD_BUDGET:
        return Decision(Action.FALLBACK_OR_OWNER, 'field', c)
    if c in SCANNER_CIRCUIT:
        return Decision(Action.WAIT_EXTERNAL_SERVICE, 'qa', c, retry_after_seconds=300)
    if c in INTEGRITY_CODES:
        return Decision(Action.OWNER_ATTENTION, 'field_or_order', c)
    if c in CONFIG_CODES:
        return Decision(Action.OWNER_ATTENTION, 'order', c)
    if c in {'generation_lease_lost','EXPIRED_LEASE_NO_AMBIGUOUS_BILLING'}:
        if e.active_lease:
            return Decision(Action.WAIT_EXTERNAL_SERVICE, 'field', 'ACTIVE_LEASE', 60)
        if e.ambiguous_attempt_exists:
            return Decision(Action.RECONCILE_UNKNOWN_BILLING, 'field', c)
        return Decision(Action.RESTORE_EXPIRED_LEASE, 'field', c)
    if c in LOCAL_FAILURES:
        if e.attempts_in_scope >= e.maximum_free_attempts:
            return Decision(Action.OWNER_ATTENTION, 'field_or_order', 'LOCAL_RETRY_LIMIT_REACHED')
        return Decision(Action.RETRY_LOCAL, 'field_or_order', c, max(30,min(e.wait_seconds,3600)))
    if c in AMBIGUOUS or c.startswith('provider_http_'):
        if e.verified_saved_response:
            return Decision(Action.REPLAY_VERIFIED_RECEIPT, 'field', c)
        if e.request_not_sent_proven or e.provider_no_charge_proven:
            if e.attempts_in_scope >= e.maximum_free_attempts:
                return Decision(Action.OWNER_ATTENTION, 'field', 'PROVEN_FREE_RETRY_LIMIT_REACHED')
            return Decision(Action.RETRY_PROVEN_FREE, 'field', c, max(30,min(e.wait_seconds,3600)))
        return Decision(Action.RECONCILE_UNKNOWN_BILLING, 'field', c)
    if c in {'r27_schema_retry_exhausted','single_retry_exhausted','partial_chunks_owner_review_required'}:
        return Decision(Action.REPAIR_SINGLE_FIELD, 'field', c)
    return Decision(Action.OWNER_ATTENTION, 'order', 'UNKNOWN_STOP_CODE')
