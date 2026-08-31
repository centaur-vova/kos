<?php

declare(strict_types=1);

namespace App\Http;

use Swoole\Http\Response;

class ApiResponse
{
    public static function success(Response $response, mixed $data = null, int $status = 200): void
    {
        $response->status($status);
        $response->header('Content-Type', 'application/json');

        $payload = [
            'status' => 'ok',
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        $response->end(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public static function error(Response $response, string $message, int $status = 400, string $code = null): void
    {
        $response->status($status);
        $response->header('Content-Type', 'application/json');

        $payload = [
            'status' => 'error',
            'error' => [
                'code' => $code ?? 'bad_request',
                'message' => $message,
            ],
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];

        $response->end(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
