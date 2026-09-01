<?php

declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use App\Application;
use App\Bootstrap;
use App\Container;
use App\Service\RecoveryService;
use Psr\Log\LoggerInterface;

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '0');

require __DIR__ . '/../vendor/autoload.php';

// Включаем корутины
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

// Загружаем конфиг
$options = Bootstrap::init(__DIR__ . '/..');

// Инициализируем DI
Container::init($options);

// Сразу получаем логгер
$logger = Container::get(LoggerInterface::class);

// Создаём приложение
$app = new Application($options, $logger);

$server = new Server(
    host: $options->serverHost,
    port: $options->serverPort,
);

$server->set([
    'worker_num' => $options->workerNum,
    'max_request' => 100000,
    'log_level' => SWOOLE_LOG_WARNING,
]);

$server->on('start', function (Server $server) use ($logger, $options) {
    $logger->info("Server started at http://{$server->host}:{$server->port}");

    /** @var RecoveryService $recoveryService */
    $recoveryService = Container::get(RecoveryService::class);

    Swoole\Timer::tick(
        $options->recoveryIntervalSec * 1000,
        static fn () => $recoveryService->recoverStuckOrders()
    );
});

$server->on('request', function (Request $request, Response $response) use ($app) {
    $app->handle($request, $response);
});

$server->start();
