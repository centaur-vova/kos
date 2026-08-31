<?php

declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use App\Config;
use App\Container;
use App\Storage\StorageInterface;
use Psr\Log\LoggerInterface;

require __DIR__ . '/../vendor/autoload.php';

Config::load();
Container::init();

// Logger
$logger = Container::get(LoggerInterface::class);

// Storage
$storage = Container::get(StorageInterface::class);

$port = Config::getInt('PROVIDER_PORT', 8000);
$providerName = Config::get('PROVIDER_NAME', 'A');

$errorRate = Config::getInt('MOCK_ERROR_RATE', 20);
$timeoutRate = Config::getInt('MOCK_TIMEOUT_RATE', 10);
$timeoutDuration = Config::getInt('MOCK_TIMEOUT_DURATION_SEC', 7);

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
        $logger->info("[Provider {$providerName}] Returning cached code for {$requestId}: {$existingCode}");
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

        $logger->info("[Provider {$providerName}] Timeout simulation for {$requestId}, code issued: {$code}");

        Swoole\Coroutine::sleep($timeoutDuration);

        $res->end(json_encode([
            'status' => 'ok',
            'request_id' => $requestId,
            'code' => $code,
        ]));
        return;
    }

    if ($behavior < ($timeoutRate + $errorRate)) {
        $logger->info("[Provider {$providerName}] Error simulation for {$requestId}");
        $res->status(500);
        $res->end(json_encode([
            'status' => 'error',
            'reason' => 'internal_error',
        ]));
        return;
    }

    $code = generateCode($sku);
    $storage->set("provider:issued:{$requestId}", $code);

    $logger->info("[Provider {$providerName}] Success for {$requestId}: {$code}");

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
