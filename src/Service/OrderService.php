<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use Psr\Log\LoggerInterface;

final readonly class OrderService
{
    public function __construct(
        private Database $db,
        private LoggerInterface $logger,
    ) {
    }

    public function create(string $sku, string $userId): array
    {
        $this->logger->info('Creating order', ['sku' => $sku, 'user_id' => $userId]);

        $order = $this->db->transaction(function ($pdo) use ($sku, $userId) {
            // Получаем товар
            $stmt = $pdo->prepare("SELECT * FROM products WHERE sku = ? FOR UPDATE");
            $stmt->execute([$sku]);
            $product = $stmt->fetch();

            if (!$product) {
                throw new \RuntimeException('Product not found');
            }

            if ($product['stock'] <= $product['reserved']) {
                throw new \RuntimeException('Out of stock');
            }

            // Создаём заказ
            $orderCode = $this->generateOrderCode();

            $stmt = $pdo->prepare(
                "INSERT INTO orders (order_code, sku, user_id, price, currency)
                 VALUES (?, ?, ?, ?, ?)
                 RETURNING *"
            );
            $stmt->execute([
                $orderCode,
                $sku,
                $userId,
                $product['price'],
                $product['currency'],
            ]);

            $order = $stmt->fetch();

            // Резервируем товар
            $stmt = $pdo->prepare(
                "UPDATE products SET reserved = reserved + 1, updated_at = NOW()
                 WHERE sku = ?"
            );
            $stmt->execute([$sku]);

            return $order;
        });

        $this->logger->info('Order created', ['order_code' => $order['order_code']]);

        return $order;
    }

    public function getById(string $orderId): ?array
    {
        $pdo = $this->db->getConnection();

        // Если начинается с 'ord_' — ищем по order_code
        if (str_starts_with($orderId, 'ord_')) {
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
            $stmt->execute([$orderId]);
        } else {
            // Иначе — по id (UUID)
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
        }

        $order = $stmt->fetch();

        if (!$order) {
            return null;
        }

        // Добавляем информацию о выдаче
        $stmt = $pdo->prepare(
            "SELECT * FROM deliveries WHERE order_id = ? ORDER BY created_at"
        );
        $stmt->execute([$order['id']]);
        $deliveries = $stmt->fetchAll();

        $order['deliveries'] = $deliveries;

        return $order;
    }

    private function generateOrderCode(): string
    {
        return 'ord_' . bin2hex(random_bytes(8));
    }
}
