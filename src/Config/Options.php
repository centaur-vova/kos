<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final readonly class Options
{
    public function __construct(
        public string $serverHost,
        public int $serverPort,
        public int $workerNum,
        public int $shutdownTimeoutSec,
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPassword,
        /** @var array<string, ProviderConfig> $providers */
        public array $providers,
        public int $deliveryMaxRetries,
        public array $deliveryRetryDelaysMs,
        public int $deliveryLockTtlSec,
        public string $logLevel,
        public readonly int $recoveryIntervalSec,
    ) {
    }

    public function getProvider(string $name): ProviderConfig
    {
        $provider = $this->providers[$name] ?? null;
        if ($provider === null) {
            throw new RuntimeException("Provider {$provider} not set");
        }

        return $provider;
    }
}
