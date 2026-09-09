<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Database;
use App\Domain\Repository\OrderEventRepository;
use App\Enum\OrderEventType;
use PDO;
use Swoole\Database\PDOProxy;

final readonly class PostgresOrderEventRepository implements OrderEventRepository
{
    public function __construct(
        private Database $db,
    ) {
    }

    public function append(string $orderId, OrderEventType $eventType, array $eventData): void
    {
        $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($orderId, $eventType, $eventData) {
            $stmt = $pdo->prepare(
                "INSERT INTO order_events (order_id, event_type, event_data, created_at) VALUES (?, ?, ?, NOW())"
            );
            $stmt->execute([$orderId, $eventType->value, json_encode($eventData)]);
        });
    }

    public function findByOrderIdUntilId(string $orderId, int $untilEventId): array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($orderId, $untilEventId) {
            $stmt = $pdo->prepare(
                "SELECT * FROM order_events
                 WHERE order_id = ?
                 AND id <= ?
                 ORDER BY id ASC" // Гарантирует идеальный порядок воспроизведения
            );
            $stmt->execute([$orderId, $untilEventId]);
            return $stmt->fetchAll();
        });
    }


    public function findByOrderId(string $orderId): array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($orderId) {
            $stmt = $pdo->prepare("SELECT * FROM order_events WHERE order_id = ? ORDER BY id ASC");
            $stmt->execute([$orderId]);
            return $stmt->fetchAll();
        });
    }

    public function findByOrderIdUntil(string $orderId, string $untilDate): array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($orderId, $untilDate) {
            $stmt = $pdo->prepare(
                "SELECT * FROM order_events
                 WHERE order_id = ?
                 AND created_at <= ?::TIMESTAMPTZ
                 ORDER BY id ASC" // Сортируем строго по ID записи для сохранения хронологии
            );
            $stmt->execute([$orderId, $untilDate]);
            return $stmt->fetchAll();
        });
    }

}
