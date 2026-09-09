<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Repository\OrderRepository;
use App\Domain\Repository\OrderItemRepository;
use App\DTO\OrderItem;
use App\DTO\OrderResponse;
use App\Enum\OrderEventType;
use Psr\Log\LoggerInterface;

final readonly class OrderService
{
    public function __construct(
        private OrderRepository $orderRepository,
        private OrderItemRepository $orderItemRepository,
        private EventSourcingService $eventSourcingService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param OrderItem[] $items
     */
    public function create(string $userId, array $items): OrderResponse
    {
        $this->logger->info('Creating order', [
            'user_id' => $userId,
            'items_count' => count($items),
        ]);

        if (empty($items)) {
            throw new \RuntimeException('Order must contain at least one item');
        }

        $order = $this->orderRepository->createWithItems($userId, $items);

        // Подгружаем позиции
        $order['items'] = $this->orderItemRepository->findByOrderId($order['id']);

        // Log event
        $this->eventSourcingService->record(
            $order['id'],
            OrderEventType::OrderCreated,
            [
                'items' => $order['items'],
            ]
        );

        $this->logger->info('Order created', ['order_code' => $order['order_code']]);

        return OrderResponse::fromArray($order);
    }

    public function getById(string $orderId): ?OrderResponse
    {
        $order = str_starts_with($orderId, 'ord_')
            ? $this->orderRepository->findByOrderCode($orderId)
            : $this->orderRepository->findById($orderId);

        if (!$order) {
            return null;
        }

        // Подгружаем позиции
        $order['items'] = $this->orderItemRepository->findByOrderId($order['id']);

        // Подгружаем возвраты
        $order['refunds'] = $this->orderItemRepository->findRefundsByOrderId($order['id']);

        return OrderResponse::fromArray($order);
    }

    public function truncateOrders()
    {
        $this->orderRepository->truncateOrders();
    }
}
