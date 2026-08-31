<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use App\Enum\OrderStatus;
use App\Storage\StorageInterface;
use Swoole\Coroutine;

class PaymentService
{
    public function __construct(
        private Database $db,
        private DeliveryService $deliveryService,
        private StorageInterface $storage,
    ) {
    }

    public function processWebhook(array $payload): array
    {
        $eventId = $payload['event_id'];
        $orderCode = $payload['order_id'];

        // Идемпотентность через Redis
        $eventKey = "payment:event:{$eventId}";

        if ($this->storage->has($eventKey)) {
            return ['status' => 'already_processed'];
        }

        // Помечаем как обрабатываемый
        $this->storage->set($eventKey, 'processing', 86400);

        $result = $this->db->transaction(function ($pdo) use ($payload, $eventId, $orderCode) {
            return $this->processInTransaction($pdo, $payload, $eventId, $orderCode);
        });

        // Запускаем выдачу после завершения транзакции
        if ($result['status'] === 'processed' && $payload['status'] === 'paid') {
            Coroutine::create(function () use ($orderCode) {
                $this->deliveryService->deliverByOrderCode($orderCode);
            });
        }

        return $result;
    }

    private function processInTransaction(\PDO $pdo, array $payload, string $eventId, string $orderCode): array
    {
        // Проверяем идемпотентность в БД
        $stmt = $pdo->prepare(
            "SELECT id, status FROM payments WHERE event_id = ?"
        );
        $stmt->execute([$eventId]);
        $existingPayment = $stmt->fetch();

        if ($existingPayment) {
            return [
                'status' => 'already_processed',
                'payment_status' => $existingPayment['status'],
            ];
        }

        // Находим заказ
        $stmt = $pdo->prepare(
            "SELECT * FROM orders WHERE order_code = ?"
        );
        $stmt->execute([$orderCode]);
        $order = $stmt->fetch();

        if (!$order) {
            $stmt = $pdo->prepare(
                "INSERT INTO payments (event_id, status, amount, currency)
                 VALUES (?, 'orphan', ?, ?)"
            );
            $stmt->execute([
                $eventId,
                $payload['amount'],
                $payload['currency'] ?? 'RUB',
            ]);

            return [
                'status' => 'orphan_payment',
                'message' => 'Order not found, payment saved',
            ];
        }

        // Проверяем статус заказа
        if (OrderStatus::tryFrom($order['status']) === OrderStatus::Delivered) {
            $stmt = $pdo->prepare(
                "INSERT INTO payments (event_id, order_id, status, amount, currency)
                 VALUES (?, ?, 'duplicate_after_delivery', ?, ?)"
            );
            $stmt->execute([
                $eventId,
                $order['id'],
                $payload['amount'],
                $payload['currency'] ?? 'RUB',
            ]);

            return [
                'status' => 'duplicate_after_delivery',
            ];
        }

        if (OrderStatus::tryFrom($order['status']) === OrderStatus::PaymentFailed) {
            $stmt = $pdo->prepare(
                "INSERT INTO payments (event_id, order_id, status, amount, currency)
                 VALUES (?, ?, 'late_payment', ?, ?)"
            );
            $stmt->execute([
                $eventId,
                $order['id'],
                $payload['amount'],
                $payload['currency'] ?? 'RUB',
            ]);

            return [
                'status' => 'late_payment_after_failure',
            ];
        }

        // Сохраняем платёж
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO payments (event_id, order_id, status, amount, currency)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $eventId,
                $order['id'],
                $payload['status'],
                $payload['amount'],
                $payload['currency'] ?? 'RUB',
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === Database::UNIQUE_VIOLATION) {
                return [
                    'status' => 'already_processed_race',
                ];
            }
            throw $e;
        }

        // Обновляем статус заказа
        if ($payload['status'] === 'paid') {
            $stmt = $pdo->prepare(
                "UPDATE orders
                 SET status = :paid_status,
                     paid_at = NOW(),
                     updated_at = NOW(),
                     version = version + 1
                 WHERE id = :order_id
                 AND status = :created_status"
            );
            $stmt->execute([
                'paid_status' => OrderStatus::Paid->value,
                'order_id' => $order['id'],
                'created_status' => OrderStatus::Created->value,
            ]);

            if ($stmt->rowCount() === 1) {
                return [
                    'status' => 'processed',
                    'delivery' => 'pending',
                ];
            }

            return [
                'status' => 'processed_by_other',
            ];

        } elseif ($payload['status'] === 'failed') {
            $stmt = $pdo->prepare(
                "UPDATE orders
                 SET status = :failed_status,
                     updated_at = NOW(),
                     version = version + 1
                 WHERE id = :order_id
                 AND status = :created_status"
            );
            $stmt->execute([
                'failed_status' => OrderStatus::PaymentFailed->value,
                'order_id' => $order['id'],
                'created_status' => OrderStatus::Created->value,
            ]);

            return [
                'status' => 'payment_failed',
            ];
        }

        return [
            'status' => 'unknown_payment_status',
        ];
    }
}
