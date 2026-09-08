<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Repository\OrderRepository;
use App\Domain\Repository\PaymentRepository;
use App\DTO\PaymentProcessingResult;
use App\DTO\PaymentWebhook;
use App\Enum\OrderStatus;
use Psr\Log\LoggerInterface;

final readonly class PaymentService
{
    public function __construct(
        private OrderRepository $orderRepository,
        private PaymentRepository $paymentRepository,
        private DeliveryService $deliveryService,
        private LoggerInterface $logger,
    ) {
    }

    public function process(PaymentWebhook $webhook): PaymentProcessingResult
    {
        $this->logger->info('Processing payment', [
            'event_id' => $webhook->eventId,
            'order_code' => $webhook->orderCode,
        ]);

        // Проверяем идемпотентность
        if ($this->paymentRepository->exists($webhook->eventId)) {
            return PaymentProcessingResult::alreadyProcessed('already_processed');
        }

        // Блокируем заказ
        $order = $this->orderRepository->findByOrderCodeForUpdate($webhook->orderCode);

        if (!$order) {
            $this->paymentRepository->saveOrphan($webhook);
            return PaymentProcessingResult::orphanPayment('Order not found');
        }

        // Проверяем статус заказа
        $currentStatus = OrderStatus::tryFrom($order['status']);

        if ($currentStatus === OrderStatus::Delivered) {
            $this->paymentRepository->saveDuplicate($webhook, $order['id']);
            return PaymentProcessingResult::duplicateAfterDelivery();
        }

        if ($currentStatus === OrderStatus::PaymentFailed) {
            $this->paymentRepository->saveLate($webhook, $order['id']);
            return PaymentProcessingResult::latePaymentAfterFailure();
        }

        // Сохраняем платёж
        try {
            $this->paymentRepository->save($webhook, $order['id']);
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                return PaymentProcessingResult::alreadyProcessedRace();
            }
            throw $e;
        }

        // Обновляем статус заказа
        if ($webhook->isPaid()) {
            $updated = $this->orderRepository->updateStatusOptimistic(
                $order['id'],
                OrderStatus::Paid,
                OrderStatus::Created,
                $order['version'],
            );

            if ($updated) {
                return PaymentProcessingResult::processed('pending');
            }

            return PaymentProcessingResult::processedByOther();
        }

        if ($webhook->isFailed()) {
            $this->orderRepository->updateStatusOptimistic(
                $order['id'],
                OrderStatus::PaymentFailed,
                OrderStatus::Created,
                $order['version'],
            );

            return PaymentProcessingResult::paymentFailed();
        }

        return PaymentProcessingResult::unknownStatus();
    }

    public function deliverByOrderCode(string $orderCode): void
    {
        $order = $this->orderRepository->findByOrderCode($orderCode);

        if ($order) {
            $this->deliveryService->deliver($order['id']);
        }
    }
}
