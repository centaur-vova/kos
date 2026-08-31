<?php

declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use App\Application;
use App\Bootstrap;
use App\Config;
use App\Container;
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

// Создаём приложение
$app = new Application($options);

$server = new Server(
    host: $options->serverHost,
    port: $options->serverPort,
);


$logger = Container::get(LoggerInterface::class);

$server->set([
    'worker_num' => Config::getInt('WORKER_NUM', swoole_cpu_num() * 2),
    'max_request' => 100000,
    'log_level' => SWOOLE_LOG_WARNING,
]);

$server->on('start', function (Server $server) use ($logger) {
    $logger->info("Server started at http://{$server->host}:{$server->port}");
});

$server->on('request', function (Request $request, Response $response) use ($app, $logger) {
    try {
        $app->handle($request, $response);
    } catch (\Throwable $e) {
        $response->status(500);
        $response->end(json_encode([
            'status' => 'error',
            'message' => 'Internal Server Error',
        ]));

        $logger->info("Server error", [
            'error' => (string) $e,
        ]);
    }
});

$server->start();
