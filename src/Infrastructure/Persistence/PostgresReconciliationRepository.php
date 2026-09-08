<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Database;
use App\Domain\Repository\ReconciliationRepository;
use App\Enum\OrderStatus;
use PDO;
use Swoole\Database\PDOProxy;

final readonly class PostgresReconciliationRepository implements ReconciliationRepository
{
    public function __construct(
        private Database $db,
    ) {
    }

    public function findAnomalies(int $stuckAfterMin): array
    {
        return $this->db->transaction(function (PDO|PDOProxy $pdo) use ($stuckAfterMin) {
            // Оплачен, но не выдан
            $stmt = $pdo->prepare(
                "SELECT * FROM orders
                WHERE status IN (?, ?, ?, ?, ?)
                AND paid_at IS NOT NULL
                AND updated_at < NOW() - INTERVAL '1 minute' * ?
                ORDER BY created_at"
            );

            $stmt->bindValue(1, OrderStatus::Created->value, PDO::PARAM_STR);
            $stmt->bindValue(2, OrderStatus::Paid->value, PDO::PARAM_STR);
            $stmt->bindValue(3, OrderStatus::Delivering->value, PDO::PARAM_STR);
            $stmt->bindValue(4, OrderStatus::DeliveryFailed->value, PDO::PARAM_STR);
            $stmt->bindValue(5, OrderStatus::OutOfStock->value, PDO::PARAM_STR);
            $stmt->bindValue(6, $stuckAfterMin, PDO::PARAM_INT);
            $stmt->execute();

            $paidNotDelivered = $stmt->fetchAll();

            // Выдан, но не оплачен
            $stmt = $pdo->prepare(
                "SELECT * FROM orders
                WHERE status = ?
                AND paid_at IS NULL
                ORDER BY created_at"
            );
            $stmt->bindValue(1, OrderStatus::Delivered->value, PDO::PARAM_STR);
            $stmt->execute();
            $deliveredNotPaid = $stmt->fetchAll();

            // Зависшие в delivering
            $stmt = $pdo->prepare(
                "SELECT * FROM orders
                WHERE status = ?
                AND updated_at < NOW() - INTERVAL '1 minute'
                ORDER BY updated_at"
            );
            $stmt->bindValue(1, OrderStatus::Delivering->value, PDO::PARAM_STR);
            $stmt->execute();
            $stuckDelivering = $stmt->fetchAll();

            return [
                'paidNotDelivered' => $paidNotDelivered,
                'deliveredNotPaid' => $deliveredNotPaid,
                'stuckDelivering' => $stuckDelivering,
            ];
        });
    }
}
