<?php

declare(strict_types=1);

namespace App;

use App\Config\ConfigLoader;
use App\Config\Options;
use App\Config\ProviderConfig;

final readonly class Bootstrap
{
    public static function init(string $basePath): Options
    {
        $loader = ConfigLoader::fromEnv($basePath);

        // Провайдеры
        $providersData = $loader->getArray('PROVIDERS_CONFIG', [
            // Дефолтный конфиг если не задано в .env
            'A' => ['host' => 'provider-a', 'port' => 8000, 'timeout_ms' => 5000],
            'B' => ['host' => 'provider-b', 'port' => 8000, 'timeout_ms' => 5000],
        ]);

        $providers = [];
        foreach ($providersData as $name => $data) {
            $providers[$name] = ProviderConfig::fromArray($name, $data);
        }

        // Опции
        return new Options(
            serverHost: $loader->getString('SERVER_HOST', '0.0.0.0'),
            serverPort: $loader->getInt('SERVER_PORT', 8080),
            workerNum: $loader->getInt('WORKER_NUM', swoole_cpu_num() * 2),
            shutdownTimeoutSec: $loader->getInt('SHUTDOWN_TIMEOUT_SEC', 30),
            dbHost: $loader->getString('DB_HOST', 'localhost'),
            dbPort: $loader->getInt('DB_PORT', 5432),
            dbName: $loader->getString('DB_NAME', 'game_shop'),
            dbUser: $loader->getString('DB_USER', 'app'),
            dbPassword: $loader->getString('DB_PASSWORD', 'secret'),
            providers: $providers,
            deliveryMaxRetries: $loader->getInt('DELIVERY_MAX_RETRIES', 3),
            deliveryRetryDelaysMs: $loader->getArray('DELIVERY_RETRY_DELAYS_MS', [1000, 3000, 5000]),
            deliveryLockTtlSec: $loader->getInt('DELIVERY_LOCK_TTL_SEC', 30),
            logLevel: $loader->getString('LOG_LEVEL', 'info'),
            recoveryIntervalSec: $loader->getInt('RECOVERY_INTERVAL_SEC', 60),
            recoveryStuckAfterMin: $loader->getInt('RECOVERY_STUCK_AFTER_MIN', 5),
            recoveryBatchSize: $loader->getInt('RECOVERY_BATCH_SIZE', 10),
            paymentIdempotencyTtlSec: $loader->getInt('PAYMENT_IDEMPOTENCY_TTL_SEC', 86400),
        );
    }
}
