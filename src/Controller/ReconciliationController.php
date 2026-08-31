<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\Options;
use App\Database;
use App\Enum\OrderStatus;
use App\Http\ApiResponse;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request;

final readonly class ReconciliationController
{
    public function __construct(
        private Database $db,
        private LoggerInterface $logger,
        private Options $options,
    ) {
    }

    public function index(Request $request, array $params): ApiResponse
    {
        $this->logger->info('Reconciliation requested');

        $pdo = $this->db->getConnection();

        // Оплачен, но не выдан
        $stmt = $pdo->prepare(
            "SELECT * FROM orders
             WHERE status IN (:created, :paid, :delivering, :delivery_failed, :out_of_stock)
             AND paid_at IS NOT NULL
             AND updated_at < NOW() - INTERVAL '1 minute' * :stuck_after_min
             ORDER BY created_at"
        );
        $stmt->execute([
            'created' => OrderStatus::Created->value,
            'paid' => OrderStatus::Paid->value,
            'delivering' => OrderStatus::Delivering->value,
            'delivery_failed' => OrderStatus::DeliveryFailed->value,
            'out_of_stock' => OrderStatus::OutOfStock->value,
            'stuck_after_min' => $this->options->recoveryStuckAfterMin,
        ]);
        $paidNotDelivered = $stmt->fetchAll();

        // Выдан, но не оплачен
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

        return ApiResponse::success([
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
        ]);
    }
}
