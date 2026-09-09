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

    public function process(array $item): bool
    {
        $currentProvider = $this->options->getFirstProvider();
        $attemptCount = 0;

        while ($currentProvider !== null) {
            // ВАЖНО: requestId уникален для КАЖДОГО провайдера, чтобы идемпотентность A не сработала при fallback на B
            $requestId = "req_{$item['id']}-{$currentProvider->name}-" . ($attemptCount + 1);

            // Задаем промежуточный статус "В процессе доставки"
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
                    // Если код прошел валидацию — Сага для этого айтема успешно завершена!
                    if ($this->finalizeSuccess($item, $result['code'], $currentProvider->name, $requestId)) {
                        return true;
                    }

                    // Если код грязный (фрод) — не меняем статус айтема на фейл, а просто идем к фоллбэку!
                    $this->logger->warning('Provider returned bad code, switching to fallback', ['provider' => $currentProvider->name]);
                }

                if (($result['reason'] ?? '') === 'out_of_stock') {
                    $this->logger->warning('Provider out of stock, switching to fallback immediately', [
                        'sku' => $item['sku'],
                        'provider' => $currentProvider->name,
                    ]);
                }

                // НОВОЕ: Обработка HTTP 500 как fallback-триггера
                if (($result['reason'] ?? '') === 'internal_server_error') {
                    $this->logger->warning('Provider returned 500, switching to fallback', [
                        'sku' => $item['sku'],
                        'provider' => $currentProvider->name,
                    ]);
                }

            } catch (ProviderTimeoutException $e) {
                // Если ретраи увенчались успехом — выходим
                if ($this->executeNetworkRetries($item, $requestId, $currentProvider->name)) {
                    return true;
                }
            } catch (ProviderException $e) {
                $this->logger->error('Provider integration error', [
                    'provider' => $currentProvider->name,
                    'error' => $e->getMessage(),
                ]);
            }

            // Переключаемся на следующего фоллбэк-провайдера
            $currentProvider = $this->options->getNextProvider($currentProvider->name);
            $attemptCount++;
        }

        // Строго здесь: ЕСЛИ НИ ОДИН провайдер не справился, фиксируем финальный фейл позиции!
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
                // ВАЖНО: В ретраях используем ТОТ ЖЕ requestId, чтобы идемпотентность работала
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
                    return false; // Код грязный, ретраить этого провайдера бессмысленно
                }

                // Если получили явный текстовый отказ (например out_of_stock или 500) — прекращаем ретраи сети
                if (in_array(($result['reason'] ?? ''), ['out_of_stock', 'internal_server_error'], true)) {
                    return false;
                }

            } catch (ProviderTimeoutException) {
                continue; // Снова таймаут — послушно идем на следующий шаг цикла ретраев
            } catch (\Throwable) {
                return false; // Любая другая критическая ошибка — выходим на фоллбэк
            }
        }
        return false;
    }

    private function finalizeSuccess(array $item, string $code, string $provider, string $requestId): bool
    {
        // 1. Идемпотентность: проверяем дубликат кода в нашей БД
        $existing = $this->orderItemRepository->findByDeliveredCode($code);
        if ($existing) {
            $this->logger->critical('DISHONEST PROVIDER DETECTED: Code hijacked!', [
                'code' => $code,
                'item_id' => $item['id'],
            ]);
            return false; // Просто возвращаем false, статус в базе не пачкаем!
        }

        // 2. Валидация формата маски цифрового товара
        if (!$this->isValidCodeFormat($code, $item['sku'])) {
            $this->logger->error('Invalid code format returned from provider', [
                'code' => $code,
                'sku' => $item['sku'],
            ]);
            return false; // Ошибочный формат, отдаем управление фоллбэку
        }

        // 3. Успешная маркировка и фиксация в БД
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
