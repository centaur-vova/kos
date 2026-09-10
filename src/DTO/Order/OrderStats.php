<?php

declare(strict_types=1);

namespace App\DTO\Order;

final readonly class OrderStats
{
    public function __construct(
        public int $total,
        public int $delivered,
        public int $refunded,
        public int $failed,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            total: (int)$data['total'],
            delivered: (int)$data['delivered'],
            refunded: (int)$data['refunded'],
            failed: (int)$data['failed'],
        );
    }

    public function isFullyDelivered(): bool
    {
        return $this->delivered === $this->total;
    }

    public function isPartiallyDelivered(): bool
    {
        return $this->delivered > 0 && $this->delivered < $this->total;
    }
}
