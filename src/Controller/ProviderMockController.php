<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class ProviderMockController
{
    private string $providerName;
    private int $errorRate;
    private int $timeoutRate;
    private int $timeoutDuration;
    private int $dishonestRate;

    public function __construct()
    {
        $this->providerName = getenv('PROVIDER_NAME') ?: 'A';
        $this->errorRate = (int)(getenv('MOCK_ERROR_RATE') ?? 20);
        $this->timeoutRate = (int)(getenv('MOCK_TIMEOUT_RATE') ?? 10);
        $this->timeoutDuration = (int)(getenv('MOCK_TIMEOUT_DURATION_SEC') ?? 7);
        $this->dishonestRate = (int)(getenv('MOCK_DISHONEST_RATE') ?? 0);
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
        ]);

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

        // Идемпотентность: проверка кэша в shared-памяти Swoole\Table
        $existingCode = $storage->get("provider:issued:{$requestId}");
        if ($existingCode) {
            $this->jsonResponse($res, 200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $existingCode]);
            return;
        }

        // Расчет детерминированного поведения
        $hash = crc32($requestId . $sku . $this->providerName);
        $behavior = $hash % 100;

        // Распределяем сценарии по изолированным методам
        if ($behavior < $this->timeoutRate) {
            $this->handleTimeoutScenario($res, $storage, $logger, $requestId, $sku);
            return;
        }

        if ($behavior < ($this->timeoutRate + $this->errorRate + $this->dishonestRate)) {
            $this->handleDishonestOrErrorScenario($res, $storage, $logger, $requestId, $sku, $hash);
            return;
        }

        $this->handleSuccessScenario($res, $storage, $requestId, $sku);
    }

    private function handleTimeoutScenario(Response $res, StorageInterface $storage, LoggerInterface $logger, string $requestId, string $sku): void
    {
        $code = $this->generateCode($sku);
        $storage->set("provider:issued:{$requestId}", $code);

        $logger->info("Timeout simulation start", ['request_id' => $requestId, 'duration' => $this->timeoutDuration]);
        Coroutine::sleep($this->timeoutDuration);

        $this->jsonResponse($res, 200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $code]);
    }

    private function handleDishonestOrErrorScenario(Response $res, StorageInterface $storage, LoggerInterface $logger, string $requestId, string $sku, int $hash): void
    {
        $dishonestType = $hash % 3;

        switch ($dishonestType) {
            case 0:
                $duplicateCode = $storage->get("provider:last_global_code") ?: 'DUP-CODE-1111-2222';
                $logger->warning("Dishonest: returning duplicate code", ['request_id' => $requestId, 'code' => $duplicateCode]);
                $this->jsonResponse($res, 200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $duplicateCode]);
                return;

            case 1:
                $code = $this->generateCode($sku);
                $storage->set("provider:issued:{$requestId}", $code);
                $logger->warning("Dishonest: 500 error but code internally issued", ['request_id' => $requestId]);
                $this->jsonResponse($res, 500, ['status' => 'error', 'reason' => 'internal_server_error']);
                return;

            case 2:
                $wrongCode = $this->generateCode('WRONG-SKU-FORMAT');
                $logger->warning("Dishonest: returning wrong format code", ['request_id' => $requestId, 'code' => $wrongCode]);
                $this->jsonResponse($res, 200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $wrongCode]);
                return;
        }

        $this->jsonResponse($res, 500, ['status' => 'error', 'reason' => 'internal_error']);
    }

    private function handleSuccessScenario(Response $res, StorageInterface $storage, string $requestId, string $sku): void
    {
        $code = $this->generateCode($sku);
        $storage->set("provider:issued:{$requestId}", $code);
        $storage->set("provider:last_global_code", $code);

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
