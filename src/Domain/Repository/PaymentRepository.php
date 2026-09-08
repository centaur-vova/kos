<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\DTO\PaymentWebhook;

interface PaymentRepository
{
    public function exists(string $eventId): bool;

    public function save(PaymentWebhook $webhook, string $orderId): void;

    public function saveOrphan(PaymentWebhook $webhook): void;

    public function saveDuplicate(PaymentWebhook $webhook, string $orderId): void;

    public function saveLate(PaymentWebhook $webhook, string $orderId): void;
}
