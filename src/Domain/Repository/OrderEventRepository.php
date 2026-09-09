<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Enum\OrderEventType;

interface OrderEventRepository
{
    public function append(string $orderId, OrderEventType $eventType, array $eventData): void;

    /** @return array<int, array<string, mixed>> */
    public function findByOrderId(string $orderId): array;

    /** @return array<int, array<string, mixed>> */
    public function findByOrderIdUntil(string $orderId, string $untilDate): array;

    public function findByOrderIdUntilId(string $orderId, int $untilEventId): array;
}
