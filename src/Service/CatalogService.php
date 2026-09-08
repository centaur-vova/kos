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

    public function getAvailableProducts(): array
    {
        return $this->productRepository->findAvailable();
    }
}
