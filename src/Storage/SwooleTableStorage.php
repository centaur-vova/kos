<?php

declare(strict_types=1);

namespace App\Storage;

use App\Config\Options;
use Swoole\Table;

final class SwooleTableStorage implements StorageInterface
{
    private Table $table;

    public function __construct(Options $options)
    {
        $this->table = new Table($options->swooleStorageTableSize);
        $this->table->column('value', Table::TYPE_STRING, 255);
        $this->table->column('ttl', Table::TYPE_INT, 4);
        $this->table->create();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->table->get($key);

        if (!$row) {
            return $default;
        }

        // Проверяем TTL
        if ($row['ttl'] > 0 && $row['ttl'] < time()) {
            $this->table->del($key);
            return $default;
        }

        return json_decode($row['value'], true) ?? $row['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);

        return $this->table->set($key, [
            'value' => $json,
            'ttl' => $ttl !== null ? time() + $ttl : 0,
        ]);
    }

    public function has(string $key): bool
    {
        $row = $this->table->get($key);

        if (!$row) {
            return false;
        }

        if ($row['ttl'] > 0 && $row['ttl'] < time()) {
            $this->table->del($key);
            return false;
        }

        return true;
    }

    public function del(string $key): void
    {
        $this->table->del($key);
    }
}
