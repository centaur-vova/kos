<?php

declare(strict_types=1);

namespace App\Controller;

use Swoole\Http\Request;
use Swoole\Http\Response;
use App\Database;
use App\Service\OrderService;

class OrderController
{
    public function __construct(
        private OrderService $orderService
    ) {
    }

    public function create(Request $request, Response $response, array $params): void
    {
        $body = json_decode($request->getContent(), true);

        $sku = $body['sku'] ?? null;
        $userId = $body['user_id'] ?? null;

        if (!$sku || !$userId) {
            $response->status(400);
            $response->end(json_encode([
                'status' => 'error',
                'message' => 'Missing required fields: sku, user_id',
            ]));
            return;
        }

        try {
            $order = $this->orderService->create($sku, $userId);

            $response->status(201);
            $response->end(json_encode([
                'status' => 'created',
                'order' => $order,
            ]));

        } catch (\RuntimeException $e) {
            $response->status(400);
            $response->end(json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]));
        }
    }

    public function show(Request $request, Response $response, array $params): void
    {
        $orderId = $params['id'] ?? null;

        if (!$orderId) {
            $response->status(400);
            $response->end(json_encode(['status' => 'error', 'message' => 'Order ID required']));
            return;
        }

        $order = $this->orderService->getById($orderId);

        if (!$order) {
            $response->status(404);
            $response->end(json_encode(['status' => 'error', 'message' => 'Order not found']));
            return;
        }

        $response->end(json_encode([
            'status' => 'ok',
            'order' => $order,
        ]));
    }
}
