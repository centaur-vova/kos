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

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Delivered,
            self::Refunded,
        ], true);
    }

    public function isRecoverable(): bool
    {
        return in_array($this, [
            self::OutOfStock,
            self::DeliveryFailed,
        ], true);
    }
}
