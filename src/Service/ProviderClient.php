<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Exception\Provider\ProviderException;
use App\Exception\Provider\ProviderTimeoutException;
use Swoole\Coroutine\Http\Client;

class ProviderClient
{
    private array $providers;

    public function __construct()
    {
        $this->providers = [
            'A' => [
                'host' => Config::get('PROVIDER_A_HOST', 'localhost'),
                'port' => Config::getInt('PROVIDER_A_PORT', 8000),
            ],
            'B' => [
                'host' => Config::get('PROVIDER_B_HOST', 'localhost'),
                'port' => Config::getInt('PROVIDER_B_PORT', 8000),
            ],
        ];
    }

    public function issue(
        string $requestId,
        string $sku,
        string $orderCode,
        string $provider,
        int $timeoutMs,
    ): array {
        $providerConfig = $this->providers[$provider] ?? $this->providers['A'];

        $client = new Client(
            $providerConfig['host'],
            $providerConfig['port'],
            false
        );

        $client->set([
            'timeout' => $timeoutMs / 1000,
        ]);

        $payload = json_encode([
            'request_id' => $requestId,
            'sku' => $sku,
            'order_code' => $orderCode,
        ]);

        $client->post('/issue', $payload);

        if ($client->errCode !== 0) {
            $this->closeClient($client);

            if ($client->errCode === SOCKET_ETIMEDOUT || $client->errCode === SOCKET_ECONNRESET) {
                throw new ProviderTimeoutException(
                    "Provider {$provider} timeout (err: {$client->errCode})"
                );
            }

            throw new ProviderException(
                "Provider {$provider} connection error: {$client->errCode}"
            );
        }

        $statusCode = $client->statusCode;
        $body = $client->body;

        $this->closeClient($client);

        if ($statusCode >= 500) {
            throw new ProviderException("Provider {$provider} error: HTTP {$statusCode}");
        }

        $response = json_decode($body, true);

        if (!$response || !isset($response['status'])) {
            throw new ProviderException("Invalid provider {$provider} response");
        }

        return $response;
    }

    private function closeClient(Client $client): void
    {
        try {
            $client->close();
        } catch (\Throwable $e) {
            // Игнорируем ошибки закрытия
        }
    }
}
