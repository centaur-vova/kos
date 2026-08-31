<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final readonly class Options
{
    /** @param array<string, ProviderConfig> $providers */
    public function __construct(
        public string $serverHost,
        public int $serverPort,
        public int $workerNum,
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPassword,
        public array $providers,
        public int $deliveryMaxRetries,
        public array $deliveryRetryDelaysMs,
        public int $deliveryLockTtlSec,
        public string $logLevel,
        public int $recoveryIntervalSec,
        public int $recoveryStuckAfterMin,
        public int $recoveryBatchSize,
        public int $paymentIdempotencyTtlSec,
        public int $swooleStorageTableSize,
    ) {
    }

    public function getProvider(string $name): ProviderConfig
    {
        $provider = $this->providers[$name] ?? null;

        if ($provider === null) {
            throw new RuntimeException("Provider {$name} not set");
        }

        return $provider;
    }

    public function getFirstProvider(): ProviderConfig
    {
        $firstKey = array_key_first($this->providers);

        if ($firstKey === null) {
            throw new RuntimeException('No providers configured');
        }

        return $this->providers[$firstKey];
    }

    public function getNextProvider(string $currentName): ?ProviderConfig
    {
        $names = array_keys($this->providers);
        $currentIndex = array_search($currentName, $names, true);

        if ($currentIndex === false || $currentIndex === count($names) - 1) {
            return null;
        }

        $nextName = $names[$currentIndex + 1];
        return $this->providers[$nextName];
    }
}
