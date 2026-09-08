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
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            errorRate: (int)($data['error_rate'] ?? 0),
            timeoutRate: (int)($data['timeout_rate'] ?? 0),
            dishonestRate: (int)($data['dishonest_rate'] ?? 0),
            blockedSkus: isset($data['blocked_skus']) ? (array)$data['blocked_skus'] : [],
        );
    }

    public function toArray(): array
    {
        return [
            'error_rate' => $this->errorRate,
            'timeout_rate' => $this->timeoutRate,
            'dishonest_rate' => $this->dishonestRate,
            'blocked_skus' => $this->blockedSkus,
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
