<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\PaymentWebhook;
use App\Http\ApiResponse;
use Swoole\Http\Request;
use App\Service\PaymentService;
use Psr\Log\LoggerInterface;

final readonly class WebhookController
{
    public function __construct(
        private PaymentService $paymentService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Request $request, array $params): ApiResponse
    {
        $content = $request->getContent();
        $body = $content ? json_decode($content, true) : null;

        if (!is_array($body)) {
            return ApiResponse::error('Invalid JSON payload', 400, 'invalid_json');
        }

        try {
            $webhook = PaymentWebhook::fromArray($body);

            if (!$this->validateWebhook($webhook)) {
                return ApiResponse::error('Invalid webhook payload', 400, 'invalid_webhook');
            }

            $result = $this->paymentService->processWebhook($webhook);
            return ApiResponse::success($result);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook error', [
                'error' => $e->getMessage(),
            ]);
            return ApiResponse::error('Internal error', 500, 'internal_error');
        }
    }

    private function validateWebhook(PaymentWebhook $webhook): bool
    {
        return !empty($webhook->eventId)
            && !empty($webhook->orderCode)
            && !empty($webhook->status)
            && $webhook->amount > 0;
    }
}
