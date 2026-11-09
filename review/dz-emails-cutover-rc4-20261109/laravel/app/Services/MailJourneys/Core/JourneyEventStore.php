<?php

declare(strict_types=1);
namespace App\Services\MailJourneys\Core;
interface JourneyEventStore {
    public function enqueueOnce(array $intent): bool;
    public function claimDue(int $now): ?array;
    public function mark(string $key, string $status, ?int $nextAt = null): void;
}
