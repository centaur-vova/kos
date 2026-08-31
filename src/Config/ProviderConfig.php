<?php

declare(strict_types=1);

namespace App\Config;

final readonly class ProviderConfig
{
    public function __construct(
        public string $name,
        public string $host,
        public int $port,
        public int $timeoutMs,
    ) {
    }

    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            host: $data['host'] ?? "provider-" . strtolower($name),
            port: (int)($data['port'] ?? 8000),
            timeoutMs: (int)($data['timeout_ms'] ?? 5000),
        );
    }
}
