<?php

declare(strict_types=1);

namespace App\Domain\Repository;

interface ProductRepository
{
    /** @return array<int, array<string, mixed>> */
    public function findAvailable(int $limit = 100, int $offset = 0): array;
}
