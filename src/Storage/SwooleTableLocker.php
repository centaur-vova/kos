<?php

declare(strict_types=1);

namespace App\Storage;

use App\Config\Options;

final readonly class SwooleTableLocker implements LockerInterface
{
    public function __construct(
        private SwooleTableStorage $storage,
        private Options $options,
    ) {
    }

    public function acquire(string $key, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->options->deliveryLockTtlSec;

        // Неатомарно: между has() и set() есть окно, поэтому при жёсткой
        // конкуренции два процесса могут взять один лок. Реальная защита
        // от гонок — уникальные constraint'ы в БД и оптимистичные апдейты.
        // В проде — Redis с SET NX EX или Swoole\Table::incr с TTL.
        if ($this->storage->has($key)) {
            return false;
        }

        return $this->storage->set($key, 'locked', $ttl);
    }

    public function release(string $key): void
    {
        $this->storage->del($key);
    }

    public function withLock(string $key, callable $callback, ?int $ttl = null): bool
    {
        if (!$this->acquire($key, $ttl)) {
            return false;
        }

        try {
            $callback();
        } finally {
            $this->release($key);
        }

        return true;
    }
}
