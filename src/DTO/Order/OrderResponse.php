<?php

declare(strict_types=1);

namespace App\DTO\Order;

final readonly class OrderResponse implements \JsonSerializable
{
    /**
     * @param OrderItemResponse[] $items
     * @param array $refunds
     */
    public function __construct(
        public string $id,
        public string $orderCode,
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
        public array $items = [],
        public array $refunds = [],
    ) {
    }

    public static function fromArray(array $order): self
    {
        $items = array_map(
            static fn (array $item) => OrderItemResponse::fromArray($item),
            $order['items'] ?? [],
        );

        return new self(
            id: (string)$order['id'],
            orderCode: (string)$order['order_code'],
            userId: (string)$order['user_id'],
            status: (string)$order['status'],
            price: (float)($order['price_cents'] ?? 0) / 100,
            currency: (string)$order['currency'],
            deliveredCode: $order['delivered_code'] ?? null,
            provider: $order['provider'] ?? null,
            providerRequestId: $order['provider_request_id'] ?? null,
            createdAt: isset($order['created_at']) ? new \DateTimeImmutable($order['created_at']) : null,
            paidAt: isset($order['paid_at']) ? new \DateTimeImmutable($order['paid_at']) : null,
            deliveredAt: isset($order['delivered_at']) ? new \DateTimeImmutable($order['delivered_at']) : null,
            version: (int)$order['version'],
            items: $items,
            refunds: $order['refunds'] ?? [],
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'order_code' => $this->orderCode,
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
            'items' => $this->items,
            'refunds' => $this->refunds,
        ];
    }
}
