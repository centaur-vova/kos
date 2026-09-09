<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\Provider\ProviderException;
use App\Exception\Provider\ProviderTimeoutException;
use App\Config\Options;
use Swoole\Coroutine\Http\Client;

final readonly class ProviderClient
{
    public function __construct(
        private Options $options,
    ) {
    }


    public function issue(
        string $requestId,
        string $sku,
        string $orderCode,
        string $provider,
    ): array {
        $config = $this->options->getProvider($provider);

        $client = new Client(
            $config->host,
            $config->port,
        );

        $client->set([
            'timeout' => $config->timeoutMs / 1000,
        ]);

        $payload = json_encode([
            'request_id' => $requestId,
            'sku' => $sku,
            'order_code' => $orderCode,
        ]);

        $client->post('/issue', $payload);

        if ($client->errCode !== 0) {
            $this->closeClient($client);

            if (in_array($client->errCode, [
                SWOOLE_ERROR_CO_TIMEDOUT,
                SOCKET_ETIMEDOUT,
            ], true)) {
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

        if ($statusCode < 0 || $statusCode >= 500) {
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
        } catch (\Throwable) {
            // Игнорируем ошибки закрытия
        }
    }
}
