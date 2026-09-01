<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\PaymentProcessingStatus;

final readonly class PaymentProcessingResult
{
    public function __construct(
        public PaymentProcessingStatus $status,
        public ?string $paymentStatus = null,
        public ?string $message = null,
        public ?string $delivery = null,
    ) {
    }

    public static function alreadyProcessed(string $paymentStatus): self
    {
        return new self(status: PaymentProcessingStatus::AlreadyProcessed, paymentStatus: $paymentStatus);
    }

    public static function orphanPayment(string $message): self
    {
        return new self(status: PaymentProcessingStatus::OrphanPayment, message: $message);
    }

    public static function duplicateAfterDelivery(): self
    {
        return new self(status: PaymentProcessingStatus::DuplicateAfterDelivery);
    }

    public static function latePaymentAfterFailure(): self
    {
        return new self(status: PaymentProcessingStatus::LatePaymentAfterFailure);
    }

    public static function alreadyProcessedRace(): self
    {
        return new self(status: PaymentProcessingStatus::AlreadyProcessedRace);
    }

    public static function processed(?string $delivery = null): self
    {
        return new self(status: PaymentProcessingStatus::Processed, delivery: $delivery);
    }

    public static function processedByOther(): self
    {
        return new self(status: PaymentProcessingStatus::ProcessedByOther);
    }

    public static function paymentFailed(): self
    {
        return new self(status: PaymentProcessingStatus::Processed, paymentStatus: 'failed');
    }

    public static function unknownStatus(): self
    {
        return new self(status: PaymentProcessingStatus::UnknownStatus);
    }

    public function isProcessed(): bool
    {
        return $this->status->is(PaymentProcessingStatus::Processed);
    }
}
