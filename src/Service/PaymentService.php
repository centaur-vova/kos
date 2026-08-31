<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Options;
use App\Database;
use App\Enum\OrderStatus;
use App\Enum\PaymentProcessingStatus;
use App\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;

final readonly class PaymentService
{
    public function __construct(
        private Database $db,
        private DeliveryService $deliveryService,
        private StorageInterface $storage,
        private LoggerInterface $logger,
        private Options $options,
    ) {
    }

    public function processWebhook(array $payload): array
    {
        $eventId = $payload['event_id'];
        $orderCode = $payload['order_id'];

        $this->logger->info('Processing webhook', [
            'event_id' => $eventId,
            'order_code' => $orderCode,
        ]);

        $eventKey = "payment:event:{$eventId}";

        if ($this->storage->has($eventKey)) {
            $this->logger->info('Webhook already processed', ['event_id' => $eventId]);
            return ['status' => PaymentProcessingStatus::AlreadyProcessed->value];
        }

        $this->storage->set($eventKey, 'processing', $this->options->paymentIdempotencyTtlSec);

        $result = $this->db->transaction(function ($pdo) use ($payload, $eventId, $orderCode) {
            return $this->processInTransaction($pdo, $payload, $eventId, $orderCode);
        });

        if ($result['status'] === PaymentProcessingStatus::Processed->value && $payload['status'] === 'paid') {
            Coroutine::create(function () use ($orderCode) {
                $this->deliveryService->deliverByOrderCode($orderCode);
            });
        }

        return $result;
    }

    private function processInTransaction(\PDO $pdo, array $payload, string $eventId, string $orderCode): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, status FROM payments WHERE event_id = ?"
        );
        $stmt->execute([$eventId]);
        $existingPayment = $stmt->fetch();

        if ($existingPayment) {
            return [
                'status' => PaymentProcessingStatus::AlreadyProcessed->value,
                'payment_status' => $existingPayment['status'],
            ];
        }

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
                'status' => PaymentProcessingStatus::OrphanPayment->value,
                'message' => 'Order not found, payment saved',
            ];
        }

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
                'status' => PaymentProcessingStatus::DuplicateAfterDelivery->value,
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
                'status' => PaymentProcessingStatus::LatePaymentAfterFailure->value,
            ];
        }

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
            if (($e->errorInfo[0] ?? null) === Database::UNIQUE_VIOLATION) {
                return [
                    'status' => PaymentProcessingStatus::AlreadyProcessedRace->value,
                ];
            }
            throw $e;
        }

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
                    'status' => PaymentProcessingStatus::Processed->value,
                    'delivery' => 'pending',
                ];
            }

            return [
                'status' => PaymentProcessingStatus::ProcessedByOther->value,
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
                'status' => PaymentProcessingStatus::Processed->value,
            ];
        }

        return [
            'status' => PaymentProcessingStatus::UnknownStatus->value,
        ];
    }
}
