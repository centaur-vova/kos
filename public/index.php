<?php

declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use App\Application;
use App\Bootstrap;
use App\Container;
use App\Service\RecoveryService;
use App\Storage\StorageTable;
use Psr\Log\LoggerInterface;

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '0');

require __DIR__ . '/../vendor/autoload.php';

// Загружаем конфиг
$options = Bootstrap::init(__DIR__ . '/..');

// Создадим Swoole Table в shared mem до форка воркеров
$storageTable = new StorageTable($options->swooleStorageTableSize);

$server = new Server(
    host: $options->serverHost,
    port: $options->serverPort,
);

$server->set([
    'worker_num' => $options->workerNum,
    'max_request' => 100000,
    'log_level' => SWOOLE_LOG_WARNING,
    'enable_coroutine' => true, // Явно форсим корутины для HTTP-запросов
]);

// Хук 'start' используем ТОЛЬКО для базового информирования в консоль
$server->on('start', function (Server $server) {
    echo "Master process started. Server running at http://{$server->host}:{$server->port}\n";
});

// ГЛАВНЫЙ ХУК: Инициализация рантайма каждого отдельного Воркера
$server->on('workerStart', function (Server $server, int $workerId) use ($options, $storageTable) {
    // Включаем корутины и хуки для PDO/сетевого рантайма строго внутри воркера
    Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

    // Инициализируем DI-контейнер ИЗОЛИРОВАННО для этого процесса воркера!
    Container::init($options, $storageTable);

    // Получаем логгер для текущего воркера
    /** @var LoggerInterface $logger */
    $logger = Container::get(LoggerInterface::class);
    $logger->info("Worker #{$workerId} initialized");

    // Запускаем таймер на воркере #0
    if ($workerId === 0) {
        /** @var RecoveryService $recoveryService */
        $recoveryService = Container::get(RecoveryService::class);

        Swoole\Timer::tick(
            $options->recoveryIntervalSec * 1000,
            static fn () => $recoveryService->recoverStuckOrders()
        );
    }
});

// ХУК ОБРАБОТКИ ЗАПРОСОВ: Просто берем Application из DI-контейнера текущего воркера
$server->on('request', function (Request $request, Response $response) {
    /** @var Application $app */
    $app = Container::get(Application::class);

    $app->handle($request, $response);
});

$server->start();
