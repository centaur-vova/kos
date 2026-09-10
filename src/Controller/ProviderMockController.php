<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\DTO\Provider\ProviderMockConfig;
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

        // Ручка конфигурации мока провайдера
        if ($req->server['request_uri'] === '/configure' && $req->server['request_method'] === 'POST') {
            $body = json_decode($req->getContent(), true);
            $body = is_array($body) ? $body : [];

            $logger->info('Received new config body', [$body]);

            $key = "provider:config:{$this->providerName}";

            // Сброс состояния кэша перед выполнением изоляции сценария (Test Isolation)
            if (isset($body['reset_state']) && $body['reset_state'] === true) {
                $storage->del($key);
                $logger->info('Provider configuration state fully reset to zero defaults', ['provider' => $this->providerName]);
                $this->jsonResponse($res, 200, ['status' => 'ok']);
                return;
            }

            // Чтение текущего состояния и атомарное обновление иммутабельного DTO (PATCH-паттерн)
            $currentConfig = $this->loadConfig($storage, $key);
            $config = $currentConfig->merge($body);

            $logger->info('Updating config with delta', [
                'provider' => $this->providerName,
                'config' => $config->toArray(),
            ]);

            // Фиксация результирующего JSON в разделяемой памяти Swoole\Table
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

        // Проверка идемпотентности запроса (Double-Spending и сетевые повторы)
        $existingCode = $storage->get("provider:issued:{$requestId}");
        if ($existingCode) {
            $this->jsonResponse($res, 200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $existingCode]);
            return;
        }

        // Загрузка монолита конфигурации из Shared Memory ОС
        $key = "provider:config:{$this->providerName}";
        $config = $this->loadConfig($storage, $key);

        $logger->info("Read config for operation execution", [
            'providerName' => $this->providerName,
            'config' => $config->toArray(),
        ]);

        // Проверка блокировки товарной позиции (SKU Blocking Policy)
        if ($config->isSkuBlocked($sku)) {
            $logger->info('SKU blocked', ['sku' => $sku]);
            $this->jsonResponse($res, 200, ['status' => 'error', 'reason' => 'out_of_stock']);
            return;
        }

        $logger->info("Incoming execution parameters", [
            'provider' => $this->providerName,
            'request_id' => $requestId,
            'sku' => $sku,
            'config_force_code' => $config->forceDuplicateCode
        ]);

        // Форсированная симуляция дубликатов цифровых кодов для проверки анти-фрод логики
        if ($config->forceDuplicateCode !== null && $config->forceDuplicateCode !== '') {
            $logger->warning('Force duplicate code triggered', ['request_id' => $requestId, 'code' => $config->forceDuplicateCode]);

            // Отдаем оба ключа, чтобы гарантированно удовлетворить контракт ProviderClient
            $this->jsonResponse($res, 200, [
                'status' => 'ok',
                'request_id' => $requestId,
                'code' => $config->forceDuplicateCode,
                'delivered_code' => $config->forceDuplicateCode
            ]);
            return;
        }

        // Расчёт детерминированного поведения на основе crc32-хэша параметров запроса
        $hash = crc32($requestId . $sku . $this->providerName);
        $behavior = $hash % 100;

        // 1. Симуляция явного отказа интеграционного шлюза (Internal Server Error)
        if ($behavior < ($config->errorRate ?? 0)) {
            $logger->info('Error simulation', ['request_id' => $requestId]);
            $this->jsonResponse($res, 200, ['status' => 'error', 'reason' => 'internal_error']);
            return;
        }

        // 2. Симуляция жесткого таймаута сети. Запись в кэш перенесена ПОСЛЕ сна для имитации обрыва SLA клиента
        if ($behavior < (($config->errorRate ?? 0) + ($config->timeoutRate ?? 0))) {
            $logger->info('Timeout simulation start', ['request_id' => $requestId]);

            Coroutine::sleep(7);

            $code = $this->generateCode($sku);
            $storage->set("provider:issued:{$requestId}", $code);

            $this->jsonResponse($res, 200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $code]);
            return;
        }

        // 3. Симуляция недобросовестного поведения поставщика (3 сценария расхождения балансов)
        if ($behavior < (($config->errorRate ?? 0) + ($config->timeoutRate ?? 0) + ($config->dishonestRate ?? 0))) {
            // Если dishonestRate = 100 — всегда HTTP 500 (скрытая утечка)
            if (($config->dishonestRate ?? 0) === 100) {
                $code = $this->generateCode($sku);
                $storage->set("provider:issued:{$requestId}", $code);

                $logger->error('Dishonest: 500 error but code internally issued', ['request_id' => $requestId]);
                $this->jsonResponse($res, 500, ['status' => 'error', 'reason' => 'internal_server_error']);
                return;
            }

            $dishonestType = $hash % 3;
            if ($dishonestType === 0) {
                // Кейс 0: Выдача дубликата кода из глобальной Shared Memory
                $duplicateCode = $storage->get('provider:last_global_code') ?: 'DUP-CODE-1111-2222';
                $logger->warning('Dishonest: duplicate code issued', ['request_id' => $requestId, 'code' => $duplicateCode]);
                $this->jsonResponse($res, 200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $duplicateCode]);
                return;
            } elseif ($dishonestType === 1) {
                // Кейс 1: Нарушение контракта маски формата цифрового товара
                $wrongCode = $this->generateCode('WRONG-SKU-FORMAT');
                $logger->warning('Dishonest: wrong format code issued', ['request_id' => $requestId, 'code' => $wrongCode]);
                $this->jsonResponse($res, 200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $wrongCode]);
                return;
            }
        }

        // Базовый успешный сценарий выдачи айтема
        $code = $this->generateCode($sku);
        $storage->set("provider:issued:{$requestId}", $code);
        $storage->set('provider:last_global_code', $code);

        $logger->info('Success execution', ['request_id' => $requestId, 'code' => $code]);

        $this->jsonResponse($res, 200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $code]);
    }

    /**
     * Извлечение и безопасная десериализация DTO-контракта из разделяемой памяти Swoole\Table
     */
    private function loadConfig(StorageInterface $storage, string $key): ProviderMockConfig
    {
        $configRow = $storage->get($key);
        $configJson = is_array($configRow) ? ($configRow['value'] ?? null) : $configRow;

        return $configJson ? ProviderMockConfig::fromJson((string)$configJson) : ProviderMockConfig::createDefault();
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
