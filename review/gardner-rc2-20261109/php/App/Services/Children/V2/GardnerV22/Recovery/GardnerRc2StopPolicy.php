<?php

declare(strict_types=1);

namespace App\Services\Children\V2\GardnerV22\Recovery;

/** Pure policy. No transport, payment, database, model or notification side effects.
 * Staging-only reference: integrate with existing AttemptLedger and queues separately.
 */
final class GardnerRc2StopPolicy
{
    private const BUDGET = ['report_request_budget_exhausted','report_token_budget_exhausted',
        'report_cost_budget_exhausted','R27_NEW_RUN_BUDGET_EXCEEDED','GLOBAL_ALL_RUNS_BUDGET_EXCEEDED',
        'LIVE_COST_OR_TOKEN_LIMIT_EXCEEDED','PER_ORDER_BUDGET_OR_TOKEN_LIMIT_EXCEEDED','EDITOR_PAID_ATTEMPT_LIMIT'];
    private const QA = ['quality_rejected','EDITOR_PATCH_REJECTED','EDITOR_PATCH_VALIDATION_FAILED','editorial_quality_gate_failed'];
    private const SCANNER = ['quality_unavailable','EDITOR_REQUIRED_AUDITORS_NOT_AVAILABLE'];
    private const INTEGRITY = ['quality_source_integrity','checkpoint_integrity','checkpoint_source_receipt_missing',
        'session_inputs_changed','saved_provider_evidence_invalid','attempt_receipt_corrupt',
        'source_identity_changed','generation_identity_invalid','PROVIDER_RECEIPT_INVALID'];
    private const LOCAL = ['queue_dispatch_failed','LOCAL_RESOURCE_TEMPORARILY_UNAVAILABLE',
        'SQLITE_BUSY_OR_LOCKED','LOCAL_HELPER_TIMEOUT','LOCAL_STORAGE_FULL',
        'PDF_PREFLIGHT_FAILED','PDF_QA_FAILED','CHILD_PROCESS_GROUP_NOT_QUIESCENT'];
    private const CONFIG = ['pipeline_disabled','payment_not_confirmed','cloudru_credentials_unavailable',
        'provider_credentials_missing','provider_http_401','provider_http_403',
        'spending_policy_not_configured','spending_policy_invalid','GARDNER_R27_DISPATCH_BINDING_NOT_VERIFIED'];
    private const AMBIGUOUS = ['provider_transport_failed','provider_stream_incomplete','provider_outcome_unknown',
        'billing_reconciliation_required','provider_invalid_json','provider_incomplete_reply',
        'PROVIDER_TOTAL_DEADLINE_EXCEEDED','EDITOR_BILLING_UNKNOWN','EDITOR_BILLING_RECONCILIATION_REQUIRED',
        'provider_reply_too_large','provider_http_429','provider_http_500','provider_http_502',
        'provider_http_503','provider_http_504'];

    /** @return array{action:string,scope:string,reason:string,retry_after_seconds:int,
     *                new_billable_request_authorized:bool,publish_allowed:bool} */
    public static function classify(string $code, array $proof = []): array
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 120) return self::response('OWNER_ATTENTION','order','UNKNOWN_STOP_CODE');
        if (in_array($code,self::QA,true)) return self::response('ENQUEUE_QA_FIELD_REPAIRS','qa',$code);
        if (in_array($code,self::BUDGET,true)) return self::response('FALLBACK_OR_OWNER','field',$code);
        if (in_array($code,self::SCANNER,true)) return self::response('WAIT_EXTERNAL_SERVICE','qa',$code,300);
        if (in_array($code,self::INTEGRITY,true)) return self::response('OWNER_ATTENTION','field_or_order',$code);
        if (in_array($code,self::CONFIG,true)) return self::response('OWNER_ATTENTION','order',$code);
        if (in_array($code,['generation_lease_lost','EXPIRED_LEASE_NO_AMBIGUOUS_BILLING'],true)) {
            if (($proof['active_lease']??false) === true) return self::response('WAIT_EXTERNAL_SERVICE','field','ACTIVE_LEASE',60);
            if (($proof['ambiguous_attempt_exists']??false) === true) return self::response('RECONCILE_UNKNOWN_BILLING','field',$code);
            return self::response('RESTORE_EXPIRED_LEASE','field',$code);
        }
        $attempts = max(0, (int) ($proof['attempts_in_scope']??0));
        $max = max(1, (int) ($proof['maximum_free_attempts']??12));
        $wait = max(30,min(3600,(int) ($proof['wait_seconds']??60)));
        if (in_array($code,self::LOCAL,true)) {
            if ($attempts >= $max) return self::response('OWNER_ATTENTION','field_or_order','LOCAL_RETRY_LIMIT_REACHED');
            return self::response('RETRY_LOCAL','field_or_order',$code,$wait);
        }
        if (in_array($code,self::AMBIGUOUS,true) || str_starts_with($code,'provider_http_')) {
            if (($proof['verified_saved_response']??false) === true) return self::response('REPLAY_VERIFIED_RECEIPT','field',$code);
            if (($proof['request_not_sent_proven']??false) === true || ($proof['provider_no_charge_proven']??false) === true) {
                if ($attempts >= $max) return self::response('OWNER_ATTENTION','field','PROVEN_FREE_RETRY_LIMIT_REACHED');
                return self::response('RETRY_PROVEN_FREE','field',$code,$wait);
            }
            return self::response('RECONCILE_UNKNOWN_BILLING','field',$code);
        }
        if (in_array($code,['r27_schema_retry_exhausted','single_retry_exhausted','partial_chunks_owner_review_required'],true))
            return self::response('REPAIR_SINGLE_FIELD','field',$code);
        return self::response('OWNER_ATTENTION','order','UNKNOWN_STOP_CODE');
    }

    private static function response(string $action,string $scope,string $reason,int $wait=0): array
    {
        return ['action'=>$action,'scope'=>$scope,'reason'=>$reason,'retry_after_seconds'=>$wait,
            'new_billable_request_authorized'=>false,'publish_allowed'=>false];
    }
}
