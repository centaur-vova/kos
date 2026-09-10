<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Repository\OrderItemRepository;
use App\Domain\Repository\OrderRepository;
use App\Domain\Repository\RefundRepository;
use App\Enum\OrderEventType;
use App\Enum\OrderItemStatus;
use App\Enum\OrderStatus;
use App\Storage\LockerInterface;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine\Barrier;
use Swoole\Coroutine;

final readonly class DeliveryService
{
    public function __construct(
        private OrderItemRepository $orderItemRepository,
        private OrderRepository $orderRepository,
        private RefundRepository $refundRepository,
        private ItemDeliveryProcessor $itemProcessor,
        private EventSourcingService $eventSourcingService,
        private LockerInterface $locker,
        private LoggerInterface $logger,
    ) {
    }

    public function deliverByOrderCode(string $orderCode): void
    {
        $this->logger->debug('deliverByOrderCode invoked', ['order_code' => $orderCode]);
        $order = $this->orderRepository->findByOrderCode($orderCode);
        if ($order) {
            $this->deliver($order['id']);
        }
    }

    public function deliver(string $orderId): void
    {
        $this->logger->info('Starting parallel order delivery saga', ['order_id' => $orderId]);
        $lockKey = "delivery:lock:{$orderId}";

        $acquired = $this->locker->withLock($lockKey, function () use ($orderId) {
            // Выбираем и pending, и застрявшие в процессе 'delivering' для поддержки восстановления (Пункт 5 ТЗ)
            $items = $this->orderItemRepository->findUnfinishedByOrderId($orderId);
            if (!empty($items)) {
                // Параллельная выдача позиций через корутины с барьером
                $barrier = Barrier::make();

                foreach ($items as $item) {
                    // Каждый товар из заказа начинает выбивать свой цифровой код параллельно!
                    // $barrier в use для учета refcount (магия автоматической защелки Swoole)
                    Coroutine::create(function () use ($item, $barrier) {
                        $this->itemProcessor->process($item);
                    });
                }

                // Асинхронно ждем, пока завершатся абсолютно все корутины выдачи айтемов
                Barrier::wait($barrier);
            }

            // Финализируем финансовые итоги заказа (Пункт 2 и 3 ТЗ)
            $this->finalizeOrderState($orderId);
        });

        if (!$acquired) {
            $this->logger->warning('Delivery lock busy, skipping', [
                'order_id' => $orderId,
                'lock_key' => $lockKey,
            ]);
        }
    }

    private function finalizeOrderState(string $orderId): void
    {
        $stats = $this->orderItemRepository->countByOrderId($orderId);
        $this->logger->info('Order processing stats collected', ['order_id' => $orderId, 'stats' => $stats]);

        // Сценарий 1: Все товары успешно выданы
        if ($stats['delivered'] === $stats['total']) {
            $this->orderRepository->updateStatus($orderId, OrderStatus::Delivered);

            // Логируем эвент закрытия Саги!
            $this->eventSourcingService->record($orderId, OrderEventType::OrderDelivered, [
                'delivered_at' => date('c'),
                'total_items' => $stats['total']
            ]);
            return;
        }

        // Сценарий 2: Часть товаров упала — запускаем рефанды
        $failedItems = $this->orderItemRepository->findFailedByOrderId($orderId);
        foreach ($failedItems as $item) {
            $this->refundRepository->createForItem($item['id'], (int)$item['price_cents']);
            $this->orderItemRepository->updateStatus($item['id'], OrderItemStatus::Refunded);

            // Логируем поштучный рефанд для сведения балансов!
            $this->eventSourcingService->record($orderId, OrderEventType::ItemRefunded, [
                'order_item_id' => $item['id'],
                'sku' => $item['sku'],
                'refund_amount_cents' => (int)$item['price_cents']
            ]);
        }

        // Переводим сам заказ в правильный итоговый статус и пишем финальный эвент
        if ($stats['delivered'] > 0) {
            $this->orderRepository->updateStatus($orderId, OrderStatus::PartiallyDelivered);

            $this->eventSourcingService->record($orderId, OrderEventType::OrderPartiallyDelivered, [
                'total_items' => $stats['total'],
                'delivered_count' => $stats['delivered'],
                'refunded_count' => count($failedItems)
            ]);
        } else {
            $this->orderRepository->updateStatus($orderId, OrderStatus::DeliveryFailed);

            $this->eventSourcingService->record($orderId, OrderEventType::OrderDeliveryFailed, [
                'reason' => 'All providers out of stock or timed out',
                'failed_at' => date('c')
            ]);
        }
    }
}
