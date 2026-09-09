<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Http\ApiResponse;
use PDO;
use Swoole\Database\PDOProxy;
use Swoole\Http\Request;

final readonly class TestController
{
    public function __construct(
        private Database $db
    ) {
    }

    public function resetState(Request $request, array $params): ApiResponse
    {
        $this->db->withConnection(function (PDO|PDOProxy $pdo) {
            // Очищаем все таблицы заказов
            $pdo->exec("TRUNCATE TABLE orders, order_items, payments, deliveries, refunds, order_events RESTART IDENTITY CASCADE");

            // Сбрасываем остатки и резервы
            $pdo->exec("UPDATE products SET stock = 1000, reserved = 0");
        });

        return ApiResponse::success();
    }
}
