<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use App\Exception\Provider\ProviderException;
use App\Exception\Provider\ProviderTimeoutException;
use App\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;

class DeliveryService
{
    private const MAX_RETRIES = 3;
    private const RETRY_DELAYS_MS = [1000, 3000, 5000];
    private const PROVIDER_TIMEOUT_MS = 5000;

    public function __construct(
        private Database $db,
        private ProviderClient $providerClient,
        private StorageInterface $storage,
        private LoggerInterface $logger,
    ) {
    }

    public function deliverByOrderCode(string $orderCode): void
    {
        $this->logger->info("deliverByOrderCode: {$orderCode}");

        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_code = ?");
        $stmt->execute([$orderCode]);
        $order = $stmt->fetch();

        $this->logger->info('Order found', $order);

        if ($order) {
            $this->deliver($order['id']);
        }
    }

    public function deliver(string $orderId): void
    {
        $this->logger->info("deliver: {$orderId}");

        // Блокировка через Redis
        $lockKey = "delivery:lock:{$orderId}";
        $this->logger->info("lockKey: {$lockKey}");

        if (!$this->storage->set($lockKey, 'locked', 30)) {
            $this->logger->info("Failed to acquire lock");
            return;
        }

        $this->logger->info("Failed to acquire lock");

        try {
            $requestId = "req_{$orderId}-1";
            $this->logger->info("requestId: {$requestId}");

            if (!$this->createDeliveryRecord($orderId, $requestId, 'A')) {
                $this->logger->info("Delivery record exists");
                $this->handleExistingDelivery($orderId, $requestId);
                return;
            }

            $this->logger->info("Delivery record created");
            $this->tryDeliver($orderId, $requestId, 'A');
        } finally {
            $this->storage->del($lockKey);
            $this->logger->info("Lock released");
        }
    }

    private function createDeliveryRecord(string $orderId, string $requestId, string $provider): bool
    {
        $this->logger->info('createDeliveryRecord', [
            'request_id' => $requestId,
        ]);

        try {
            $pdo = $this->db->getConnection();
            $this->logger->info('PDO connected');

            $stmt = $pdo->prepare(
                "INSERT INTO deliveries (order_id, request_id, provider, status)
                 VALUES (?, ?, ?, 'pending')"
            );
            $this->logger->info("Statement prepared");

            $stmt->execute([$orderId, $requestId, $provider]);
            $this->logger->info("Statement executed");

            return true;

        } catch (\PDOException $e) {
            $this->logger->error('PDO exception', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            if ($e->getCode() === Database::UNIQUE_VIOLATION) {
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

        if ($delivery['status'] === 'issued') {
            $this->completeOrderWithCode($orderId, $delivery['code']);
            return;
        }

        if ($delivery['status'] === 'timeout') {
            $this->retryWithBackoff($orderId, $requestId, $delivery['provider']);
            return;
        }

        if ($delivery['status'] === 'pending') {
            Coroutine::sleep(1);
            $this->handleExistingDelivery($orderId, $requestId);
            return;
        }

        if ($delivery['provider'] === 'A') {
            $this->tryFallbackToProviderB($orderId);
        }
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
            "UPDATE orders SET status = 'delivering', updated_at = NOW()
             WHERE id = ? AND status = 'paid'"
        );
        $stmt->execute([$orderId]);

        try {
            $result = $this->providerClient->issue(
                requestId: $requestId,
                sku: $order['sku'],
                orderCode: $order['order_code'],
                provider: $provider,
                timeoutMs: self::PROVIDER_TIMEOUT_MS
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
            $this->handleProviderTimeout($orderId, $requestId, $provider);

        } catch (ProviderException $e) {
            $this->handleProviderError($orderId, $requestId, $provider);
        }
    }

    private function handleProviderTimeout(string $orderId, string $requestId, string $provider): void
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare(
            "UPDATE deliveries SET status = 'timeout', completed_at = NOW()
             WHERE request_id = ?"
        );
        $stmt->execute([$requestId]);

        $this->retryWithBackoff($orderId, $requestId, $provider);
    }

    private function retryWithBackoff(string $orderId, string $requestId, string $provider): void
    {
        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            $delayMs = self::RETRY_DELAYS_MS[$attempt] ?? 5000;
            Coroutine::sleep($delayMs / 1000);

            try {
                $order = $this->getOrder($orderId);

                $result = $this->providerClient->issue(
                    requestId: $requestId,
                    sku: $order['sku'],
                    orderCode: $order['order_code'],
                    provider: $provider,
                    timeoutMs: self::PROVIDER_TIMEOUT_MS
                );

                if ($result['status'] === 'ok') {
                    $this->saveDeliveryResult($orderId, $requestId, $provider, $result['code']);
                    $this->completeOrder($orderId, $provider, $requestId, $result['code']);
                    return;
                }

                if ($provider === 'A') {
                    $this->tryFallbackToProviderB($orderId);
                }
                return;

            } catch (ProviderTimeoutException $e) {
                continue;

            } catch (ProviderException $e) {
                if ($provider === 'A') {
                    $this->tryFallbackToProviderB($orderId);
                }
                return;
            }
        }

        $this->markDeliveryFailed($orderId, $requestId, $provider);
    }

    private function tryFallbackToProviderB(string $orderId): void
    {
        $fallbackRequestId = "req_{$orderId}-2";

        if (!$this->createDeliveryRecord($orderId, $fallbackRequestId, 'B')) {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare(
                "SELECT * FROM deliveries WHERE request_id = ?"
            );
            $stmt->execute([$fallbackRequestId]);
            $delivery = $stmt->fetch();

            if ($delivery && $delivery['status'] === 'issued') {
                $this->completeOrderWithCode($orderId, $delivery['code']);
            }
            return;
        }

        $this->tryDeliver($orderId, $fallbackRequestId, 'B');
    }

    private function saveDeliveryResult(string $orderId, string $requestId, string $provider, string $code): void
    {
        $pdo = $this->db->getConnection();

        $stmt = $pdo->prepare(
            "UPDATE deliveries
             SET status = 'issued', code = ?, completed_at = NOW()
             WHERE request_id = ?"
        );
        $stmt->execute([$code, $requestId]);

        $stmt = $pdo->prepare(
            "UPDATE keys_pool
             SET order_id = ?, status = 'issued', issued_at = NOW()
             WHERE code = ?"
        );
        $stmt->execute([$orderId, $code]);
    }

    private function completeOrder(string $orderId, string $provider, string $requestId, string $code): void
    {
        $pdo = $this->db->getConnection();

        $stmt = $pdo->prepare(
            "UPDATE orders
             SET status = 'delivered',
                 delivered_code = ?,
                 provider = ?,
                 provider_request_id = ?,
                 delivered_at = NOW(),
                 updated_at = NOW(),
                 version = version + 1
             WHERE id = ?
             AND status IN ('paid', 'delivering')"
        );
        $stmt->execute([$code, $provider, $requestId, $orderId]);

        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare(
                "SELECT delivered_code FROM orders WHERE id = ?"
            );
            $stmt->execute([$orderId]);
            $existing = $stmt->fetch();

            if ($existing && $existing['delivered_code'] !== $code) {
                $this->logger->info("CRITICAL: Double delivery detected for order {$orderId}");

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
             SET status = 'delivered',
                 delivered_code = ?,
                 delivered_at = NOW(),
                 updated_at = NOW(),
                 version = version + 1
             WHERE id = ?
             AND status IN ('paid', 'delivering')"
        );
        $stmt->execute([$code, $orderId]);
    }

    private function handleOutOfStock(string $orderId, string $provider, string $requestId): void
    {
        $pdo = $this->db->getConnection();

        $stmt = $pdo->prepare(
            "UPDATE deliveries SET status = 'error', code = 'out_of_stock', completed_at = NOW()
             WHERE request_id = ?"
        );
        $stmt->execute([$requestId]);

        $stmt = $pdo->prepare(
            "UPDATE orders SET status = 'out_of_stock', updated_at = NOW(), version = version + 1
             WHERE id = ? AND status IN ('paid', 'delivering')"
        );
        $stmt->execute([$orderId]);

        if ($provider === 'A') {
            $this->tryFallbackToProviderB($orderId);
        }
    }

    private function handleProviderError(string $orderId, string $requestId, string $provider): void
    {
        $pdo = $this->db->getConnection();

        $stmt = $pdo->prepare(
            "UPDATE deliveries SET status = 'error', completed_at = NOW()
             WHERE request_id = ?"
        );
        $stmt->execute([$requestId]);

        if ($provider === 'A') {
            $this->tryFallbackToProviderB($orderId);
        } else {
            $this->markDeliveryFailed($orderId, $requestId, $provider);
        }
    }

    private function markDeliveryFailed(string $orderId, string $requestId, string $provider): void
    {
        $pdo = $this->db->getConnection();

        $stmt = $pdo->prepare(
            "UPDATE orders SET status = 'delivery_failed', updated_at = NOW(), version = version + 1
             WHERE id = ? AND status IN ('paid', 'delivering')"
        );
        $stmt->execute([$orderId]);

        $this->logger->info("Delivery failed for order {$orderId}, provider {$provider}, request {$requestId}");
    }

    private function getOrder(string $orderId): array
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        return $stmt->fetch();
    }
}
