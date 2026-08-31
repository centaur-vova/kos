<?php

declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use App\Bootstrap;
use App\Container;
use App\Storage\StorageInterface;
use Psr\Log\LoggerInterface;

require __DIR__ . '/../vendor/autoload.php';

// Загружаем конфиг
$options = Bootstrap::init(__DIR__ . '/..');

// Инициализируем DI
Container::init($options);

// Logger
$logger = Container::get(LoggerInterface::class);

// Storage
$storage = Container::get(StorageInterface::class);

// Параметры провайдера
$providerName = getenv('PROVIDER_NAME') ?: 'A';
$port = (int)(getenv('PROVIDER_PORT') ?: 8000);

// Mock-параметры
$errorRate = (int)(getenv('MOCK_ERROR_RATE') ?: 20);
$timeoutRate = (int)(getenv('MOCK_TIMEOUT_RATE') ?: 10);
$timeoutDuration = (int)(getenv('MOCK_TIMEOUT_DURATION_SEC') ?: 7);

$server = new Server('0.0.0.0', $port);

$server->on('request', function (Request $req, Response $res) use (
    $storage,
    $errorRate,
    $timeoutRate,
    $timeoutDuration,
    $providerName,
    $logger,
) {
    if ($req->server['request_uri'] !== '/issue' || $req->server['request_method'] !== 'POST') {
        $res->status(404);
        $res->end(json_encode(['status' => 'error', 'reason' => 'not_found']));
        return;
    }

    $body = json_decode($req->getContent(), true);
    $requestId = $body['request_id'] ?? null;
    $sku = $body['sku'] ?? null;

    if (!$requestId || !$sku) {
        $res->status(400);
        $res->end(json_encode(['status' => 'error', 'reason' => 'bad_request']));
        return;
    }

    // Проверяем, не выдавали ли уже этот код
    $existingCode = $storage->get("provider:issued:{$requestId}");

    if ($existingCode) {
        $logger->info("Returning cached code", [
            'provider' => $providerName,
            'request_id' => $requestId,
            'code' => $existingCode,
        ]);

        $res->end(json_encode([
            'status' => 'ok',
            'request_id' => $requestId,
            'code' => $existingCode,
        ]));
        return;
    }

    // Детерминированное поведение
    $hash = crc32($requestId . $sku . $providerName);
    $behavior = $hash % 100;

    if ($behavior < $timeoutRate) {
        $code = generateCode($sku);
        $storage->set("provider:issued:{$requestId}", $code);

        $logger->info("Timeout simulation", [
            'provider' => $providerName,
            'request_id' => $requestId,
            'code' => $code,
        ]);

        Swoole\Coroutine::sleep($timeoutDuration);

        $res->end(json_encode([
            'status' => 'ok',
            'request_id' => $requestId,
            'code' => $code,
        ]));
        return;
    }

    if ($behavior < ($timeoutRate + $errorRate)) {
        $logger->info("Error simulation", [
            'provider' => $providerName,
            'request_id' => $requestId,
        ]);

        $res->status(500);
        $res->end(json_encode([
            'status' => 'error',
            'reason' => 'internal_error',
        ]));
        return;
    }

    $code = generateCode($sku);
    $storage->set("provider:issued:{$requestId}", $code);

    $logger->info("Success", [
        'provider' => $providerName,
        'request_id' => $requestId,
        'code' => $code,
    ]);

    $res->end(json_encode([
        'status' => 'ok',
        'request_id' => $requestId,
        'code' => $code,
    ]));
});

function generateCode(string $sku): string
{
    if (str_starts_with($sku, 'STEAM-TOPUP') || str_starts_with($sku, 'GIFT-')) {
        return 'TOPUP-' . strtoupper(bin2hex(random_bytes(4)));
    }

    return strtoupper(implode('-', [
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
    ]));
}

$server->start();
