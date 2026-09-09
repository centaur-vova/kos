<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Enum\OrderStatus;

interface OrderRepository
{
    /** @return array<string, mixed>|null */
    public function findByOrderCode(string $orderCode): ?array;

    public function findById(string $id): ?array;

    public function createWithItems(string $userId, array $items): array;

    public function updateStatus(string $orderId, OrderStatus $status): void;

    public function findByOrderCodeForUpdate(string $orderCode): ?array;

    public function updateStatusOptimistic(string $orderId, OrderStatus $newStatus, OrderStatus $expectedStatus, int $version): bool;

    public function findStuckOrders(int $stuckAfterMin, int $limit): array;

    public function truncateOrders(): void;
}
