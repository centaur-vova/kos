<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Database;
use App\Domain\Repository\PaymentRepository;
use App\DTO\PaymentWebhook;
use PDO;
use Swoole\Database\PDOProxy;

final readonly class PostgresPaymentRepository implements PaymentRepository
{
    public function __construct(
        private Database $db,
    ) {
    }

    public function exists(string $eventId): bool
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($eventId) {
            $stmt = $pdo->prepare("SELECT id FROM payments WHERE event_id = ?");
            $stmt->execute([$eventId]);
            return (bool)$stmt->fetch();
        });
    }

    public function save(PaymentWebhook $webhook, string $orderId): void
    {
        $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($webhook, $orderId) {
            $stmt = $pdo->prepare(
                "INSERT INTO payments (event_id, order_id, status, amount, currency) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $webhook->eventId,
                $orderId,
                $webhook->status,
                $webhook->amount,
                $webhook->currency,
            ]);
        });
    }

    public function saveOrphan(PaymentWebhook $webhook): void
    {
        $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($webhook) {
            $stmt = $pdo->prepare(
                "INSERT INTO payments (event_id, status, amount, currency) VALUES (?, 'orphan', ?, ?)"
            );
            $stmt->execute([$webhook->eventId, $webhook->amount, $webhook->currency]);
        });
    }

    public function saveDuplicate(PaymentWebhook $webhook, string $orderId): void
    {
        $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($webhook, $orderId) {
            $stmt = $pdo->prepare(
                "INSERT INTO payments (event_id, order_id, status, amount, currency) VALUES (?, ?, 'duplicate_after_delivery', ?, ?)"
            );
            $stmt->execute([$webhook->eventId, $orderId, $webhook->amount, $webhook->currency]);
        });
    }

    public function saveLate(PaymentWebhook $webhook, string $orderId): void
    {
        $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($webhook, $orderId) {
            $stmt = $pdo->prepare(
                "INSERT INTO payments (event_id, order_id, status, amount, currency) VALUES (?, ?, 'late_payment', ?, ?)"
            );
            $stmt->execute([$webhook->eventId, $orderId, $webhook->amount, $webhook->currency]);
        });
    }
}
