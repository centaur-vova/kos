<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class OrderItem
{
    public function __construct(
        public string $sku,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            sku: (string)$data['sku'],
        );
    }
}
