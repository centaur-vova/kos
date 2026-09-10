<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Options;
use App\Domain\Repository\OrderItemRepository;
use App\Enum\OrderItemStatus;
use App\Exception\Provider\ProviderException;
use App\Exception\Provider\ProviderTimeoutException;
use PDOException;
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

    public function process(array $item): bool
    {
        $currentProvider = $this->options->getFirstProvider();
        $attemptCount = 0;

        while ($currentProvider !== null) {
            // requestId уникален для каждого провайдера: идемпотентность A
            // не должна срабатывать при fallback на B
            $requestId = "req_{$item['id']}-{$currentProvider->name}-" . ($attemptCount + 1);

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
                    if ($this->finalizeSuccess($item, $result['code'], $currentProvider->name, $requestId)) {
                        return true;
                    }

                    // Код грязный (фрод или неверная маска) — идём к следующему провайдеру
                    $this->logger->warning('Provider returned bad code, switching to fallback', [
                        'provider' => $currentProvider->name,
                    ]);
                }

                // Явные отказы поставщика — сразу к следующему провайдеру
                $reason = $result['reason'] ?? '';
                if ($reason === 'out_of_stock') {
                    $this->logger->warning('Provider out of stock, switching to fallback', [
                        'sku' => $item['sku'],
                        'provider' => $currentProvider->name,
                    ]);
                } elseif ($reason === 'internal_server_error') {
                    $this->logger->warning('Provider returned 500, switching to fallback', [
                        'sku' => $item['sku'],
                        'provider' => $currentProvider->name,
                    ]);
                }

            } catch (ProviderTimeoutException $e) {
                if ($this->executeNetworkRetries($item, $requestId, $currentProvider->name)) {
                    return true;
                }

                // Поставщик мог успеть выдать код, но ответ не дошёл.
                // Переходим к fallback, но фиксируем это в логах для сверки.
                $this->logger->warning('Provider timed out after retries, may have issued code', [
                    'item_id' => $item['id'],
                    'provider' => $currentProvider->name,
                    'request_id' => $requestId,
                ]);
            } catch (ProviderException $e) {
                $this->logger->error('Provider integration error', [
                    'provider' => $currentProvider->name,
                    'error' => $e->getMessage(),
                ]);
            }

            $currentProvider = $this->options->getNextProvider($currentProvider->name);
            $attemptCount++;
        }

        // Ни один провайдер не справился — финальный фейл позиции
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
                // Тот же requestId: идемпотентность поставщика вернёт тот же код
                $result = $this->providerClient->issue(
                    requestId: $requestId,
                    sku: $item['sku'],
                    orderCode: "item-{$item['id']}",
                    provider: $providerName,
                );

                if ($result['status'] === 'ok') {
                    if ($this->finalizeSuccess($item, $result['code'], $providerName, $requestId)) {
                        return true;
                    }
                    // Код грязный — ретраить этого провайдера бессмысленно
                    return false;
                }

                // Явный отказ — прекращаем ретраи и идём на fallback
                if (in_array(($result['reason'] ?? ''), ['out_of_stock', 'internal_server_error'], true)) {
                    return false;
                }

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
        // 1. Проверка дубликата кода в нашей БД
        $existing = $this->orderItemRepository->findByDeliveredCode($code);
        if ($existing) {
            $this->logger->critical('Dishonest provider detected: code already used', [
                'code' => $code,
                'item_id' => $item['id'],
            ]);
            return false;
        }

        // 2. Валидация формата маски
        if (!$this->isValidCodeFormat($code, $item['sku'])) {
            $this->logger->error('Invalid code format returned from provider', [
                'code' => $code,
                'sku' => $item['sku'],
            ]);
            return false;
        }

        // 3. Запись в БД. Уникальный constraint delivered_code ловит гонку,
        // если два заказа одновременно получили один код.
        try {
            $this->orderItemRepository->markDelivered($item['id'], $code, $provider, $requestId);
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                $this->logger->critical('Race on delivered_code, blocked by unique constraint', [
                    'code' => $code,
                    'item_id' => $item['id'],
                ]);
                return false;
            }
            throw $e;
        }

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
