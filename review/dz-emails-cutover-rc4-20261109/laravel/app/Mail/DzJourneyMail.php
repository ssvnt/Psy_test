<?php

declare(strict_types=1);
namespace App\Mail;

use Illuminate\Mail\Mailable;

/** Exactly the reviewed HTML/TXT renderer output, without report attachments. */
final class DzJourneyMail extends Mailable
{
    public function __construct(public readonly array $rendered) {}
    public function build(): static
    {
        if (!isset($this->rendered['subject'],$this->rendered['html'],$this->rendered['text'])) {
            throw new \InvalidArgumentException('EMAIL_RENDER_NOT_READY');
        }
        return $this->subject($this->rendered['subject'])
            ->view('emails.dz-journey-html',['body'=>$this->rendered['html']])
            ->text('emails.dz-journey-plaintext',['body'=>$this->rendered['text']]);
    }
}
