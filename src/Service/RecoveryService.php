<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use App\Enum\OrderStatus;
use Psr\Log\LoggerInterface;

class RecoveryService
{
    public function __construct(
        private Database $db,
        private DeliveryService $deliveryService,
        private LoggerInterface $logger,
    ) {
    }

    public function recoverStuckOrders(): void
    {
        $this->logger->info('Starting recovery of stuck orders');

        $pdo = $this->db->getConnection();

        // Находим зависшие заказы
        $stmt = $pdo->prepare(
            "SELECT id, order_code FROM orders
             WHERE status NOT IN (:delivered, :payment_failed)
             AND paid_at IS NOT NULL
             AND updated_at < NOW() - INTERVAL '5 minutes'
             ORDER BY created_at
             LIMIT 10"
        );
        $stmt->execute([
            'delivered' => OrderStatus::Delivered->value,
            'payment_failed' => OrderStatus::PaymentFailed->value,
        ]);

        $stuckOrders = $stmt->fetchAll();

        $this->logger->info('Found stuck orders', ['count' => count($stuckOrders)]);

        foreach ($stuckOrders as $order) {
            $this->logger->info('Recovering order', [
                'order_id' => $order['id'],
                'order_code' => $order['order_code'],
            ]);

            // Перезапускаем выдачу
            $this->deliveryService->deliverByOrderCode($order['order_code']);
        }

        $this->logger->info('Recovery completed');
    }
}
