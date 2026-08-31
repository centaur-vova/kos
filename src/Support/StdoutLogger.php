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

        $microtime = microtime(true);
        $date = (new \DateTimeImmutable("@" . sprintf("%.3f", $microtime)))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('H:i:s.v');

        $output = sprintf(
            "[%s] [%s] %s %s\n",
            $date,
            strtoupper($levelString),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );

        echo $output;
    }
}
