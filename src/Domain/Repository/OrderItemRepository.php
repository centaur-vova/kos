<?php

declare(strict_types=1);

namespace App\Domain\Repository;

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
    public function findDeliveriesByOrderId(string $orderId): array;

    /** @return array<int, array<string, mixed>> */
    public function findRefundsByOrderId(string $orderId): array;

    public function updateStatus(string $itemId, OrderItemStatus $status): void;

    public function markDelivered(string $itemId, string $code, string $provider, string $requestId): void;

    /** @return array{total: int, delivered: int} */
    public function countByOrderId(string $orderId): array;
}
