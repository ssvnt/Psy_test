<?php

declare(strict_types=1);
namespace App\Services\MailJourneys\Core;

/** The caller MUST retrieve state from the server database, never from browser input. */
interface JourneyContextProvider
{
    /** Return a current fact snapshot, recipient_email and URLs for this server-owned session. */
    public function snapshot(string $product, string $sessionKey): array;
}

interface JourneyEventStore
{
    /** Intent is stored with a unique event key; no SMTP/network call. */
    public function enqueueOnce(array $intent): bool;
    /** Atomically claim one due, pending event. A crashed sender leaves "sending" for review. */
    public function claimDue(int $now): ?array;
    public function mark(string $key, string $status, ?int $nextAt = null): void;
}

interface JourneyMailer
{
    /** Must return only after successful mailer acceptance; never claim inbox delivery. */
    public function send(string $recipient, array $rendered, string $eventKey): void;
}

/** Transaction-neutral state machine. No legacy sender is called from here. */
final class MailJourneyCoordinator
{
    private const EVENTS = ['test_completed_offer','unpaid_12h','payment_confirmed','report_ready'];
    private const MARKETING = ['test_completed_offer','unpaid_12h'];

    /** @param array<string,array<string,bool>> $enabled Feature flags, all OFF unless explicitly specified. */
    public function __construct(
        private readonly JourneyContextProvider $context,
        private readonly JourneyEventStore $events,
        private readonly JourneyMailer $mailer,
        private readonly array $copy,
        private readonly string $expectedHost,
        private readonly array $enabled = [],
    ) {}

    public function schedule(string $product, string $event, string $sessionKey, int $now): string
    {
        if (!in_array($product, MailJourneyPolicy::PRODUCTS, true) || !in_array($event, self::EVENTS, true)) {
            return 'UNKNOWN_EVENT';
        }
        if (($this->enabled[$product][$event] ?? false) !== true) return 'FEATURE_DISABLED';
        $s = $this->context->snapshot($product, $sessionKey);
        if (($s['session_key'] ?? null) !== $sessionKey) return 'SESSION_BINDING_MISMATCH';
        if (($s['legacy_event_reserved'][$event] ?? false) === true) return 'LEGACY_SENDER_OWNS_EVENT';
        $due = $now;
        if ($event === 'unpaid_12h') {
            $completed = $s['completed_unix'] ?? null;
            if (!is_int($completed) || $completed <= 0) return 'COMPLETION_TIME_UNVERIFIED';
            $due = $completed + MailJourneyPolicy::FOLLOWUP_DELAY_SECONDS;
        }
        // Evaluate at the due time to allow a pending 12h event, then re-evaluate at SEND time.
        if (!in_array($event,self::MARKETING,true) && ($s['service_recipient_binding_verified'] ?? false)!==true) {
            return 'SERVICE_RECIPIENT_UNVERIFIED';
        }
        $evaluation = MailJourneyPolicy::evaluate($product,$event,$s,max($now,$due));
        if (!$evaluation['eligible']) return 'BLOCKED_'.$evaluation['reason'];
        $key = $evaluation['event_key'];
        if (!is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/D',$key)) return 'BAD_EVENT_KEY';
        return $this->events->enqueueOnce([
            'event_key'=>$key,'product'=>$product,'event_kind'=>$event,
            'session_key'=>$sessionKey,'scheduled_unix'=>$due,
        ]) ? 'SCHEDULED' : 'ALREADY_SCHEDULED';
    }

    /** Delivers at most one event. Suitable for a bounded external tick. */
    public function dispatchOne(int $now): string
    {
        $row = $this->events->claimDue($now);
        if ($row === null) return 'EMPTY';
        $key = $row['event_key'];
        $product = $row['product'];
        $event = $row['event_kind'];
        try {
            if (($this->enabled[$product][$event] ?? false) !== true) {
                $this->events->mark($key,'hold'); return 'FLAG_OFF_HOLD';
            }
            $s = $this->context->snapshot($product,$row['session_key']);
            if (($s['legacy_event_reserved'][$event] ?? false) === true) {
                $this->events->mark($key,'suppressed'); return 'LEGACY_EVENT_SUPPRESSED';
            }
            if (($s['session_key'] ?? null) !== $row['session_key']) {
                $this->events->mark($key,'suppressed'); return 'BINDING_SUPPRESSED';
            }
            if (!in_array($event,self::MARKETING,true) && ($s['service_recipient_binding_verified'] ?? false)!==true) {
                $this->events->mark($key,'suppressed'); return 'SERVICE_RECIPIENT_SUPPRESSED';
            }
            $gate = MailJourneyPolicy::evaluate($product,$event,$s,$now);
            if (!$gate['eligible'] || $gate['event_key'] !== $key) {
                if (in_array($gate['reason'],['PAYMENT_NOT_VERIFIED','REPORT_NOT_PUBLISHED_OR_ACCESS_UNVERIFIED'],true)) {
                    $this->postponeOrAlert($row,$now); return 'VERIFY_LATER';
                }
                $this->events->mark($key,'suppressed'); return 'SUPPRESSED_'.$gate['reason'];
            }
            $email = $s['recipient_email'] ?? null;
            if (!is_string($email) || !filter_var($email,FILTER_VALIDATE_EMAIL)) {
                $this->postponeOrAlert($row,$now); return 'RECIPIENT_HOLD';
            }
            $template = $this->copy[$product][$event] ?? null;
            if (!is_array($template)) {
                $this->events->mark($key,'hold'); return 'MISSING_TEMPLATE';
            }
            if ($event === 'payment_confirmed' && $gate['copy_variant'] === 'status_only') {
                if (!is_array($template['status_only_paragraphs'] ?? null)) {
                    $this->events->mark($key,'hold'); return 'MISSING_STATUS_COPY';
                }
                $template['paragraphs'] = $template['status_only_paragraphs'];
            }
            $urls = $s['urls'] ?? [];
            if (!is_array($urls)) $urls = [];
            $rendered = MailPreviewRenderer::render($template,$urls,$this->expectedHost);
        } catch (\Throwable) {
            // A pre-send error does NOT create an SMTP unknown; hold for review.
            $this->postponeOrAlert($row,$now);
            return 'PRE_SEND_RETRY_OR_ATTENTION';
        }
        try {
            $this->mailer->send($email,$rendered,$key);
            $this->events->mark($key,'accepted_by_mailer');
            return 'ACCEPTED_BY_MAILER';
        } catch (\Throwable) {
            // SMTP exceptions after a TCP write have an uncertain outcome. Never auto-resend.
            $this->events->mark($key,'delivery_unknown');
            return 'DELIVERY_UNKNOWN_NO_AUTO_RETRY';
        }
    }
    private function postponeOrAlert(array $claimed, int $now): void
    {
        if (($claimed['attempts'] ?? 1)>=6) {
            $this->events->mark($claimed['event_key'],'owner_attention');
        } else {
            $this->events->mark($claimed['event_key'],'retry_local',$now+3600);
        }
    }

}
