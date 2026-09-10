<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Database;
use App\Domain\Repository\OrderItemRepository;
use App\DTO\Order\OrderStats;
use App\Enum\OrderItemStatus;
use PDO;
use Swoole\Database\PDOProxy;

final readonly class PostgresOrderItemRepository implements OrderItemRepository
{
    public function __construct(
        private Database $db,
    ) {
    }

    public function findPendingByOrderId(string $orderId): array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($orderId) {
            $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? AND status = 'pending'");
            $stmt->execute([$orderId]);
            return $stmt->fetchAll();
        });
    }

    public function findFailedByOrderId(string $orderId): array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($orderId) {
            $stmt = $pdo->prepare(
                "SELECT * FROM order_items WHERE order_id = ? AND status IN ('delivery_failed', 'out_of_stock')"
            );
            $stmt->execute([$orderId]);
            return $stmt->fetchAll();
        });
    }

    public function findByOrderId(string $orderId): array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($orderId) {
            $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY created_at");
            $stmt->execute([$orderId]);
            return $stmt->fetchAll();
        });
    }

    public function findRefundsByOrderId(string $orderId): array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($orderId) {
            $stmt = $pdo->prepare(
                "SELECT r.* FROM refunds r
                 JOIN order_items oi ON oi.id = r.order_item_id
                 WHERE oi.order_id = ?"
            );
            $stmt->execute([$orderId]);
            return $stmt->fetchAll();
        });
    }

    public function findByDeliveredCode(string $code): ?array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($code) {
            $stmt = $pdo->prepare("SELECT * FROM order_items WHERE delivered_code = ?");
            $stmt->execute([$code]);
            return $stmt->fetch() ?: null;
        });
    }

    public function findUnfinishedByOrderId(string $orderId): array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($orderId) {
            $stmt = $pdo->prepare(
                "SELECT * FROM order_items
             WHERE order_id = ?
             AND status IN ('pending', 'delivering', 'delivery_failed', 'out_of_stock')"
            );
            $stmt->execute([$orderId]);
            return $stmt->fetchAll();
        });
    }

    public function updateStatus(string $itemId, OrderItemStatus $status): void
    {
        $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($itemId, $status) {
            $stmt = $pdo->prepare("UPDATE order_items SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status->value, $itemId]);
        });
    }

    public function markDelivered(string $itemId, string $code, string $provider, string $requestId): void
    {
        $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($itemId, $code, $provider, $requestId) {
            $stmt = $pdo->prepare(
                "UPDATE order_items SET status = 'delivered', delivered_code = ?, provider = ?, provider_request_id = ?, updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([$code, $provider, $requestId, $itemId]);
        });
    }

    public function countByOrderId(string $orderId): OrderStats
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($orderId) {
            $stmt = $pdo->prepare(
                "SELECT
                    COUNT(*) as total,
                    COUNT(*) FILTER (WHERE status = 'delivered') as delivered,
                    COUNT(*) FILTER (WHERE status = 'refunded') as refunded,
                    COUNT(*) FILTER (WHERE status = 'delivery_failed') as failed
                 FROM order_items WHERE order_id = ?"
            );
            $stmt->execute([$orderId]);

            return OrderStats::fromArray($stmt->fetch());
        });
    }
}
