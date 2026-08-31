<?php

declare(strict_types=1);

namespace App\Config;

use Dotenv\Dotenv;

final readonly class ConfigLoader
{
    /**
     * @param array<string, mixed> $repository
     */
    public function __construct(
        private array $repository,
    ) {
    }

    /**
     * Initialize config from system environment and optional .env file
     */
    public static function fromEnv(string $path): self
    {
        $fileData = [];
        $envPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';

        // Load from file only if it exists (local dev without Docker)
        if (file_exists($envPath) && is_readable($envPath)) {
            $dotenv = Dotenv::createImmutable($path);
            $fileData = $dotenv->load();
        }

        // Merge: System Env variables have higher priority than .env file
        // This ensures Docker Compose 'env_file' always wins
        /** @var array<string, mixed> $merged */
        $merged = array_merge($fileData, $_ENV);
        return new self($merged);
    }

    public function getString(string $key, string $default = ''): string
    {
        $val = $this->raw($key, $default);
        return is_scalar($val) ? (string) $val : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $val = $this->raw($key, $default);
        return is_scalar($val) ? (int) $val : $default;
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        $val = $this->raw($key, $default);
        return is_scalar($val) ? (float) $val : $default;
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enumClass
     * @param T $default
     * @return T
     */
    public function getEnum(string $key, string $enumClass, \BackedEnum $default): \BackedEnum
    {
        $value = $this->raw($key);
        if ($value === null) {
            return $default;
        }

        /** @var scalar|null $value */
        $strValue = is_scalar($value) ? (string) $value : null;
        if ($strValue === null) {
            throw new \RuntimeException("Invalid value type for {$key}: expected scalar, got " . get_debug_type($value));
        }

        $enum = $enumClass::tryFrom($strValue);
        if ($enum === null) {
            $allowed = array_map(static fn (\BackedEnum $case) => $case->value, $enumClass::cases());
            throw new \RuntimeException("Invalid value for {$key}: '{$strValue}'. Expected one of: " . implode(', ', $allowed));
        }
        return $enum;
    }

    /**
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    public function getArray(string $key, array $default = []): array
    {
        $val = $this->raw($key, $default);

        if (!is_string($val)) {
            return [];
        }

        $decoded = json_decode($val ?: '{}', true);

        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid JSON in {$key}. Expected object, got: " . json_last_error_msg());
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function raw(string $key, mixed $default = null): mixed
    {
        return $this->repository[$key] ?? $default;
    }
}
