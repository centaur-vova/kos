<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentProcessingStatus: string implements \JsonSerializable
{
    case Processed = 'processed';
    case AlreadyProcessed = 'already_processed';
    case AlreadyProcessedRace = 'already_processed_race';
    case ProcessedByOther = 'processed_by_other';
    case DuplicateAfterDelivery = 'duplicate_after_delivery';
    case LatePaymentAfterFailure = 'late_payment_after_failure';
    case OrphanPayment = 'orphan_payment';
    case UnknownStatus = 'unknown_payment_status';

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function is(self $status): bool
    {
        return $this === $status;
    }
}
