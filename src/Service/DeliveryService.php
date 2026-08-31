<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use App\Enum\OrderStatus;
use App\Exception\Provider\ProviderException;
use App\Exception\Provider\ProviderTimeoutException;
use App\Config\Options;
use App\Enum\DeliveryStatus;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;

final class DeliveryService
{
    public function __construct(
        private Database $db,
        private ProviderClient $providerClient,
        private LockService $lockService,
        private Options $options,
        private LoggerInterface $logger,
    ) {
    }

    public function deliverByOrderCode(string $orderCode): void
    {
        $this->logger->info('deliverByOrderCode', ['order_code' => $orderCode]);

        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_code = ?");
        $stmt->execute([$orderCode]);
        $order = $stmt->fetch();

        if ($order) {
            $this->deliver($order['id']);
        }
    }

    public function deliver(string $orderId): void
    {
        $this->logger->info('deliver', ['order_id' => $orderId]);

        $lockKey = "delivery:lock:{$orderId}";

        $this->lockService->withLock($lockKey, function () use ($orderId) {
            $firstProvider = $this->options->getFirstProvider();
            $requestId = "req_{$orderId}-1";

            if (!$this->createDeliveryRecord($orderId, $requestId, $firstProvider->name)) {
                $this->handleExistingDelivery($orderId, $requestId);
                return;
            }

            $this->tryDeliver($orderId, $requestId, $firstProvider->name);
        });
    }

    private function createDeliveryRecord(string $orderId, string $requestId, string $provider): bool
    {
        try {
            $pdo = $this->db->getConnection();

            $stmt = $pdo->prepare(
                "INSERT INTO deliveries (order_id, request_id, provider, status)
                 VALUES (:order_id, :request_id, :provider, :pending_status)"
            );
            $stmt->execute([
                'order_id' => $orderId,
                'request_id' => $requestId,
                'provider' => $provider,
                'pending_status' => DeliveryStatus::Pending->value,
            ]);

            return true;

        } catch (\PDOException $e) {
            if ($e->errorInfo[0] === Database::UNIQUE_VIOLATION) {
                return false;
            }
            throw $e;
        }
    }

    private function handleExistingDelivery(string $orderId, string $requestId): void
    {
        $pdo = $this->db->getConnection();

        $stmt = $pdo->prepare(
            "SELECT * FROM deliveries WHERE request_id = ?"
        );
        $stmt->execute([$requestId]);
        $delivery = $stmt->fetch();

        if (!$delivery) {
            return;
        }

        if ($delivery['status'] === DeliveryStatus::Issued->value) {
            $this->completeOrderWithCode($orderId, $delivery['code']);
            return;
        }

        if ($delivery['status'] === DeliveryStatus::Timeout->value) {
            $this->retryWithBackoff($orderId, $requestId, $delivery['provider']);
            return;
        }

        if ($delivery['status'] === DeliveryStatus::Pending->value) {
            Coroutine::sleep(1);
            $this->handleExistingDelivery($orderId, $requestId);
            return;
        }

        $this->tryFallback($orderId, $delivery['provider']);
    }

    private function tryDeliver(string $orderId, string $requestId, string $provider): void
    {
        $this->logger->info('tryDeliver', [
            'order_id' => $orderId,
            'request_id' => $requestId,
            'provider' => $provider,
        ]);

        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            return;
        }

        $stmt = $pdo->prepare(
            "UPDATE orders SET status = :delivering_status, updated_at = NOW()
             WHERE id = :order_id AND status = :paid_status"
        );
        $stmt->execute([
            'delivering_status' => OrderStatus::Delivering->value,
            'order_id' => $orderId,
            'paid_status' => OrderStatus::Paid->value,
        ]);

        try {
            $result = $this->providerClient->issue(
                requestId: $requestId,
                sku: $order['sku'],
                orderCode: $order['order_code'],
                provider: $provider,
            );

            if ($result['status'] === 'ok') {
                $this->saveDeliveryResult($orderId, $requestId, $provider, $result['code']);
                $this->completeOrder($orderId, $provider, $requestId, $result['code']);

            } elseif ($result['reason'] === 'out_of_stock') {
                $this->handleOutOfStock($orderId, $provider, $requestId);

            } else {
                $this->handleProviderError($orderId, $requestId, $provider);
            }

        } catch (ProviderTimeoutException $e) {
            $this->logger->warning('Provider timeout', [
                'provider' => $provider,
                'request_id' => $requestId,
            ]);
            $this->handleProviderTimeout($orderId, $requestId, $provider);

        } catch (ProviderException $e) {
            $this->logger->error('Provider error', [
                'provider' => $provider,
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
            $this->handleProviderError($orderId, $requestId, $provider);
        }
    }

    private function handleProviderTimeout(string $orderId, string $requestId, string $provider): void
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare(
            "UPDATE deliveries SET status = :timeout_status, completed_at = NOW()
             WHERE request_id = :request_id"
        );
        $stmt->execute([
            'timeout_status' => DeliveryStatus::Timeout->value,
            'request_id' => $requestId,
        ]);

        $this->retryWithBackoff($orderId, $requestId, $provider);
    }

    private function retryWithBackoff(string $orderId, string $requestId, string $provider): void
    {
        $this->logger->info('retryWithBackoff', [
            'order_id' => $orderId,
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
                $order = $this->getOrder($orderId);

                $result = $this->providerClient->issue(
                    requestId: $requestId,
                    sku: $order['sku'],
                    orderCode: $order['order_code'],
                    provider: $provider,
                );

                $this->logger->info('Retry result', [
                    'result' => $result,
                ]);

                if ($result['status'] === 'ok') {
                    $this->logger->info('Saving delivery result', ['code' => $result['code']]);
                    $this->saveDeliveryResult($orderId, $requestId, $provider, $result['code']);

                    $this->logger->info('Completing order');
                    $this->completeOrder($orderId, $provider, $requestId, $result['code']);

                    $this->logger->info('Retry successful');
                    return;
                }

                $this->tryFallback($orderId, $provider);
                return;

            } catch (ProviderTimeoutException $e) {
                continue;

            } catch (ProviderException $e) {
                $this->tryFallback($orderId, $provider);
                return;
            }
        }

        $this->markDeliveryFailed($orderId, $requestId, $provider);
    }

    private function tryFallback(string $orderId, string $currentProvider): void
    {
        $nextProvider = $this->options->getNextProvider($currentProvider);

        if ($nextProvider === null) {
            $this->markDeliveryFailed($orderId, "req_{$orderId}-1", $currentProvider);
            return;
        }

        $fallbackRequestId = "req_{$orderId}-" . (array_search($nextProvider->name, array_keys($this->options->providers), true) + 1);

        if (!$this->createDeliveryRecord($orderId, $fallbackRequestId, $nextProvider->name)) {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare(
                "SELECT * FROM deliveries WHERE request_id = ?"
            );
            $stmt->execute([$fallbackRequestId]);
            $delivery = $stmt->fetch();

            if ($delivery && $delivery['status'] === DeliveryStatus::Issued->value) {
                $this->completeOrderWithCode($orderId, $delivery['code']);
            }
            return;
        }

        $this->tryDeliver($orderId, $fallbackRequestId, $nextProvider->name);
    }

    private function saveDeliveryResult(string $orderId, string $requestId, string $provider, string $code): void
    {
        $pdo = $this->db->getConnection();

        $stmt = $pdo->prepare(
            "UPDATE deliveries SET status = :issued_status, code = :code, completed_at = NOW()
             WHERE request_id = :request_id"
        );
        $stmt->execute([
            'issued_status' => DeliveryStatus::Issued->value,
            'code' => $code,
            'request_id' => $requestId,
        ]);

        $stmt = $pdo->prepare(
            "UPDATE keys_pool SET order_id = ?, status = 'issued', issued_at = NOW() WHERE code = ?"
        );
        $stmt->execute([$orderId, $code]);
    }

    private function completeOrder(string $orderId, string $provider, string $requestId, string $code): void
    {
        $pdo = $this->db->getConnection();

        $stmt = $pdo->prepare(
            "UPDATE orders
             SET status = :delivered_status,
                 delivered_code = :code,
                 provider = :provider,
                 provider_request_id = :request_id,
                 delivered_at = NOW(),
                 updated_at = NOW(),
                 version = version + 1
             WHERE id = :order_id
             AND status IN (:paid_status, :delivering_status)"
        );
        $stmt->execute([
            'delivered_status' => OrderStatus::Delivered->value,
            'code' => $code,
            'provider' => $provider,
            'request_id' => $requestId,
            'order_id' => $orderId,
            'paid_status' => OrderStatus::Paid->value,
            'delivering_status' => OrderStatus::Delivering->value,
        ]);

        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare(
                "SELECT delivered_code FROM orders WHERE id = ?"
            );
            $stmt->execute([$orderId]);
            $existing = $stmt->fetch();

            if ($existing && $existing['delivered_code'] !== $code) {
                $this->logger->critical('Double delivery detected', [
                    'order_id' => $orderId,
                    'existing_code' => $existing['delivered_code'],
                    'new_code' => $code,
                ]);

                $stmt = $pdo->prepare(
                    "UPDATE keys_pool
                     SET status = 'available', order_id = NULL
                     WHERE code = ? AND status = 'issued'"
                );
                $stmt->execute([$code]);
            }
        }
    }

    private function completeOrderWithCode(string $orderId, string $code): void
    {
        $pdo = $this->db->getConnection();

        $stmt = $pdo->prepare(
            "UPDATE orders
             SET status = :delivered_status,
                 delivered_code = :code,
                 delivered_at = NOW(),
                 updated_at = NOW(),
                 version = version + 1
             WHERE id = :order_id
             AND status IN (:paid_status, :delivering_status)"
        );
        $stmt->execute([
            'delivered_status' => OrderStatus::Delivered->value,
            'code' => $code,
            'order_id' => $orderId,
            'paid_status' => OrderStatus::Paid->value,
            'delivering_status' => OrderStatus::Delivering->value,
        ]);
    }

    private function handleOutOfStock(string $orderId, string $provider, string $requestId): void
    {
        $pdo = $this->db->getConnection();

        $stmt = $pdo->prepare(
            "UPDATE deliveries SET status = :error_status, code = :out_of_stock, completed_at = NOW()
             WHERE request_id = :request_id"
        );
        $stmt->execute([
            'error_status' => DeliveryStatus::Error->value,
            'out_of_stock' => 'out_of_stock',
            'request_id' => $requestId,
        ]);

        $stmt = $pdo->prepare(
            "UPDATE orders SET status = :out_status, updated_at = NOW(), version = version + 1
             WHERE id = :order_id AND status IN (:paid_status, :delivering_status)"
        );
        $stmt->execute([
            'out_status' => OrderStatus::OutOfStock->value,
            'order_id' => $orderId,
            'paid_status' => OrderStatus::Paid->value,
            'delivering_status' => OrderStatus::Delivering->value,
        ]);

        $this->tryFallback($orderId, $provider);
    }

    private function handleProviderError(string $orderId, string $requestId, string $provider): void
    {
        $pdo = $this->db->getConnection();

        $stmt = $pdo->prepare(
            "UPDATE deliveries SET status = :error_status, completed_at = NOW()
             WHERE request_id = :request_id"
        );
        $stmt->execute([
            'error_status' => DeliveryStatus::Error->value,
            'request_id' => $requestId,
        ]);

        $this->tryFallback($orderId, $provider);
    }

    private function markDeliveryFailed(string $orderId, string $requestId, string $provider): void
    {
        $pdo = $this->db->getConnection();

        $stmt = $pdo->prepare(
            "UPDATE orders SET status = :failed_status, updated_at = NOW(), version = version + 1
             WHERE id = :order_id AND status IN (:paid_status, :delivering_status)"
        );
        $stmt->execute([
            'failed_status' => OrderStatus::DeliveryFailed->value,
            'order_id' => $orderId,
            'paid_status' => OrderStatus::Paid->value,
            'delivering_status' => OrderStatus::Delivering->value,
        ]);

        $this->logger->error('Delivery failed', [
            'order_id' => $orderId,
            'provider' => $provider,
            'request_id' => $requestId,
        ]);
    }

    private function getOrder(string $orderId): array
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        return $stmt->fetch();
    }
}
