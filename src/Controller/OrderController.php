<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ApiResponse;
use Swoole\Http\Request;
use App\Service\OrderService;
use Psr\Log\LoggerInterface;

final readonly class OrderController
{
    public function __construct(
        private OrderService $orderService,
        private LoggerInterface $logger,
    ) {
    }

    public function create(Request $request, array $params): ApiResponse
    {
        $content = $request->getContent();
        $body = $content ? json_decode($content, true) : null;

        if (!is_array($body)) {
            return ApiResponse::error('Invalid JSON payload', 400, 'invalid_json');
        }

        $sku = $body['sku'] ?? null;
        $userId = $body['user_id'] ?? null;

        if (!$sku || !$userId) {
            return ApiResponse::error('Missing required fields: sku, user_id', 400, 'missing_fields');
        }

        try {
            $this->logger->info('Creating order', ['sku' => $sku, 'user_id' => $userId]);
            $order = $this->orderService->create($sku, $userId);

            return ApiResponse::success(['order' => $order], 201);

        } catch (\RuntimeException $e) {
            $this->logger->error('Order creation failed', [
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error($e->getMessage(), 400, 'order_creation_failed');
        }
    }

    public function show(Request $request, array $params): ApiResponse
    {
        $orderId = $params['id'] ?? null;

        if (!$orderId) {
            return ApiResponse::error('Order ID required', 400, 'order_id_required');
        }

        $order = $this->orderService->getById($orderId);

        if (!$order) {
            return ApiResponse::error('Order not found', 404, 'order_not_found');
        }

        return ApiResponse::success(['order' => $order]);
    }
}
