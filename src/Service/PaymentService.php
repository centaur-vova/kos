<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use App\DTO\PaymentProcessingResult;
use App\DTO\PaymentWebhook;
use App\Enum\OrderStatus;

final readonly class PaymentService
{
    public function __construct(
        private Database $db,
        private DeliveryService $deliveryService,
    ) {
    }

    public function process(PaymentWebhook $webhook): PaymentProcessingResult
    {
        return $this->db->transaction(function ($pdo) use ($webhook) {
            return $this->processInTransaction($pdo, $webhook);
        });
    }

    public function deliverByOrderCode(string $orderCode): void
    {
        $this->deliveryService->deliverByOrderCode($orderCode);
    }

    private function processInTransaction(\PDO $pdo, PaymentWebhook $webhook): PaymentProcessingResult
    {
        $eventId = $webhook->eventId;
        $orderCode = $webhook->orderCode;

        $stmt = $pdo->prepare(
            "SELECT id, status FROM payments WHERE event_id = ?"
        );
        $stmt->execute([$eventId]);
        $existingPayment = $stmt->fetch();

        if ($existingPayment) {
            return PaymentProcessingResult::alreadyProcessed($existingPayment['status']);
        }

        // Pessimistic lock for update
        $stmt = $pdo->prepare(
            "SELECT * FROM orders WHERE order_code = ? FOR UPDATE"
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
                $webhook->amount,
                $webhook->currency,
            ]);

            return PaymentProcessingResult::orphanPayment('Order not found, payment saved');
        }

        if (OrderStatus::tryFrom($order['status']) === OrderStatus::Delivered) {
            $stmt = $pdo->prepare(
                "INSERT INTO payments (event_id, order_id, status, amount, currency)
             VALUES (?, ?, 'duplicate_after_delivery', ?, ?)"
            );
            $stmt->execute([
                $eventId,
                $order['id'],
                $webhook->amount,
                $webhook->currency,
            ]);

            return PaymentProcessingResult::duplicateAfterDelivery();
        }

        if (OrderStatus::tryFrom($order['status']) === OrderStatus::PaymentFailed) {
            $stmt = $pdo->prepare(
                "INSERT INTO payments (event_id, order_id, status, amount, currency)
             VALUES (?, ?, 'late_payment', ?, ?)"
            );
            $stmt->execute([
                $eventId,
                $order['id'],
                $webhook->amount,
                $webhook->currency,
            ]);

            return PaymentProcessingResult::latePaymentAfterFailure();
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO payments (event_id, order_id, status, amount, currency)
             VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $eventId,
                $order['id'],
                $webhook->status,
                $webhook->amount,
                $webhook->currency,
            ]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? null) === Database::UNIQUE_VIOLATION) {
                return PaymentProcessingResult::alreadyProcessedRace();
            }
            throw $e;
        }

        if ($webhook->isPaid()) {
            $stmt = $pdo->prepare(
                "UPDATE orders
             SET status = :paid_status,
                 paid_at = NOW(),
                 updated_at = NOW(),
                 version = version + 1
             WHERE id = :order_id
             AND status = :created_status
             AND version = :current_version"
            );
            $stmt->execute([
                'paid_status' => OrderStatus::Paid->value,
                'order_id' => $order['id'],
                'created_status' => OrderStatus::Created->value,
                'current_version' => $order['version'],
            ]);

            if ($stmt->rowCount() === 1) {
                return PaymentProcessingResult::processed('pending');
            }

            return PaymentProcessingResult::processedByOther();

        } elseif ($webhook->isFailed()) {
            $stmt = $pdo->prepare(
                "UPDATE orders
             SET status = :failed_status,
                 updated_at = NOW(),
                 version = version + 1
             WHERE id = :order_id
             AND status = :created_status
             AND version = :current_version"
            );
            $stmt->execute([
                'failed_status' => OrderStatus::PaymentFailed->value,
                'order_id' => $order['id'],
                'created_status' => OrderStatus::Created->value,
                'current_version' => $order['version'],
            ]);

            return PaymentProcessingResult::paymentFailed();
        }

        return PaymentProcessingResult::unknownStatus();
    }
}
