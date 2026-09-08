<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Database;
use App\Domain\Repository\RefundRepository;
use PDO;
use Swoole\Database\PDOProxy;

final readonly class PostgresRefundRepository implements RefundRepository
{
    public function __construct(
        private Database $db,
    ) {
    }

    public function createForItem(string $itemId, int $amountCents): void
    {
        $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($itemId, $amountCents) {
            $stmt = $pdo->prepare("SELECT id FROM refunds WHERE order_item_id = ?");
            $stmt->execute([$itemId]);
            $existing = $stmt->fetch();

            if ($existing) {
                return;
            }

            $stmt = $pdo->prepare("INSERT INTO refunds (order_item_id, amount_cents) VALUES (?, ?)");
            $stmt->execute([$itemId, $amountCents]);
        });
    }
}
