<?php

declare(strict_types=1);

namespace App\Controller;

use Swoole\Http\Request;
use Swoole\Http\Response;
use App\Service\PaymentService;
use Psr\Log\LoggerInterface;

final readonly class WebhookController
{
    public function __construct(
        private PaymentService $paymentService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Request $request, Response $response, array $params): void
    {
        $content = $request->getContent();
        $body = $content ? json_decode($content, true) : null;

        if (!is_array($body) || !$this->validateWebhook($body)) {
            $response->status(400);
            $response->end(json_encode([
                'status' => 'error',
                'message' => 'Invalid webhook payload',
            ]));
            return;
        }

        try {
            $result = $this->paymentService->processWebhook($body);
            $response->end(json_encode($result));
        } catch (\Throwable $e) {
            $this->logger->error('Webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $response->status(500);
            $response->end(json_encode([
                'status' => 'error',
                'message' => 'Internal error',
            ]));
        }
    }

    private function validateWebhook(array $body): bool
    {
        return isset(
            $body['event_id'],
            $body['order_id'],
            $body['status'],
            $body['amount']
        );
    }
}
