<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\PaymentWebhook;
use App\Exception\DomainException;
use App\Http\ApiResponse;
use Swoole\Http\Request;
use App\Service\WebhookProcessor;
use Psr\Log\LoggerInterface;

final readonly class WebhookController
{
    public function __construct(
        private WebhookProcessor $webhookProcessor,
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
            // Array payload to DTO
            $webhook = PaymentWebhook::fromArray($body);

            // Process webhook and get result
            $result = $this->webhookProcessor->process($webhook);

            return ApiResponse::success($result);

        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400, $e->getErrorCode());

        } catch (\Throwable $e) {
            $this->logger->error('Webhook error', [
                'error' => $e->getMessage(),
            ]);
            return ApiResponse::error('Internal error', 500, 'internal_error');
        }
    }
}
