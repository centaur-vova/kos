<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Database;
use App\Domain\Repository\OrderItemRepository;
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

    public function findDeliveriesByOrderId(string $orderId): array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($orderId) {
            $stmt = $pdo->prepare("SELECT * FROM deliveries WHERE order_id = ? ORDER BY created_at");
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

    public function countByOrderId(string $orderId): array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($orderId) {
            $stmt = $pdo->prepare(
                "SELECT
                    COUNT(*) as total,
                    COUNT(*) FILTER (WHERE status = 'delivered') as delivered
                 FROM order_items WHERE order_id = ?"
            );
            $stmt->execute([$orderId]);
            return $stmt->fetch();
        });
    }
}
