<?php

declare(strict_types=1);

namespace App;

use App\Config\Options;
use PDO;

final class Database
{
    public const UNIQUE_VIOLATION = '23505';

    public function __construct(
        private readonly Options $options,
    ) {
    }

    public function getConnection(): PDO
    {

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $this->options->dbHost,
            $this->options->dbPort,
            $this->options->dbName,
        );

        return new \PDO($dsn, $this->options->dbUser, $this->options->dbPassword, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
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
            try {
                $pdo->rollBack();
            } catch (\Throwable) {
                // Глушим ошибку разрыва сокета
            }
            throw $e;
        }
    }
}
