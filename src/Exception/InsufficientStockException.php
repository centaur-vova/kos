<?php

declare(strict_types=1);

namespace App\Exception;

final class InsufficientStockException extends DomainException
{
    public function __construct(string $sku)
    {
        parent::__construct(
            sprintf('Insufficient stock for product "%s"', $sku),
            409
        );
    }

    public function getErrorCode(): string
    {
        return 'insufficient_stock';
    }
}
