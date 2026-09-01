<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class DeliveryResponse implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $requestId,
        public string $provider,
        public string $status,
        public ?string $code = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $completedAt = null,
    ) {
    }

    public static function fromArray(array $delivery): self
    {
        return new self(
            id: (string)$delivery['id'],
            requestId: (string)$delivery['request_id'],
            provider: (string)$delivery['provider'],
            status: (string)$delivery['status'],
            code: $delivery['code'] ?? null,
            createdAt: isset($delivery['created_at'])
                ? new \DateTimeImmutable($delivery['created_at'])
                : null,
            completedAt: isset($delivery['completed_at'])
                ? new \DateTimeImmutable($delivery['completed_at'])
                : null,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->requestId,
            'provider' => $this->provider,
            'status' => $this->status,
            'code' => $this->code,
            'created_at' => $this->createdAt?->format('c'),
            'completed_at' => $this->completedAt?->format('c'),
        ];
    }
}
