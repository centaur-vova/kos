<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\PaymentWebhook;
use PHPUnit\Framework\TestCase;

final class PaymentWebhookTest extends TestCase
{
    public function testFromArray(): void
    {
        $webhook = PaymentWebhook::fromArray([
            'event_id' => 'evt_1',
            'order_id' => 'ord_1',
            'status' => 'paid',
            'amount' => 1290,
        ]);

        $this->assertSame('evt_1', $webhook->eventId);
        $this->assertSame('ord_1', $webhook->orderCode);
        $this->assertTrue($webhook->isPaid());
    }

    public function testMissingFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PaymentWebhook::fromArray(['event_id' => 'evt_1']);
    }
}
