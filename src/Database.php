<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public const UNIQUE_VIOLATION = '23505';

    public function getConnection(): PDO
    {

        $host = Config::get('DB_HOST', 'localhost');
        $port = Config::get('DB_PORT', '5432');
        $dbname = Config::get('DB_NAME', 'game_shop');
        $user = Config::get('DB_USER', 'app');
        $password = Config::get('DB_PASSWORD', 'secret');

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->getConnection();

        try {
            $pdo->beginTransaction();
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
