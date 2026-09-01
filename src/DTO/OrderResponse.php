<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class OrderResponse implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $orderCode,
        public string $sku,
        public string $userId,
        public string $status,
        public float $price,
        public string $currency,
        public ?string $deliveredCode = null,
        public ?string $provider = null,
        public ?string $providerRequestId = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $paidAt = null,
        public ?\DateTimeImmutable $deliveredAt = null,
        public int $version = 0,
        /** @var DeliveryResponse[] */
        public array $deliveries = [],
    ) {
    }

    public static function fromArray(array $order): self
    {
        $deliveries = array_map(
            static fn (array $delivery) => DeliveryResponse::fromArray($delivery),
            $order['deliveries'] ?? [],
        );

        return new self(
            id: $order['id'],
            orderCode: $order['order_code'],
            sku: $order['sku'],
            userId: $order['user_id'],
            status: $order['status'],
            price: (float)$order['price'],
            currency: $order['currency'],
            deliveredCode: $order['delivered_code'] ?? null,
            provider: $order['provider'] ?? null,
            providerRequestId: $order['provider_request_id'] ?? null,
            createdAt: isset($order['created_at']) ? new \DateTimeImmutable($order['created_at']) : null,
            paidAt: isset($order['paid_at']) ? new \DateTimeImmutable($order['paid_at']) : null,
            deliveredAt: isset($order['delivered_at']) ? new \DateTimeImmutable($order['delivered_at']) : null,
            version: (int)$order['version'],
            deliveries: $deliveries,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'order_code' => $this->orderCode,
            'sku' => $this->sku,
            'user_id' => $this->userId,
            'status' => $this->status,
            'price' => $this->price,
            'currency' => $this->currency,
            'delivered_code' => $this->deliveredCode,
            'provider' => $this->provider,
            'provider_request_id' => $this->providerRequestId,
            'created_at' => $this->createdAt?->format('c'),
            'paid_at' => $this->paidAt?->format('c'),
            'delivered_at' => $this->deliveredAt?->format('c'),
            'version' => $this->version,
            'deliveries' => $this->deliveries,
        ];
    }
}
