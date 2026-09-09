<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Repository\OrderEventRepository;
use App\Enum\OrderEventType;

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
                    $state['status'] = 'created';
                    $state['items'] = $data['items'] ?? [];
                    break;

                case OrderEventType::OrderPaid->value:
                    $state['status'] = 'paid';
                    $state['paid_at'] = $data['paid_at'] ?? null;
                    break;

                case OrderEventType::ItemDelivered->value:
                    $state['items'] = $this->updateItemStatus($state['items'], (string)$data['item_id'], 'delivered', $data);
                    break;

                case OrderEventType::ItemRefunded->value:
                    $state['items'] = $this->updateItemStatus($state['items'], (string)$data['item_id'], 'refunded', $data);
                    $state['refunds'][] = $data;
                    break;

                case OrderEventType::OrderDelivered->value:
                    $state['status'] = 'delivered';
                    $state['delivered_at'] = $data['delivered_at'] ?? null;
                    break;

                case OrderEventType::OrderPartiallyDelivered->value:
                    $state['status'] = 'partially_delivered';
                    break;

                case OrderEventType::OrderDeliveryFailed->value:
                    $state['status'] = 'delivery_failed';
                    break;
            }
        }

        return $state;
    }

    public function getStateAtUntilId(string $orderId, int $untilEventId): array
    {
        $events = $this->eventRepository->findByOrderIdUntilId($orderId, $untilEventId);

        $state = [
            'status' => 'created',
            'paid_at' => null,
            'delivered_at' => null,
            'items' => [],
            'refunds' => [],
            'delivered_items_count' => 0,
            'refunded_amount_cents' => 0
        ];

        foreach ($events as $event) {
            $type = $event['event_type'];
            $data = json_decode($event['event_data'], true) ?? [];

            switch ($type) {
                case 'order.created':
                    $state['status'] = 'created';
                    $state['items'] = $data['items'] ?? [];
                    break;
                case 'order.paid':
                    $state['status'] = 'paid';
                    $state['paid_at'] = $data['paid_at'] ?? null;
                    break;
                case 'item.delivered':
                    $state['items'] = $this->updateItemStatus($state['items'], (string)$data['item_id'], 'delivered', $data);
                    $state['delivered_items_count']++;
                    break;
                case 'item.refunded':
                    $state['items'] = $this->updateItemStatus($state['items'], (string)$data['item_id'], 'refunded', $data);
                    $state['refunds'][] = $data;
                    $state['refunded_amount_cents'] += ($data['refund_amount_cents'] ?? 0);
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

    public function getStateAtPaidStep(string $orderId): array
    {
        // Выгребаем хронологический лог эвентов из Postgres
        $events = $this->eventRepository->findByOrderId($orderId);

        $state = [
            'status' => 'created',
            'paid_at' => null,
            'delivered_at' => null,
            'items' => [],
            'refunds' => [],
            'delivered_items_count' => 0,
            'refunded_amount_cents' => 0
        ];

        foreach ($events as $event) {
            $type = $event['event_type'];
            $data = json_decode($event['event_data'], true) ?? [];

            if ($type === 'order.created') {
                $state['status'] = 'created';
                $state['items'] = $data['items'] ?? [];
            }

            if ($type === 'order.paid') {
                $state['status'] = 'paid';
                $state['paid_at'] = $data['paid_at'] ?? null;
                // ФИНАЛЬНАЯ ТOЧКА: Оплата прошла, дальше историю для этого среза не крутим!
                break;
            }
        }

        return $state;
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
