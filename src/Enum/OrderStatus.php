<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case PaymentFailed = 'payment_failed';
    case OutOfStock = 'out_of_stock';
    case DeliveryFailed = 'delivery_failed';

    public function isFinal(): bool
    {
        return in_array($this, [
            self::Delivered,
            self::PaymentFailed,
            self::OutOfStock,
        ], true);
    }

    public function isRecoverable(): bool
    {
        return in_array($this, [
            self::OutOfStock,
            self::DeliveryFailed,
        ], true);
    }

    public function canTransitionTo(self $newStatus): bool
    {
        return match($this) {
            self::Created => in_array($newStatus, [self::Paid, self::PaymentFailed], true),
            self::Paid => in_array($newStatus, [self::Delivering, self::OutOfStock, self::DeliveryFailed], true),
            self::Delivering => in_array($newStatus, [self::Delivered, self::OutOfStock, self::DeliveryFailed], true),
            // OutOfStock — восстановимое состояние, после пополнения остатков можно повторить выдачу
            self::OutOfStock => $newStatus === self::Delivering,
            self::DeliveryFailed => $newStatus === self::Delivering,
            self::Delivered, self::PaymentFailed => false,
        };
    }
}
