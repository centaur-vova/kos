<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Repository\ProductRepository;

final readonly class CatalogService
{
    public function __construct(
        private ProductRepository $productRepository,
    ) {
    }

    public function getAvailableProducts(int $limit = 100, int $offset = 0): array
    {
        return $this->productRepository->findAvailable($limit, $offset);
    }
}
