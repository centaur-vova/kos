<?php

declare(strict_types=1);

namespace App\Exception;

final class ProductNotFoundException extends DomainException
{
    public function __construct(string $sku)
    {
        parent::__construct(
            sprintf('Product "%s" not found', $sku),
            404
        );
    }

    public function getErrorCode(): string
    {
        return 'product_not_found';
    }
}
