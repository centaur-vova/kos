<?php

declare(strict_types=1);

namespace App\Controller;

use Swoole\Http\Request;
use Swoole\Http\Response;
use App\Service\OrderService;
use Psr\Log\LoggerInterface;

final readonly class OrderController
{
    public function __construct(
        private OrderService $orderService,
        private LoggerInterface $logger,
    ) {
    }

    public function create(Request $request, Response $response, array $params): void
    {
        $content = $request->getContent();
        $body = $content ? json_decode($content, true) : null;

        if (!is_array($body)) {
            $response->status(400);
            $response->end(json_encode([
                'status' => 'error',
                'message' => 'Invalid JSON payload',
            ]));
            return;
        }

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
            $this->logger->info('Creating order', ['sku' => $sku, 'user_id' => $userId]);
            $order = $this->orderService->create($sku, $userId);

            $response->status(201);
            $response->end(json_encode([
                'status' => 'created',
                'order' => $order,
            ]));

        } catch (\RuntimeException $e) {
            $this->logger->error('Order creation failed', [
                'error' => $e->getMessage(),
            ]);

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
