<?php

declare(strict_types=1);

namespace App\Exception;

final class OrderNotFoundException extends DomainException
{
    public function __construct(string $orderCode)
    {
        parent::__construct(
            sprintf('Order "%s" not found', $orderCode),
            404
        );
    }

    public function getErrorCode(): string
    {
        return 'order_not_found';
    }
}
