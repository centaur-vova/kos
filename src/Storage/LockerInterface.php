<?php

declare(strict_types=1);

namespace App\Storage;

interface LockerInterface
{
    public function acquire(string $key, ?int $ttl = null): bool;
    public function release(string $key): void;
    public function withLock(string $key, callable $callback, ?int $ttl = null): bool;
}
