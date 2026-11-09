<?php

declare(strict_types=1);

namespace App\Services\MailJourneys\Core;

/** Side-effect-free lifecycle gate. Not connected to Laravel or SMTP. */
final class MailJourneyPolicy
{
    public const PRODUCTS = ['gardner', 'teen'];
    public const EVENTS = ['test_completed_offer', 'unpaid_12h', 'payment_confirmed', 'report_ready'];
    public const FOLLOWUP_DELAY_SECONDS = 43200;

    /**
     * Required state must originate from the authenticated, server-side product adapter,
     * never from user input. Integration must re-evaluate at dispatch AND send time.
     * No PII is logged or returned.
     *
     * @return array{eligible: bool, reason: string, event_key: string|null, not_before: int|null, copy_variant: string|null}
     */
    public static function evaluate(string $product, string $event, array $s, int $now): array
    {
        if (!in_array($product, self::PRODUCTS, true) || !in_array($event, self::EVENTS, true)) {
            return self::deny('UNKNOWN_PRODUCT_OR_EVENT');
        }
        $key = $s['session_key'] ?? null;
        if (!is_string($key) || !preg_match('/\A[a-zA-Z0-9._:-]{8,128}\z/D', $key)) {
            return self::deny('INVALID_SESSION_BINDING');
        }
        if ($now < 1) return self::deny('INVALID_CLOCK');
        if (($s['recipient_binding_verified'] ?? false) !== true || ($s['recipient_suppressed'] ?? false) === true) {
            return self::deny('RECIPIENT_UNVERIFIED_OR_SUPPRESSED');
        }
        $order = $s['order_status'] ?? 'unknown';
        if (!in_array($order, ['none', 'creating', 'creation_unknown', 'pending', 'paid', 'refunded', 'cancelled', 'unknown'], true)) {
            return self::deny('ORDER_STATE_INVALID');
        }
        if (in_array($event, ['test_completed_offer', 'unpaid_12h'], true)) {
            if (($s['completed'] ?? false) !== true) return self::deny('TEST_NOT_COMPLETED');
            if (($s['marketing_consent'] ?? false) !== true || ($s['unsubscribed'] ?? false) === true) {
                return self::deny('NO_MARKETING_CONSENT');
            }
            if (($s['new_session_after_rollout'] ?? false) !== true) return self::deny('HISTORIC_SESSION_SUPPRESSED');
            // In-flight/unknown bank operation is never treated as an unpaid customer.
            if ($order !== 'none' || ($s['ever_paid'] ?? false) === true || ($s['bank_outcome_unknown'] ?? false) === true) {
                return self::deny('PAYMENT_OR_EXISTING_ORDER');
            }
            $completedAt = $s['completed_unix'] ?? null;
            if (!is_int($completedAt) || $completedAt <= 0 || $completedAt > $now) return self::deny('COMPLETION_CLOCK_INVALID');
            $due = $completedAt + ($event === 'unpaid_12h' ? self::FOLLOWUP_DELAY_SECONDS : 0);
            if ($due > $now) return self::deny('NOT_DUE', $due);
            return self::allow($product, $event, $key, null, $due);
        }

        if ($order !== 'paid' || ($s['bank_verified'] ?? false) !== true
            || ($s['bank_amount_matches'] ?? false) !== true || ($s['currency_rub'] ?? false) !== true
            || ($s['order_session_binding_verified'] ?? false) !== true
            || ($s['ever_refunded'] ?? false) === true) {
            return self::deny('PAYMENT_NOT_VERIFIED');
        }
        if ($event === 'payment_confirmed') {
            // Service payment acknowledgment must NOT depend on generator readiness.
            // A 24h promise is only used when the owner's real service SLA is approved.
            $v = (($s['report_24h_sla_approved'] ?? false) === true) ? '24h' : 'status_only';
            $orderVersion = $s['order_version'] ?? 'initial_order';
            if (!is_string($orderVersion) || !preg_match('/\A[a-zA-Z0-9._:-]{2,128}\z/D',$orderVersion)) return self::deny('ORDER_VERSION_INVALID');
            return self::allow($product, $event, $key, $orderVersion, $now, $v);
        }
        if (($s['qa_pass'] ?? false) !== true || ($s['owner_approved'] ?? false) !== true
            || ($s['published'] ?? false) !== true || ($s['html_sha_verified'] ?? false) !== true
            || ($s['pdf_sha_verified'] ?? false) !== true || ($s['buyer_access_verified'] ?? false) !== true
            || ($s['report_url_available'] ?? false) !== true || ($s['consultation_url_available'] ?? false) !== true) {
            return self::deny('REPORT_NOT_PUBLISHED_OR_ACCESS_UNVERIFIED');
        }
        $version = $s['report_version'] ?? null;
        if (!is_string($version) || !preg_match('/\A[a-zA-Z0-9._:-]{6,128}\z/D', $version)) {
            return self::deny('REPORT_VERSION_UNVERIFIED');
        }
        return self::allow($product, $event, $key, $version, $now);
    }

    private static function deny(string $reason, ?int $notBefore = null): array
    {
        return ['eligible' => false, 'reason' => $reason, 'event_key' => null, 'not_before' => $notBefore, 'copy_variant'=>null];
    }

    private static function allow(string $product, string $event, string $key, ?string $version, int $notBefore, ?string $variant = null): array
    {
        $id = hash('sha256', 'dz-email-rc3-v1|'.$product.'|'.$event.'|'.$key.'|'.($version ?? 'once'));
        return ['eligible' => true, 'reason' => 'ELIGIBLE', 'event_key' => $id, 'not_before' => $notBefore, 'copy_variant'=>$variant];
    }
}
