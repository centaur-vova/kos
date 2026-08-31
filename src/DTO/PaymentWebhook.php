<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class PaymentWebhook
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $orderCode,
        public readonly string $status,
        public readonly float $amount,
        public readonly string $currency = 'RUB',
        public readonly ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            eventId: $data['event_id'],
            orderCode: $data['order_id'],
            status: $data['status'],
            amount: (float)$data['amount'],
            currency: $data['currency'] ?? 'RUB',
            createdAt: isset($data['created_at'])
                ? new \DateTimeImmutable($data['created_at'])
                : null,
        );
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
