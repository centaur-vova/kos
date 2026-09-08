<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class OrderItemResponse implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $sku,
        public float $price,
        public string $status,
        public ?string $deliveredCode = null,
        public ?string $provider = null,
        public ?string $providerRequestId = null,
    ) {
    }

    public static function fromArray(array $item): self
    {
        return new self(
            id: (string)$item['id'],
            sku: (string)$item['sku'],
            price: (float)($item['price_cents'] ?? 0) / 100,
            status: (string)$item['status'],
            deliveredCode: $item['delivered_code'] ?? null,
            provider: $item['provider'] ?? null,
            providerRequestId: $item['provider_request_id'] ?? null,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'price' => $this->price,
            'status' => $this->status,
            'delivered_code' => $this->deliveredCode,
            'provider' => $this->provider,
            'provider_request_id' => $this->providerRequestId,
        ];
    }
}
