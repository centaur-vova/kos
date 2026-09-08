<?php

declare(strict_types=1);

namespace App\Domain\Repository;

interface RefundRepository
{
    public function createForItem(string $itemId, int $amountCents): void;
}
