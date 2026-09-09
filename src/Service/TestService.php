<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Repository\OrderRepository;
use App\Domain\Repository\PaymentRepository;
use App\DTO\PaymentProcessingResult;
use App\DTO\PaymentWebhook;
use App\Enum\OrderEventType;
use App\Enum\OrderStatus;
use Psr\Log\LoggerInterface;

final readonly class TestService
{
    public function __construct(
        private OrderRepository $orderRepository,
        private PaymentRepository $paymentRepository,
        private EventSourcingService $eventSourcingService,
        private DeliveryService $deliveryService,
        private LoggerInterface $logger,
    ) {
    }

    public function resetState(): void
    {
    }
}
