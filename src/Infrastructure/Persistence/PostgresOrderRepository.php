<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Database;
use App\Domain\Repository\OrderRepository;
use App\Enum\OrderStatus;
use PDO;
use Swoole\Database\PDOProxy;
use RuntimeException;

final readonly class PostgresOrderRepository implements OrderRepository
{
    public function __construct(
        private Database $db,
    ) {
    }

    public function findByOrderCode(string $orderCode): ?array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($orderCode) {
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
            $stmt->execute([$orderCode]);
            return $stmt->fetch() ?: null;
        });
    }

    public function findById(string $id): ?array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($id) {
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        });
    }

    public function createWithItems(string $userId, array $items): array
    {
        return $this->db->transaction(function (PDO|PDOProxy $pdo) use ($userId, $items) {
            $totalAmountCents = 0;
            $currencyCode = 'RUB';
            $cachedProducts = [];

            // Готовим стейтменты ОДИН раз вне цикла
            $selectProductStmt = $pdo->prepare(
                "SELECT sku, stock, reserved, price_cents, currency FROM products WHERE sku = ? FOR UPDATE"
            );
            $insertItemStmt = $pdo->prepare(
                "INSERT INTO order_items (order_id, sku, price_cents) VALUES (?, ?, ?)"
            );
            $updateStockStmt = $pdo->prepare(
                "UPDATE products SET reserved = reserved + 1, updated_at = NOW() WHERE sku = ?"
            );

            // Проход 1: Валидация и расчёт суммы
            foreach ($items as $item) {
                $selectProductStmt->execute([$item->sku]);
                $product = $selectProductStmt->fetch();

                if (!$product) {
                    throw new RuntimeException("Product not found: {$item->sku}");
                }

                if ($product['stock'] <= $product['reserved']) {
                    throw new RuntimeException("Out of stock: {$item->sku}");
                }

                $itemPriceCents = (int)$product['price_cents'];
                $currencyCode = $product['currency'];
                $totalAmountCents += $itemPriceCents;

                // Кэшируем цену, чтобы не делать повторные SELECT
                $cachedProducts[$item->sku] = $itemPriceCents;
            }

            $orderCode = 'ord_' . bin2hex(random_bytes(8));

            // Сохраняем цену в центах (целое число)
            $insertOrderStmt = $pdo->prepare(
                "INSERT INTO orders (order_code, user_id, price_cents, currency)
                 VALUES (?, ?, ?, ?)
                 RETURNING *"
            );
            $insertOrderStmt->execute([$orderCode, $userId, $totalAmountCents, $currencyCode]);
            $order = $insertOrderStmt->fetch();

            // Проход 2: Запись позиций и резервирование
            foreach ($items as $item) {
                $priceCents = $cachedProducts[$item->sku];

                $insertItemStmt->execute([$order['id'], $item->sku, $priceCents]);
                $updateStockStmt->execute([$item->sku]);
            }

            return $order;
        });
    }

    public function updateStatus(string $orderId, OrderStatus $status): void
    {
        $updates = [
            'status = ?',
            'updated_at = NOW()',
            'version = version + 1',
        ];

        if ($status === OrderStatus::Paid) {
            $updates[] = 'paid_at = NOW()';
        } elseif ($status === OrderStatus::Delivered) {
            $updates[] = 'delivered_at = NOW()';
        } elseif ($status === OrderStatus::PartiallyDelivered) {
            $updates[] = 'delivered_at = NOW()';
        }

        $sql = "UPDATE orders SET " . implode(', ', $updates) . " WHERE id = ?";

        $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($sql, $status, $orderId) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$status->value, $orderId]);
        });
    }

    public function findByOrderCodeForUpdate(string $orderCode): ?array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($orderCode) {
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ? FOR UPDATE");
            $stmt->execute([$orderCode]);
            return $stmt->fetch() ?: null;
        });
    }

    public function updateStatusOptimistic(
        string $orderId,
        OrderStatus $newStatus,
        OrderStatus $expectedStatus,
        int $version,
    ): bool {
        $updates = [
            'status = ?',
            'updated_at = NOW()',
            'version = version + 1',
        ];

        if ($newStatus === OrderStatus::Paid) {
            $updates[] = 'paid_at = NOW()';
        } elseif ($newStatus === OrderStatus::Delivered) {
            $updates[] = 'delivered_at = NOW()';
        }

        $sql = "UPDATE orders SET " . implode(', ', $updates) . " WHERE id = ? AND status = ? AND version = ?";

        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($sql, $newStatus, $orderId, $expectedStatus, $version) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$newStatus->value, $orderId, $expectedStatus->value, $version]);
            return $stmt->rowCount() === 1;
        });
    }

    public function findStuckOrders(int $stuckAfterMin, int $limit): array
    {
        return $this->db->withConnection(function (PDO|PDOProxy $pdo) use ($stuckAfterMin, $limit) {
            $stmt = $pdo->prepare(
                "SELECT id, order_code FROM orders
                 WHERE status IN ('paid', 'delivering', 'out_of_stock', 'delivery_failed')
                 AND paid_at IS NOT NULL
                 AND updated_at < NOW() - INTERVAL '1 minute' * ?
                 ORDER BY created_at
                 LIMIT ?"
            );

            $stmt->bindValue(1, $stuckAfterMin, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        });
    }

    public function truncateOrders(): void
    {
        $this->db->withConnection(function (PDO|PDOProxy $pdo) {
            // Выполняем атомарный сброс рантайма базы данных
            $pdo->exec("TRUNCATE TABLE orders, order_items, order_events RESTART IDENTITY CASCADE");
        });
    }
}
