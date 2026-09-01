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
        $this->validate();
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['event_id'], $data['order_id'], $data['status'], $data['amount'])) {
            throw new \InvalidArgumentException('Missing required fields for PaymentWebhook DTO');
        }

        return new self(
            eventId: (string)$data['event_id'],
            orderCode: (string)$data['order_id'],
            status: (string)$data['status'],
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

    private function validate(): void
    {
        if (empty($this->eventId)) {
            throw new \InvalidArgumentException('event_id is required');
        }

        if (empty($this->orderCode)) {
            throw new \InvalidArgumentException('order_id is required');
        }

        if (empty($this->status)) {
            throw new \InvalidArgumentException('status is required');
        }

        if ($this->amount <= 0) {
            throw new \InvalidArgumentException('amount must be positive');
        }
    }
}
