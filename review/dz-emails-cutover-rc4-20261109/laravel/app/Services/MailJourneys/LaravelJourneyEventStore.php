<?php

declare(strict_types=1);
namespace App\Services\MailJourneys;

use Carbon\CarbonImmutable;
use App\Services\MailJourneys\Core\JourneyEventStore;
use Illuminate\Support\Facades\DB;

/** DB outbox: one row per immutable event, with one-way SMTP outcome accounting. */
final class LaravelJourneyEventStore implements JourneyEventStore
{
    public function enqueueOnce(array $intent): bool
    {
        $key=(string)($intent['event_key']??'');
        if (!preg_match('/\A[a-f0-9]{64}\z/D',$key)
            || !in_array($intent['product']??null,['gardner','teen'],true)
            || !in_array($intent['event_kind']??null,['test_completed_offer','unpaid_12h','payment_confirmed','report_ready'],true)
            || !is_int($intent['scheduled_unix']??null) || $intent['scheduled_unix']<1
            || !is_string($intent['session_key']??null) || strlen($intent['session_key'])>128) {
            throw new \InvalidArgumentException('MAIL_INTENT_INVALID');
        }
        return DB::table('dz_mail_journey_events')->insertOrIgnore([
            'event_key'=>$key,'product'=>$intent['product'],'event_kind'=>$intent['event_kind'],
            'session_key_hash'=>hash('sha256',$intent['session_key']),
            // Public UUID only; no email/address/prompt/child answers in the event outbox.
            'session_key'=>$intent['session_key'],
            'scheduled_at'=>CarbonImmutable::createFromTimestampUTC($intent['scheduled_unix']),
            'status'=>'pending','attempts'=>0,'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
        ])===1;
    }
    public function claimDue(int $now): ?array
    {
        return DB::transaction(function () use($now): ?array {
            $row=DB::table('dz_mail_journey_events')->where('status','pending')
                ->where('scheduled_at','<=',CarbonImmutable::createFromTimestampUTC($now))
                ->orderBy('id')->lockForUpdate()->first();
            if (!$row)return null;
            $changed=DB::table('dz_mail_journey_events')->where('id',$row->id)->where('status','pending')->update([
                'status'=>'sending','attempts'=>$row->attempts+1,'send_started_at'=>now('UTC'),'updated_at'=>now('UTC'),
            ]);
            if ($changed!==1) return null;
            return ['event_key'=>$row->event_key,'product'=>$row->product,'event_kind'=>$row->event_kind,
                'session_key'=>$row->session_key,'scheduled_unix'=>CarbonImmutable::parse($row->scheduled_at)->timestamp,
                'attempts'=>(int)$row->attempts+1];
        },3);
    }
    public function mark(string $key,string $status,?int $nextAt=null): void
    {
        if (!in_array($status,['accepted_by_mailer','delivery_unknown','suppressed','hold','retry_local','owner_attention'],true)) {
            throw new \InvalidArgumentException('MAIL_RESULT_INVALID');
        }
        $updates=['status'=>$status==='retry_local'?'pending':$status,'updated_at'=>now('UTC')];
        if($status==='retry_local'){
            if ($nextAt===null||$nextAt<1)throw new \InvalidArgumentException('MAIL_RETRY_TIME_INVALID');
            $updates['scheduled_at']=CarbonImmutable::createFromTimestampUTC($nextAt);
        }
        if($status==='accepted_by_mailer')$updates['accepted_at']=now('UTC');
        if($status==='delivery_unknown')$updates['error_code']='smtp_outcome_unknown';
        $changed=DB::table('dz_mail_journey_events')->where('event_key',$key)->where('status','sending')->update($updates);
        if($changed!==1)throw new \RuntimeException('MAIL_EVENT_CAS_FAILED');
    }
}
