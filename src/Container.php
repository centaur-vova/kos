<?php

declare(strict_types=1);

namespace App;

use App\Controller\OrderController;
use App\Controller\WebhookController;
use DI\ContainerBuilder;
use App\Storage\StorageInterface;
use App\Service\PaymentService;
use App\Service\DeliveryService;
use App\Service\OrderService;
use App\Service\ProviderClient;
use App\Storage\SwooleTableStorage;
use App\Support\StdoutLogger;
use Psr\Log\LoggerInterface;

use function DI\autowire;

class Container
{
    private static \DI\Container $container;

    public static function init(): void
    {
        $builder = new ContainerBuilder();

        $builder->addDefinitions([
            // Logger
            LoggerInterface::class => autowire(StdoutLogger::class),

            // Storage
            StorageInterface::class => autowire(SwooleTableStorage::class),

            // Database
            Database::class => autowire(Database::class),

            // ProviderClient
            ProviderClient::class => autowire(ProviderClient::class),

            // DeliveryService
            DeliveryService::class => autowire(DeliveryService::class),

            // PaymentService
            PaymentService::class => autowire(PaymentService::class),

            // OrderService
            OrderService::class => autowire(OrderService::class),

            // Controllers
            OrderController::class => autowire(OrderController::class),
            WebhookController::class => autowire(WebhookController::class),
        ]);

        self::$container = $builder->build();
    }

    public static function get(string $id): mixed
    {
        return self::$container->get($id);
    }
}
