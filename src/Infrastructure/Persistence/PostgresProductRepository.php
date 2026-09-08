<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Database;
use App\Domain\Repository\ProductRepository;
use PDO;
use Swoole\Database\PDOProxy;

final readonly class PostgresProductRepository implements ProductRepository
{
    public function __construct(
        private Database $db,
    ) {
    }

    public function findAvailable(): array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) {
            $stmt = $pdo->query(
                "SELECT sku, name, type, price, currency,
                        stock, reserved, (stock - reserved) as available
                 FROM products
                 WHERE stock > reserved
                 ORDER BY type, price"
            );

            return $stmt->fetchAll();
        });
    }

    public function resetStock(): void
    {
        $this->db->withConnection(function (PDO|PDOProxy $pdo) {
            $pdo->exec("UPDATE products SET stock = 1000, reserved = 0");
        });
    }
}
