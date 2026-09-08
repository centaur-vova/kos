<?php

declare(strict_types=1);

namespace App\Domain\Repository;

interface ReconciliationRepository
{
    /** @return array{paidNotDelivered: array, deliveredNotPaid: array, stuckDelivering: array} */
    public function findAnomalies(int $stuckAfterMin): array;
}
