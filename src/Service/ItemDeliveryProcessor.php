<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Options;
use App\Domain\Repository\OrderItemRepository;
use App\Enum\OrderItemStatus;
use App\Exception\Provider\ProviderException;
use App\Exception\Provider\ProviderTimeoutException;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;

final readonly class ItemDeliveryProcessor
{
    public function __construct(
        private ProviderClient $providerClient,
        private OrderItemRepository $orderItemRepository,
        private Options $options,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Изолированный асинхронный процесс выдачи ОДНОГО конкретного товара
     */
    public function process(array $item): bool
    {
        $currentProvider = $this->options->getFirstProvider();
        $attemptCount = 0;

        while ($currentProvider !== null) {
            $requestId = "req_{$item['id']}-" . ($attemptCount + 1);
            $this->orderItemRepository->updateStatus($item['id'], OrderItemStatus::Delivering);

            $this->logger->info('Attempting item delivery', [
                'item_id' => $item['id'],
                'provider' => $currentProvider->name,
                'request_id' => $requestId,
            ]);

            try {
                $result = $this->providerClient->issue(
                    requestId: $requestId,
                    sku: $item['sku'],
                    orderCode: "item-{$item['id']}",
                    provider: $currentProvider->name,
                );

                if ($result['status'] === 'ok') {
                    $success = $this->finalizeSuccess($item, $result['code'], $currentProvider->name, $requestId);

                    if ($success) {
                        return true;
                    }

                    // Код плохой — идём к следующему провайдеру
                    $currentProvider = $this->options->getNextProvider($currentProvider->name);
                    $attemptCount++;
                    continue;
                }

                if ($result['reason'] === 'out_of_stock') {
                    $this->logger->warning('Provider out of stock, switching to fallback immediately', [
                        'sku' => $item['sku'],
                        'provider' => $currentProvider->name,
                    ]);
                    $currentProvider = $this->options->getNextProvider($currentProvider->name);
                    $attemptCount++;
                    continue;
                }

            } catch (ProviderTimeoutException $e) {
                if ($this->executeNetworkRetries($item, $requestId, $currentProvider->name)) {
                    return true;
                }
            } catch (ProviderException $e) {
                $this->logger->error('Provider integration error', [
                    'provider' => $currentProvider->name,
                    'error' => $e->getMessage(),
                ]);
            }

            $currentProvider = $this->options->getNextProvider($currentProvider->name);
            $attemptCount++;
        }

        $this->orderItemRepository->updateStatus($item['id'], OrderItemStatus::DeliveryFailed);
        return false;
    }

    private function executeNetworkRetries(array $item, string $requestId, string $providerName): bool
    {
        $delays = $this->options->deliveryRetryDelaysMs;

        for ($attempt = 0; $attempt < $this->options->deliveryMaxRetries; $attempt++) {
            $delayMs = $delays[$attempt] ?? $delays[array_key_last($delays)];

            Coroutine::sleep($delayMs / 1000);

            $this->logger->info('Network retry attempt', [
                'item_id' => $item['id'],
                'attempt' => $attempt + 1,
                'provider' => $providerName,
            ]);

            try {
                $result = $this->providerClient->issue(
                    requestId: $requestId,
                    sku: $item['sku'],
                    orderCode: "item-{$item['id']}",
                    provider: $providerName,
                );

                if ($result['status'] === 'ok') {
                    $success = $this->finalizeSuccess($item, $result['code'], $providerName, $requestId);

                    if ($success) {
                        return true;
                    }

                    // Плохой код — fallback
                    return false;
                }
                return false;
            } catch (ProviderTimeoutException) {
                continue;
            } catch (\Throwable) {
                return false;
            }
        }
        return false;
    }

    private function finalizeSuccess(array $item, string $code, string $provider, string $requestId): bool
    {
        // 1. Идемпотентность: проверяем дубликат кода
        $existing = $this->orderItemRepository->findByDeliveredCode($code);
        if ($existing) {
            $this->logger->critical('DISHONEST PROVIDER DETECTED: Code hijacked!', [
                'code' => $code,
                'item_id' => $item['id'],
            ]);
            $this->orderItemRepository->updateStatus($item['id'], OrderItemStatus::DeliveryFailed);
            return false;
        }

        // 2. Валидация формата
        if (!$this->isValidCodeFormat($code, $item['sku'])) {
            $this->logger->error('Invalid code format returned from provider', [
                'code' => $code,
                'sku' => $item['sku'],
            ]);
            $this->orderItemRepository->updateStatus($item['id'], OrderItemStatus::DeliveryFailed);
            return false;
        }

        // 3. Успешная маркировка
        $this->orderItemRepository->markDelivered($item['id'], $code, $provider, $requestId);
        return true;
    }

    private function isValidCodeFormat(string $code, string $sku): bool
    {
        if (str_starts_with($sku, 'STEAM-TOPUP') || str_starts_with($sku, 'GIFT-')) {
            return str_starts_with($code, 'TOPUP-');
        }
        return (bool)preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code);
    }
}
