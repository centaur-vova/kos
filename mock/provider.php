<?php

declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use App\Bootstrap;
use App\Container;
use App\Controller\ProviderMockController;
use App\Storage\StorageTable;
use Psr\Log\LoggerInterface;

require __DIR__ . '/../vendor/autoload.php';

// Инициализация и запуск сервера остаются чистыми и легковесными
$options = Bootstrap::init(__DIR__ . '/..');
$port = (int)(getenv('PROVIDER_PORT') ?: 8000);

// Создадим Swoole Table в shared mem до форка воркеров
$storageTable = new StorageTable($options->swooleStorageTableSize);

$server = new Server('0.0.0.0', $port);
$server->set([
    'worker_num' => 4,
    'enable_coroutine' => true,
]);

$server->on('workerStart', function (Server $server, int $workerId) use ($options, $storageTable) {
    // Включаем корутины и хуки для PDO/сетевого рантайма строго внутри воркера
    Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

    Container::init($options, $storageTable);

    /** @var LoggerInterface $logger */
    $logger = Container::get(LoggerInterface::class);
    $logger->info("Mock Provider worker #{$workerId} up and running");
});

$server->on('request', function (Request $req, Response $res) {
    /** @var ProviderMockController $mockController */
    $mockController = Container::get(ProviderMockController::class);
    $mockController->handle($req, $res);
});

$server->start();
