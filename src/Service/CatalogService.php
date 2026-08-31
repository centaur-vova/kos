<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;

final readonly class CatalogService
{
    public function __construct(
        private Database $db,
    ) {
    }

    public function getAvailableProducts(): array
    {
        $pdo = $this->db->getConnection();

        $stmt = $pdo->query(
            "SELECT sku, name, type, price, currency,
                    stock, reserved, (stock - reserved) as available
             FROM products
             WHERE stock > reserved
             ORDER BY type, price"
        );

        $products = $stmt->fetchAll();

        $pdo = null; // Явно закрываем сокет

        return $products;
    }
}
