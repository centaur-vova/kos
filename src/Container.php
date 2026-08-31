<?php

declare(strict_types=1);

namespace App;

use App\Config\Options;
use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use App\Logger\Logger;
use App\Storage\StorageInterface;
use App\Storage\SwooleTableStorage;
use App\Service\OrderService;
use App\Service\PaymentService;
use App\Service\DeliveryService;
use App\Service\ProviderClient;
use App\Controller\OrderController;
use App\Controller\WebhookController;
use App\Support\StdoutLogger;

use function DI\autowire;

class Container
{
    private static \DI\Container $container;

    public static function init(Options $options): void
    {
        $builder = new ContainerBuilder();

        $builder->addDefinitions([
            Options::class => $options,

            LoggerInterface::class => function () use ($options) {
                return new StdoutLogger($options->logLevel);
            },

            StorageInterface::class => autowire(SwooleTableStorage::class),

            Database::class => function () use ($options) {
                return new Database($options);
            },

            ProviderClient::class => function () use ($options) {
                return new ProviderClient($options);
            },

            DeliveryService::class => autowire(DeliveryService::class),
            PaymentService::class => autowire(PaymentService::class),
            OrderService::class => autowire(OrderService::class),

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
