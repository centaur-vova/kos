<?php

declare(strict_types=1);

namespace App;

use App\Config\Options;
use PDO;
use Swoole\Database\PDOPool;
use Swoole\Database\PDOConfig;
use Swoole\Database\PDOProxy;
use RuntimeException;
use Throwable;

final class Database
{
    public const POOL_SIZE = 25; // TODO: move to config

    public const UNIQUE_VIOLATION = '23505';

    private ?PDOPool $pool = null;

    public function __construct(
        private readonly Options $options,
    ) {
        $config = (new PDOConfig())
            ->withDriver('pgsql')
            ->withHost($this->options->dbHost)
            ->withPort($this->options->dbPort)
            ->withDbName($this->options->dbName)
            ->withUsername($this->options->dbUser)
            ->withPassword($this->options->dbPassword)
            ->withOptions([
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

        $this->pool = new PDOPool($config, self::POOL_SIZE);
    }

    /**
     * Безопасное выполнение любого SQL-запроса вне транзакции.
     * Заменяет ручной вызов getConnection() в репозиториях.
     */
    public function withConnection(callable $callback): mixed
    {
        $pdo = $this->getConnection();
        try {
            /** @var PDO|PDOProxy $pdo */
            return $callback($pdo);
        } finally {
            $this->putConnection($pdo);
        }
    }

    /**
     * Безопасное выполнение логики внутри транзакции.
     */
    public function transaction(callable $callback): mixed
    {
        return $this->withConnection(function (PDO|PDOProxy $pdo) use ($callback) {
            try {
                $pdo->beginTransaction();
                $result = $callback($pdo);
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                    // Глушим ошибку разрыва сокета при жестком падении базы
                }
                throw $e;
            }
        });
    }

    /**
     * Внутренний метод получения сокета. Закрыт от внешнего вызова.
     */
    private function getConnection(): PDO|PDOProxy
    {
        $connection = $this->pool->get();
        if ($connection === false) {
            throw new RuntimeException("Swoole PDOPool is empty or timeout exceeded");
        }
        return $connection;
    }

    /**
     * Внутренний метод возврата сокета. Закрыт от внешнего вызова.
     */
    private function putConnection(PDO|PDOProxy $pdo): void
    {
        if ($this->pool !== null) {
            $this->pool->put($pdo);
        }
    }
}
