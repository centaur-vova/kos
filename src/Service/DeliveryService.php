<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Options;
use App\Domain\Repository\OrderItemRepository;
use App\Domain\Repository\OrderRepository;
use App\Domain\Repository\RefundRepository;
use App\Enum\OrderItemStatus;
use App\Enum\OrderStatus;
use App\Exception\Provider\ProviderException;
use App\Exception\Provider\ProviderTimeoutException;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;

final readonly class DeliveryService
{
    public function __construct(
        private ProviderClient $providerClient,
        private OrderItemRepository $orderItemRepository,
        private OrderRepository $orderRepository,
        private RefundRepository $refundRepository,
        private LockService $lockService,
        private Options $options,
        private LoggerInterface $logger,
    ) {
    }

    public function deliverByOrderCode(string $orderCode): void
    {
        $this->logger->info('deliverByOrderCode', ['order_code' => $orderCode]);

        $order = $this->orderRepository->findByOrderCode($orderCode);

        if ($order) {
            $this->deliver($order['id']);
        }
    }

    public function deliver(string $orderId): void
    {
        $this->logger->info('deliver', ['order_id' => $orderId]);

        $lockKey = "delivery:lock:{$orderId}";

        $this->lockService->withLock($lockKey, function () use ($orderId) {
            $items = $this->orderItemRepository->findPendingByOrderId($orderId);

            foreach ($items as $item) {
                $this->deliverItem($item);
            }

            $this->updateOrderStatusAfterItems($orderId);
        });
    }

    private function deliverItem(array $item): void
    {
        $firstProvider = $this->options->getFirstProvider();
        $requestId = "req_{$item['id']}-1";

        $this->tryDeliverItem($item, $requestId, $firstProvider->name);
    }

    private function tryDeliverItem(array $item, string $requestId, string $provider): void
    {
        $this->logger->info('tryDeliverItem', [
            'item_id' => $item['id'],
            'sku' => $item['sku'],
            'request_id' => $requestId,
            'provider' => $provider,
        ]);

        $this->orderItemRepository->updateStatus($item['id'], OrderItemStatus::Delivering);

        try {
            $result = $this->providerClient->issue(
                requestId: $requestId,
                sku: $item['sku'],
                orderCode: "item-{$item['id']}",
                provider: $provider,
            );

            if ($result['status'] === 'ok') {
                $code = $result['code'];

                if (!$this->validateCode($code, $item['sku'])) {
                    $this->orderItemRepository->updateStatus($item['id'], OrderItemStatus::DeliveryFailed);
                    $this->logger->critical('Suspicious code from provider', [
                        'provider' => $provider,
                        'code' => $code,
                    ]);

                    // Вызываем fallback!
                    $this->tryFallback($item, $provider);
                    return;
                }

                $this->orderItemRepository->markDelivered(
                    $item['id'],
                    $code,
                    $provider,
                    $requestId,
                );
            } elseif ($result['reason'] === 'out_of_stock') {
                $this->orderItemRepository->updateStatus($item['id'], OrderItemStatus::OutOfStock);
            } else {
                $this->orderItemRepository->updateStatus($item['id'], OrderItemStatus::DeliveryFailed);
            }

        } catch (ProviderTimeoutException $e) {
            $this->logger->warning('Provider timeout', [
                'provider' => $provider,
                'request_id' => $requestId,
            ]);
            $this->handleProviderTimeout($item, $requestId, $provider);

        } catch (ProviderException $e) {
            $this->logger->error('Provider error', [
                'provider' => $provider,
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
            $this->handleProviderError($item, $requestId, $provider);
        }
    }

    private function handleProviderTimeout(array $item, string $requestId, string $provider): void
    {
        $this->retryItemWithBackoff($item, $requestId, $provider);
    }

    private function retryItemWithBackoff(array $item, string $requestId, string $provider): void
    {
        $this->logger->info('retryItemWithBackoff', [
            'item_id' => $item['id'],
            'request_id' => $requestId,
            'provider' => $provider,
            'max_retries' => $this->options->deliveryMaxRetries,
        ]);

        for ($attempt = 0; $attempt < $this->options->deliveryMaxRetries; $attempt++) {
            $this->logger->info('retry attempt', ['attempt' => $attempt]);

            $delays = $this->options->deliveryRetryDelaysMs;
            if (empty($delays)) {
                throw new \RuntimeException('DELIVERY_RETRY_DELAYS_MS must not be empty');
            }

            $delayMs = $delays[$attempt] ?? $delays[array_key_last($delays)];
            Coroutine::sleep($delayMs / 1000);

            try {
                $result = $this->providerClient->issue(
                    requestId: $requestId,
                    sku: $item['sku'],
                    orderCode: "item-{$item['id']}",
                    provider: $provider,
                );

                if ($result['status'] === 'ok') {
                    $code = $result['code'];

                    if (!$this->validateCode($code, $item['sku'])) {
                        $this->orderItemRepository->updateStatus($item['id'], OrderItemStatus::DeliveryFailed);
                        $this->tryFallback($item, $provider);
                        return;
                    }

                    $this->orderItemRepository->markDelivered(
                        $item['id'],
                        $code,
                        $provider,
                        $requestId,
                    );
                    return;
                }

                $this->tryFallback($item, $provider);
                return;

            } catch (ProviderTimeoutException $e) {
                continue;
            } catch (ProviderException $e) {
                $this->tryFallback($item, $provider);
                return;
            }
        }

        $this->orderItemRepository->updateStatus($item['id'], OrderItemStatus::DeliveryFailed);
    }

    private function handleProviderError(array $item, string $requestId, string $provider): void
    {
        $this->orderItemRepository->updateStatus($item['id'], OrderItemStatus::DeliveryFailed);
        $this->tryFallback($item, $provider);
    }

    private function tryFallback(array $item, string $currentProvider): void
    {
        $nextProvider = $this->options->getNextProvider($currentProvider);

        if ($nextProvider === null) {
            $this->orderItemRepository->updateStatus($item['id'], OrderItemStatus::DeliveryFailed);
            return;
        }

        $fallbackRequestId = "req_{$item['id']}-" . (array_search($nextProvider->name, array_keys($this->options->providers), true) + 1);

        $this->tryDeliverItem($item, $fallbackRequestId, $nextProvider->name);
    }

    private function updateOrderStatusAfterItems(string $orderId): void
    {
        $this->logger->info('updateOrderStatusAfterItems', ['order_id' => $orderId]);

        $stats = $this->orderItemRepository->countByOrderId($orderId);

        $this->logger->info('Stats', ['stats' => $stats]);

        if ($stats['delivered'] === $stats['total']) {
            $this->orderRepository->updateStatus($orderId, OrderStatus::Delivered);
        } elseif ($stats['delivered'] > 0) {
            $this->orderRepository->updateStatus($orderId, OrderStatus::PartiallyDelivered);
            $this->createRefundsForFailedItems($orderId);
        } else {
            $this->orderRepository->updateStatus($orderId, OrderStatus::DeliveryFailed);
        }
    }

    private function createRefundsForFailedItems(string $orderId): void
    {
        $this->logger->info('createRefundsForFailedItems', ['order_id' => $orderId]);

        $failedItems = $this->orderItemRepository->findFailedByOrderId($orderId);

        $this->logger->info('Failed items', ['count' => count($failedItems)]);

        foreach ($failedItems as $item) {
            $this->refundRepository->createForItem($item['id'], (int)$item['price_cents']);
            $this->orderItemRepository->updateStatus($item['id'], OrderItemStatus::Refunded);
        }
    }

    private function validateCode(string $code, string $sku): bool
    {
        // Проверяем дубликат
        $existing = $this->orderItemRepository->findByDeliveredCode($code);

        if ($existing) {
            $this->logger->error('Duplicate code detected', [
                'code' => $code,
                'existing_item_id' => $existing['id'] ?? null,
            ]);
            return false;
        }

        // Проверяем формат кода по типу товара
        if (!$this->isValidCodeFormat($code, $sku)) {
            $this->logger->error('Invalid code format', [
                'code' => $code,
                'sku' => $sku,
            ]);
            return false;
        }

        return true;
    }

    private function isValidCodeFormat(string $code, string $sku): bool
    {
        // Для topup/giftcard — код начинается с TOPUP-
        if (str_starts_with($sku, 'STEAM-TOPUP') || str_starts_with($sku, 'GIFT-')) {
            return str_starts_with($code, 'TOPUP-');
        }

        // Для key — формат XXXX-XXXX-XXXX
        return (bool)preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code);
    }
}
