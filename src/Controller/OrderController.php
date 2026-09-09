<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\OrderItem;
use App\Http\ApiResponse;
use App\Service\EventSourcingService;
use Swoole\Http\Request;
use App\Service\OrderService;
use Psr\Log\LoggerInterface;

final readonly class OrderController
{
    public function __construct(
        private OrderService $orderService,
        private LoggerInterface $logger,
        private EventSourcingService $eventSourcingService,
    ) {
    }

    public function create(Request $request, array $params): ApiResponse
    {
        $content = $request->getContent();
        $body = $content ? json_decode($content, true) : null;

        if (!is_array($body) || !isset($body['user_id'], $body['items'])) {
            return ApiResponse::error('Invalid payload: user_id and items required', 400, 'invalid_payload');
        }

        $items = [];
        foreach ($body['items'] as $itemData) {
            if (!is_array($itemData) || !isset($itemData['sku'])) {
                return ApiResponse::error('Each item must have sku', 400, 'invalid_item');
            }
            $items[] = OrderItem::fromArray($itemData);
        }

        try {
            $this->logger->info('Creating order', [
                'user_id' => $body['user_id'],
                'items_count' => count($items),
            ]);

            $order = $this->orderService->create($body['user_id'], $items);

            return ApiResponse::success(['order' => $order], 201);

        } catch (\RuntimeException $e) {
            $this->logger->error('Order creation failed', ['error' => $e->getMessage()]);
            return ApiResponse::error($e->getMessage(), 400, 'order_creation_failed');
        }
    }

    public function show(Request $request, array $params): ApiResponse
    {
        $orderId = $params['id'] ?? null;

        if (!$orderId || $orderId === 'null') {
            return ApiResponse::error('Order ID required', 400, 'order_id_required');
        }

        $order = $this->orderService->getById($orderId);

        if (!$order) {
            return ApiResponse::error('Order not found', 404, 'order_not_found');
        }

        return ApiResponse::success(['order' => $order]);
    }

    public function stateAt(Request $request, array $params): ApiResponse
    {
        $orderCode = $params['id'] ?? null;
        $date = $request->get['until'] ?? null;

        if (!$orderCode || !$date) {
            return ApiResponse::error('Order code and until date are required', 400);
        }

        $order = $this->orderService->getById($orderCode);
        if (!$order) {
            return ApiResponse::error('Order not found', 404, 'order_not_found');
        }

        // Передаем чистый UUID объекта и строку даты со смещением
        $state = $this->eventSourcingService->getStateAt($order->id, $date);

        return ApiResponse::success(['state' => $state]);
    }


    public function events(Request $request, array $params): ApiResponse
    {
        $orderCode = $params['id'] ?? null;

        if (!$orderCode) {
            return ApiResponse::error('Order code required', 400);
        }

        $order = $this->orderService->getById($orderCode);
        if (!$order) {
            return ApiResponse::error('Order not found', 404, 'order_not_found');
        }

        $events = $this->eventSourcingService->getEventStreamByOrderId($order->id);

        return ApiResponse::success(['events' => $events]);
    }

}
