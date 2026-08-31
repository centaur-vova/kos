<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Enum\OrderStatus;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;

class ReconciliationController
{
    public function __construct(
        private Database $db,
        private LoggerInterface $logger,
    ) {
    }

    public function index(Request $request, Response $response, array $params): void
    {
        $this->logger->info('Reconciliation requested');

        $pdo = $this->db->getConnection();

        // Оплачен, но не выдан
        $stmt = $pdo->prepare(
            "SELECT * FROM orders
             WHERE status NOT IN (:delivered, :payment_failed)
             AND paid_at IS NOT NULL
             AND updated_at < NOW() - INTERVAL '5 minutes'
             ORDER BY created_at"
        );
        $stmt->execute([
            'delivered' => OrderStatus::Delivered->value,
            'payment_failed' => OrderStatus::PaymentFailed->value,
        ]);
        $paidNotDelivered = $stmt->fetchAll();

        // Выдан, но не оплачен (подозрительно)
        $stmt = $pdo->prepare(
            "SELECT * FROM orders
             WHERE status = :delivered
             AND paid_at IS NULL
             ORDER BY created_at"
        );
        $stmt->execute([
            'delivered' => OrderStatus::Delivered->value,
        ]);
        $deliveredNotPaid = $stmt->fetchAll();

        // Зависшие в delivering
        $stmt = $pdo->prepare(
            "SELECT * FROM orders
             WHERE status = :delivering
             AND updated_at < NOW() - INTERVAL '1 minute'
             ORDER BY updated_at"
        );
        $stmt->execute([
            'delivering' => OrderStatus::Delivering->value,
        ]);
        $stuckDelivering = $stmt->fetchAll();

        $response->end(json_encode([
            'status' => 'ok',
            'timestamp' => (new \DateTimeImmutable())->format('c'),
            'summary' => [
                'paid_not_delivered' => count($paidNotDelivered),
                'delivered_not_paid' => count($deliveredNotPaid),
                'stuck_delivering' => count($stuckDelivering),
            ],
            'details' => [
                'paid_not_delivered' => $paidNotDelivered,
                'delivered_not_paid' => $deliveredNotPaid,
                'stuck_delivering' => $stuckDelivering,
            ],
        ], JSON_UNESCAPED_UNICODE));
    }
}
