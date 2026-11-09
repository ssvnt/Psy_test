<?php

declare(strict_types=1);
namespace App\Services\MailJourneys;

use App\Mail\DzJourneyMail;
use App\Services\MailJourneys\Core\JourneyMailer;
use Illuminate\Support\Facades\Mail;

final class LaravelJourneyMailer implements JourneyMailer
{
    public function send(string $recipient,array $rendered,string $eventKey): void
    {
        if (config('dz_mail_journeys.enabled')!==true || config('dz_mail_journeys.send_enabled')!==true) {
            throw new \RuntimeException('MAIL_RC4_DISABLED');
        }
        $transport=(string)config('mail.default');
        if (!app()->environment('testing') && in_array($transport,['log','array','null',''],true)) {
            throw new \RuntimeException('MAIL_TRANSPORT_NOT_DELIVERABLE');
        }
        // Does not expose report content, child data or raw tokens in SMTP headers/logs.
        Mail::to($recipient)->send(new DzJourneyMail($rendered));
        // send() returning does NOT prove inbox delivery: caller records accepted_by_mailer only.
    }
}
