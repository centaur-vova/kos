<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Options;
use App\Domain\Repository\OrderRepository;
use Psr\Log\LoggerInterface;

final readonly class RecoveryService
{
    public function __construct(
        private OrderRepository $orderRepository,
        private DeliveryService $deliveryService,
        private Options $options,
        private LoggerInterface $logger,
    ) {
    }

    public function recoverStuckOrders(): void
    {
        return; // TOREMOVE

        $this->logger->info('Starting recovery of stuck orders');

        $stuckOrders = $this->orderRepository->findStuckOrders(
            stuckAfterMin: $this->options->recoveryStuckAfterMin,
            limit: $this->options->recoveryBatchSize,
        );

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
