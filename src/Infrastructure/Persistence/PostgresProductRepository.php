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

    public function findAvailable(int $limit = 100, int $offset = 0): array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($limit, $offset) {
            $stmt = $pdo->prepare(
                "SELECT sku, name, type, price_cents, currency,
                    stock, reserved, (stock - reserved) as available
                FROM products
                WHERE stock > reserved
                ORDER BY type, price_cents
                LIMIT ? OFFSET ?"
            );
            $stmt->execute([$limit, $offset]);
            return $stmt->fetchAll();
        });
    }
}
