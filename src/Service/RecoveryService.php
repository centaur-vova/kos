<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Options;
use App\Database;
use App\Enum\OrderStatus;
use Psr\Log\LoggerInterface;

final readonly class RecoveryService
{
    public function __construct(
        private Database $db,
        private DeliveryService $deliveryService,
        private Options $options,
        private LoggerInterface $logger,
    ) {
    }

    public function recoverStuckOrders(): void
    {
        $this->logger->info('Starting recovery of stuck orders');

        $pdo = $this->db->getConnection();

        $stmt = $pdo->prepare(
            "SELECT id, order_code FROM orders
             WHERE status NOT IN (:delivered, :payment_failed)
             AND paid_at IS NOT NULL
             AND updated_at < NOW() - INTERVAL '1 minute' * :stuck_after_min
             ORDER BY created_at
             LIMIT :batch_size"
        );
        $stmt->execute([
            'delivered' => OrderStatus::Delivered->value,
            'payment_failed' => OrderStatus::PaymentFailed->value,
            'stuck_after_min' => $this->options->recoveryStuckAfterMin,
            'batch_size' => $this->options->recoveryBatchSize,
        ]);

        $stuckOrders = $stmt->fetchAll();

        $this->logger->info('Found stuck orders', ['count' => count($stuckOrders)]);

        foreach ($stuckOrders as $order) {
            $this->logger->info('Recovering order', [
                'order_id' => $order['id'],
                'order_code' => $order['order_code'],
            ]);

            $this->deliveryService->deliverByOrderCode($order['order_code']);
        }

        $this->logger->info('Recovery completed');
    }
}
