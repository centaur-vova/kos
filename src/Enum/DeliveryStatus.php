<?php

declare(strict_types=1);

namespace App\Enum;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Issued = 'issued';
    case Timeout = 'timeout';
    case Error = 'error';

    public function is(self $status): bool
    {
        return $this === $status;
    }
}
