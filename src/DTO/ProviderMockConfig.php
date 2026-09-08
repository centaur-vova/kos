<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class ProviderMockConfig
{
    public function __construct(
        public int $errorRate = 0,
        public int $timeoutRate = 0,
        public int $dishonestRate = 0,
        /** @var string[] */
        public array $blockedSkus = [],
        public ?string $forceDuplicateCode = null,
    ) {
    }

    public function merge(array $data): self
    {
        return new self(
            errorRate: array_key_exists('error_rate', $data) ? (int)$data['error_rate'] : $this->errorRate,
            timeoutRate: array_key_exists('timeout_rate', $data) ? (int)$data['timeout_rate'] : $this->timeoutRate,
            dishonestRate: array_key_exists('dishonest_rate', $data) ? (int)$data['dishonest_rate'] : $this->dishonestRate,
            blockedSkus: array_key_exists('blocked_skus', $data) ? (array)$data['blocked_skus'] : $this->blockedSkus,
            forceDuplicateCode: array_key_exists('force_duplicate_code', $data) ? (string)$data['force_duplicate_code'] : $this->forceDuplicateCode,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            errorRate: (int)($data['error_rate'] ?? 0),
            timeoutRate: (int)($data['timeout_rate'] ?? 0),
            dishonestRate: (int)($data['dishonest_rate'] ?? 0),
            blockedSkus: isset($data['blocked_skus']) ? (array)$data['blocked_skus'] : [],
            forceDuplicateCode: isset($data['force_duplicate_code']) ? (string)$data['force_duplicate_code'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'error_rate' => $this->errorRate,
            'timeout_rate' => $this->timeoutRate,
            'dishonest_rate' => $this->dishonestRate,
            'blocked_skus' => $this->blockedSkus,
            'force_duplicate_code' => $this->forceDuplicateCode,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        return self::fromArray(is_array($data) ? $data : []);
    }

    public function isSkuBlocked(string $sku): bool
    {
        return in_array($sku, $this->blockedSkus, true);
    }
}
