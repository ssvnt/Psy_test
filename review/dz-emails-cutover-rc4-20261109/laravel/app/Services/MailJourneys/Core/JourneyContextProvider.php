<?php

declare(strict_types=1);
namespace App\Services\MailJourneys\Core;
interface JourneyContextProvider {
    public function snapshot(string $product, string $sessionKey): array;
}
