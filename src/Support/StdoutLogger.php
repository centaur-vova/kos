<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Log\AbstractLogger;

class StdoutLogger extends AbstractLogger
{
    /** @var array<string, mixed> */
    private array $levels = [
        'emergency' => 0, 'alert' => 1, 'critical' => 2, 'error' => 3,
        'warning' => 4, 'notice' => 5, 'info' => 6, 'debug' => 7, 'trace' => 8,
    ];

    public function __construct(
        private readonly string $minLevel = 'info',
    ) {
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $levelString = is_scalar($level) ? (string) $level : 'info';

        $currentLevelWeight = $this->levels[$levelString] ?? 6;
        $minLevelWeight = $this->levels[$this->minLevel] ?? 6;

        if ($currentLevelWeight > $minLevelWeight) {
            return;
        }

        $time = microtime(true);
        $date = date('H:i:s', (int)$time);
        $ms = sprintf('%03d', ($time - (int)$time) * 1000);

        $output = sprintf(
            "[%s.%s] [%s] %s %s\n",
            $date,
            $ms,
            strtoupper($levelString),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : ''
        );

        echo $output;
    }
}
