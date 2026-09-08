<?php

declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use App\Bootstrap;
use App\Container;
use App\Controller\ProviderMockController;
use Psr\Log\LoggerInterface;

require __DIR__ . '/../vendor/autoload.php';

// Инициализация и запуск сервера остаются чистыми и легковесными
$options = Bootstrap::init(__DIR__ . '/..');
$port = (int)(getenv('PROVIDER_PORT') ?: 8000);

$server = new Server('0.0.0.0', $port);
$server->set([
    'worker_num' => 1,
    'enable_coroutine' => true,
]);

$server->on('workerStart', function (Server $server, int $workerId) use ($options) {
    Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    Container::init($options);

    $logger = Container::get(LoggerInterface::class);
    $logger->info("Mock Provider worker #{$workerId} up and running");
});

$server->on('request', function (Request $req, Response $res) {
    /** @var ProviderMockController $mockController */
    $mockController = Container::get(ProviderMockController::class);

    $mockController->handle($req, $res);
});

$server->start();
