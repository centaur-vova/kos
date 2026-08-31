<?php

declare(strict_types=1);

namespace App\Logger;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\UidProcessor;
use Monolog\Processor\WebProcessor;

class PaymentLogger
{
    private static ?Logger $instance = null;

    public static function getInstance(): Logger
    {
        if (self::$instance === null) {
            $logger = new Logger('payment');

            // Все логи в stdout — для Docker
            $logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

            // Добавляем уникальный ID для каждого запроса
            $logger->pushProcessor(new UidProcessor());

            self::$instance = $logger;
        }

        return self::$instance;
    }

    public static function log(string $message, array $context = [], string $level = 'info'): void
    {
        $logger = self::getInstance();

        $logger->$level($message, $context);
    }
}
