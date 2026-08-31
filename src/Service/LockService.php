<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Options;
use App\Storage\StorageInterface;
use Psr\Log\LoggerInterface;

final readonly class LockService
{
    public function __construct(
        private StorageInterface $storage,
        private Options $options,
        private LoggerInterface $logger,
    ) {
    }

    public function acquire(string $key, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->options->deliveryLockTtlSec;

        $acquired = $this->storage->set($key, 'locked', $ttl);

        if (!$acquired) {
            $this->logger->info('Failed to acquire lock', ['key' => $key]);
        }

        return $acquired;
    }

    public function release(string $key): void
    {
        $this->storage->del($key);
    }

    public function withLock(string $key, callable $callback, ?int $ttl = null): void
    {
        if (!$this->acquire($key, $ttl)) {
            return;
        }

        try {
            $callback();
        } finally {
            $this->release($key);
        }
    }
}
