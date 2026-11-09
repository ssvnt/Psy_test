<?php

declare(strict_types=1);
namespace App\Services\MailJourneys\Core;
interface JourneyMailer {
    public function send(string $recipient, array $rendered, string $eventKey): void;
}
