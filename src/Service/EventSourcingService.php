<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Repository\OrderEventRepository;
use App\Enum\OrderEventType;
use App\Enum\OrderItemStatus;
use App\Enum\OrderStatus;

final readonly class EventSourcingService
{
    public function __construct(
        private OrderEventRepository $eventRepository,
    ) {
    }

    public function record(string $orderId, OrderEventType $eventType, array $eventData): void
    {
        $this->eventRepository->append($orderId, $eventType, $eventData);
    }

    public function getEventStreamByOrderId(string $orderId): array
    {
        return $this->eventRepository->findByOrderId($orderId);
    }

    public function getStateAt(string $orderId, string $date): array
    {
        $events = $this->eventRepository->findByOrderIdUntil($orderId, $date);

        // Проекция состояния из событий
        $state = [
            'status' => 'created',
            'paid_at' => null,
            'delivered_at' => null,
            'items' => [],
            'refunds' => [],
        ];

        foreach ($events as $event) {
            $type = $event['event_type'];
            $data = json_decode($event['event_data'], true) ?? [];

            // НАСТОЯЩИЙ SENIOR-КОНТРАКТ: Мапим строго по .value нашего Backed Enum!
            switch ($type) {
                case OrderEventType::OrderCreated->value:
                    $state['status'] = OrderStatus::Created->value;
                    $state['items'] = $data['items'] ?? [];
                    break;

                case OrderEventType::OrderPaid->value:
                    $state['status'] = OrderStatus::Paid->value;
                    $state['paid_at'] = $data['paid_at'] ?? null;
                    break;

                case OrderEventType::ItemDelivered->value:
                    $state['items'] = $this->updateItemStatus(
                        $state['items'],
                        (string)$data['item_id'],
                        OrderItemStatus::Delivered->value,
                        $data
                    );
                    break;

                case OrderEventType::ItemRefunded->value:
                    $state['items'] = $this->updateItemStatus(
                        $state['items'],
                        (string)$data['item_id'],
                        OrderItemStatus::Refunded->value,
                        $data
                    );
                    $state['refunds'][] = $data;
                    break;

                case OrderEventType::OrderDelivered->value:
                    $state['status'] = OrderStatus::Delivered->value;
                    $state['delivered_at'] = $data['delivered_at'] ?? null;
                    break;

                case OrderEventType::OrderPartiallyDelivered->value:
                    $state['status'] = OrderStatus::PartiallyDelivered->value;
                    break;

                case OrderEventType::OrderDeliveryFailed->value:
                    $state['status'] = OrderStatus::DeliveryFailed->value;
                    break;
            }
        }

        return $state;
    }

    private function updateItemStatus(array $items, string $itemId, string $status, array $data): array
    {
        foreach ($items as &$item) {
            if ((string)$item['id'] === $itemId) {
                $item['status'] = $status;
                if (isset($data['code'])) {
                    $item['delivered_code'] = $data['code'];
                }
                if (isset($data['provider'])) {
                    $item['provider'] = $data['provider'];
                }
            }
        }
        unset($item);

        return $items;
    }

    public function getFinancialReport(string $fromDate, string $toDate): array
    {
        $events = $this->eventRepository->findByDateRange($fromDate, $toDate);

        $report = [
            'total_received_cents' => 0,
            'total_refunded_cents' => 0,
            'balance_cents' => 0,
            'events_count' => count($events),
        ];

        foreach ($events as $event) {
            $data = json_decode($event['event_data'], true) ?? [];

            if ($event['event_type'] === OrderEventType::OrderPaid->value) {
                $report['total_received_cents'] += (int)($data['amount_cents'] ?? 0);
            }

            if ($event['event_type'] === OrderEventType::ItemRefunded->value) {
                $report['total_refunded_cents'] += (int)($data['refund_amount_cents'] ?? 0);
            }
        }

        $report['balance_cents'] = $report['total_received_cents'] - $report['total_refunded_cents'];

        return $report;
    }

}
