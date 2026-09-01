<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\Enum\OrderStatus;
use PHPUnit\Framework\TestCase;

final class OrderStatusTest extends TestCase
{
    public function testCanTransition(): void
    {
        $this->assertTrue(OrderStatus::Created->canTransitionTo(OrderStatus::Paid));
        $this->assertFalse(OrderStatus::Delivered->canTransitionTo(OrderStatus::Paid));
    }

    public function testIsFinal(): void
    {
        $this->assertTrue(OrderStatus::Delivered->isFinal());
        $this->assertFalse(OrderStatus::Paid->isFinal());
    }
}
