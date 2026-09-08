<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\DTO\ProviderMockConfig;
use App\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class ProviderMockController
{
    private string $providerName;

    public function __construct()
    {
        $this->providerName = getenv('PROVIDER_NAME') ?: 'A';
    }

    public function handle(Request $req, Response $res): void
    {
        /** @var LoggerInterface $logger */
        $logger = Container::get(LoggerInterface::class);
        /** @var StorageInterface $storage */
        $storage = Container::get(StorageInterface::class);

        $logger->info('Provider request received', [
            'provider' => $this->providerName,
            'uri' => $req->server['request_uri'],
            'method' => $req->server['request_method'],
        ]);

        // Настройка провайдера
        if ($req->server['request_uri'] === '/configure' && $req->server['request_method'] === 'POST') {
            $body = json_decode($req->getContent(), true);
            $body = is_array($body) ? $body : [];

            $logger->info('Received new config body', [$body]);

            // Читаем текущий конфиг ДЛЯ ЭТОГО провайдера
            $key = "provider:config:{$this->providerName}";
            $currentJson = $storage->get($key);
            $currentConfig = $currentJson ? ProviderMockConfig::fromJson($currentJson) : new ProviderMockConfig();

            // Мержим с новыми данными
            $config = $currentConfig->merge($body);

            $logger->info('Updating config', [
                'provider' => $this->providerName,
                'config' => $config->toArray(),
            ]);

            // Сохраняем ДЛЯ ЭТОГО провайдера
            $storage->set($key, $config->toJson());

            $this->jsonResponse($res, 200, ['status' => 'ok']);
            return;
        }

        if ($req->server['request_uri'] !== '/issue' || $req->server['request_method'] !== 'POST') {
            $this->jsonResponse($res, 404, ['status' => 'error', 'reason' => 'not_found']);
            return;
        }

        $body = json_decode($req->getContent(), true);
        $requestId = $body['request_id'] ?? null;
        $sku = $body['sku'] ?? null;

        if (!$requestId || !$sku) {
            $this->jsonResponse($res, 400, ['status' => 'error', 'reason' => 'bad_request']);
            return;
        }

        // Идемпотентность
        $existingCode = $storage->get("provider:issued:{$requestId}");
        if ($existingCode) {
            $this->jsonResponse($res, 200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $existingCode]);
            return;
        }

        // Читаем конфиг из Shared Memory
        $key = "provider:config:{$this->providerName}";
        $configJson = $storage->get($key);
        $config = $configJson ? ProviderMockConfig::fromJson($configJson) : new ProviderMockConfig();

        $logger->info("Read config", [
            'providerName' => $this->providerName,
            'config' => $config->toArray(),
        ]);

        // Блокировка SKU
        if ($config->isSkuBlocked($sku)) {
            $logger->info('SKU blocked', ['sku' => $sku]);
            $this->jsonResponse($res, 200, ['status' => 'error', 'reason' => 'out_of_stock']);
            return;
        }

        if ($config->forceDuplicateCode !== null) {
            $logger->warning('Force duplicate code', ['request_id' => $requestId, 'code' => $config->forceDuplicateCode]);
            $this->jsonResponse($res, 200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $config->forceDuplicateCode]);
            return;
        }

        // Детерминированное поведение
        $hash = crc32($requestId . $sku . $this->providerName);
        $behavior = $hash % 100;

        // Ошибка
        if ($behavior < $config->errorRate) {
            $logger->info('Error simulation', ['request_id' => $requestId]);
            $this->jsonResponse($res, 200, ['status' => 'error', 'reason' => 'internal_error']);
            return;
        }

        // Таймаут — запись в кэш ПОСЛЕ сна
        if ($behavior < ($config->errorRate + $config->timeoutRate)) {
            $logger->info('Timeout simulation start', ['request_id' => $requestId]);

            Coroutine::sleep(7);

            $code = $this->generateCode($sku);
            $storage->set("provider:issued:{$requestId}", $code);

            $this->jsonResponse($res, 200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $code]);
            return;
        }

        // Недобросовестность — 3 кейса
        if ($behavior < ($config->errorRate + $config->timeoutRate + $config->dishonestRate)) {
            $dishonestType = $hash % 3;

            if ($dishonestType === 0) {
                // Дубль
                $duplicateCode = $storage->get('provider:last_global_code') ?: 'DUP-CODE-1111-2222';
                $logger->warning('Dishonest: duplicate code', ['request_id' => $requestId, 'code' => $duplicateCode]);
                $this->jsonResponse($res, 200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $duplicateCode]);
                return;
            } elseif ($dishonestType === 1) {
                // Чужой формат
                $wrongCode = $this->generateCode('WRONG-SKU-FORMAT');
                $logger->warning('Dishonest: wrong format code', ['request_id' => $requestId, 'code' => $wrongCode]);
                $this->jsonResponse($res, 200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $wrongCode]);
                return;
            } else {
                // Код выдан, но ошибка
                $code = $this->generateCode($sku);
                $storage->set("provider:issued:{$requestId}", $code);

                $logger->error('Dishonest: 500 error but code internally issued', ['request_id' => $requestId]);
                $this->jsonResponse($res, 500, ['status' => 'error', 'reason' => 'internal_server_error']);
                return;
            }
        }

        // Успех
        $code = $this->generateCode($sku);
        $storage->set("provider:issued:{$requestId}", $code);
        $storage->set('provider:last_global_code', $code);

        $logger->info('Success', ['request_id' => $requestId, 'code' => $code]);

        $this->jsonResponse($res, 200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $code]);
    }

    private function jsonResponse(Response $res, int $statusCode, array $data): void
    {
        $res->status($statusCode);
        $res->header('Content-Type', 'application/json');
        $res->end(json_encode($data));
    }

    private function generateCode(string $sku): string
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
}
