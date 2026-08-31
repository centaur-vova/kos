<?php

declare(strict_types=1);

namespace App;

use App\Config\Options;
use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use App\Service\ProviderClient;
use App\Storage\StorageInterface;
use App\Storage\SwooleTableStorage;
use App\Support\StdoutLogger;

use function DI\create;

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
            StorageInterface::class => static fn (\DI\Container $c) => $c->get(SwooleTableStorage::class),

            // Server/infra
            LoggerInterface::class => static fn () => new StdoutLogger($options->logLevel),
            Database::class => static fn () => new Database($options),
            ProviderClient::class => static fn () => new ProviderClient($options),
        ]);

        self::$container = $builder->build();
    }

    public static function get(string $id): mixed
    {
        return self::$container->get($id);
    }
}
