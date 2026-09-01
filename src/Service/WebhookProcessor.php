<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Options;
use App\DTO\PaymentWebhook;
use App\DTO\PaymentProcessingResult;
use App\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;

final readonly class WebhookProcessor
{
    public function __construct(
        private PaymentService $paymentService,
        private StorageInterface $storage,
        private LoggerInterface $logger,
        private Options $options,
    ) {
    }

    public function process(PaymentWebhook $webhook): PaymentProcessingResult
    {
        $eventKey = "payment:event:{$webhook->eventId}";

        $this->logger->info('Processing webhook', [
            'event_id' => $webhook->eventId,
            'order_code' => $webhook->orderCode,
        ]);

        if ($this->storage->has($eventKey)) {
            $this->logger->info('Webhook already processed', ['event_id' => $webhook->eventId]);
            return PaymentProcessingResult::alreadyProcessed('already_processed');
        }

        $this->storage->set($eventKey, 'processing', $this->options->paymentIdempotencyTtlSec);

        try {
            $result = $this->paymentService->process($webhook);

            if ($result->isProcessed() && $webhook->isPaid()) {
                Coroutine::create(function () use ($webhook) {
                    $this->paymentService->deliverByOrderCode($webhook->orderCode);
                });
            }

            return $result;

        } catch (\Throwable $e) {
            $this->storage->del($eventKey);
            throw $e;
        }
    }
}
