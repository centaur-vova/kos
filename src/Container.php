<?php

declare(strict_types=1);

namespace App;

use App\Config\Options;
use App\Controller\ProviderMockController;
use App\Domain\Repository\OrderEventRepository;
use App\Domain\Repository\OrderItemRepository;
use App\Domain\Repository\OrderRepository;
use App\Domain\Repository\PaymentRepository;
use App\Domain\Repository\ProductRepository;
use App\Domain\Repository\ReconciliationRepository;
use App\Domain\Repository\RefundRepository;
use App\Infrastructure\Persistence\PostgresOrderEventRepository;
use App\Infrastructure\Persistence\PostgresOrderItemRepository;
use App\Infrastructure\Persistence\PostgresOrderRepository;
use App\Infrastructure\Persistence\PostgresPaymentRepository;
use App\Infrastructure\Persistence\PostgresProductRepository;
use App\Infrastructure\Persistence\PostgresReconciliationRepository;
use App\Infrastructure\Persistence\PostgresRefundRepository;
use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use App\Service\ProviderClient;
use App\Storage\StorageInterface;
use App\Storage\SwooleTableStorage;
use App\Support\StdoutLogger;

use function DI\autowire;
use function DI\get;

final class Container
{
    private static \DI\Container $container;

    public static function init(Options $options): void
    {
        $builder = new ContainerBuilder();

        $builder->useAutowiring(true);

        $builder->addDefinitions([
            Options::class => $options,

            // Storage
            SwooleTableStorage::class => static fn () => new SwooleTableStorage($options),
            StorageInterface::class => get(SwooleTableStorage::class),

            // Server/infra
            LoggerInterface::class => static fn () => new StdoutLogger($options->logLevel),
            Database::class => static fn () => new Database($options),
            ProviderClient::class => static fn () => new ProviderClient($options),

            // Repositories
            OrderItemRepository::class => get(PostgresOrderItemRepository::class),
            OrderRepository::class => get(PostgresOrderRepository::class),
            RefundRepository::class => get(PostgresRefundRepository::class),
            PaymentRepository::class => get(PostgresPaymentRepository::class),
            ProductRepository::class => get(PostgresProductRepository::class),
            ReconciliationRepository::class => get(PostgresReconciliationRepository::class),
            OrderEventRepository::class => get(PostgresOrderEventRepository::class),

            // Controller(s)
            ProviderMockController::class => autowire(ProviderMockController::class),
        ]);

        self::$container = $builder->build();
    }

    public static function get(string $id): mixed
    {
        return self::$container->get($id);
    }
}
