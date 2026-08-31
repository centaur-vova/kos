<?php

declare(strict_types=1);

namespace App;

use Dotenv\Dotenv;

class Config
{
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        $dotenv = Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->safeLoad();

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int)self::get($key, $default);
    }

    public static function getFloat(string $key, float $default = 0.0): float
    {
        return (float)self::get($key, $default);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['true', '1', 'yes', 'on'], true);
    }

    public static function getArray(string $key, array $default = []): array
    {
        $value = self::get($key);

        if (is_array($value)) {
            return $value;
        }

        if (empty($value)) {
            return $default;
        }

        return array_map('trim', explode(',', (string)$value));
    }
}
