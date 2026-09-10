<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Options;
use App\Storage\StorageInterface;

final readonly class LockService
{
    public function __construct(
        private StorageInterface $storage,
        private Options $options,
    ) {
    }

    public function acquire(string $key, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->options->deliveryLockTtlSec;
        $acquired = $this->storage->set($key, 'locked', $ttl);

        return $acquired;
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
