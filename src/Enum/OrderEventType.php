<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderEventType: string
{
    case OrderCreated = 'order.created';
    case OrderPaid = 'order.paid';
    case OrderPaymentFailed = 'order.payment_failed';
    case ItemDelivered = 'item.delivered';
    case ItemDeliveryFailed = 'item.delivery_failed';
    case ItemRefunded = 'item.refunded';
    case OrderDelivered = 'order.delivered';
    case OrderPartiallyDelivered = 'order.partially_delivered';
    case OrderDeliveryFailed = 'order.delivery_failed';
    case OrderOutOfStock = 'order.out_of_stock';
}
