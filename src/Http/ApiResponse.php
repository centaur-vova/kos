<?php

declare(strict_types=1);

namespace App\Http;

final readonly class ApiResponse
{
    private const STATUS_OK = 'ok';
    private const STATUS_ERROR = 'error';

    public function __construct(
        public int $status,
        public array $payload,
    ) {
    }

    public static function success(mixed $data = null, int $status = 200): self
    {
        $payload = [
            'status' => self::STATUS_OK,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return new self($status, $payload);
    }

    public static function error(string $message, int $status = 400, ?string $code = null): self
    {
        return new self($status, [
            'status' => self::STATUS_ERROR,
            'error' => [
                'code' => $code ?? 'bad_request',
                'message' => $message,
            ],
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }
}
