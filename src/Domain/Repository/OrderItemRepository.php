<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\DTO\Order\OrderStats;
use App\Enum\OrderItemStatus;

interface OrderItemRepository
{
    /** @return array<int, array<string, mixed>> */
    public function findPendingByOrderId(string $orderId): array;

    /** @return array<int, array<string, mixed>> */
    public function findFailedByOrderId(string $orderId): array;

    /** @return array<int, array<string, mixed>> */
    public function findByOrderId(string $orderId): array;

    /** @return array<int, array<string, mixed>> */
    public function findRefundsByOrderId(string $orderId): array;

    public function findByDeliveredCode(string $code): ?array;

    public function findUnfinishedByOrderId(string $orderId): array;

    public function updateStatus(string $itemId, OrderItemStatus $status): void;

    public function markDelivered(string $itemId, string $code, string $provider, string $requestId): void;

    public function countByOrderId(string $orderId): OrderStats;
}
