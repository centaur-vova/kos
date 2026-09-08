<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderItemStatus: string
{
    case Pending = 'pending';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case Refunded = 'refunded';
    case OutOfStock = 'out_of_stock';
    case DeliveryFailed = 'delivery_failed';
}
