<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** Only consented, newly completed native Gardner sessions; never includes private results. */
final class GardnerFreeOfferMail extends Mailable
{
    public function __construct(public string $accountUrl) {}

    public function build(): static
    {
        return $this->subject('Добрый Жук — результаты теста и возможности полного отчёта')
            ->view('emails.gardner-free-offer')
            ->text('emails.gardner-free-offer-text');
    }
}
