<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\Options;
use App\Domain\Repository\ReconciliationRepository;
use App\Http\ApiResponse;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request;

final readonly class ReconciliationController
{
    public function __construct(
        private ReconciliationRepository $reconciliationRepository,
        private Options $options,
        private LoggerInterface $logger,
    ) {
    }

    public function index(Request $request, array $params): ApiResponse
    {
        $this->logger->info('Reconciliation requested');

        $result = $this->reconciliationRepository->findAnomalies(
            $this->options->recoveryStuckAfterMin,
        );

        return ApiResponse::success([
            'summary' => [
                'paid_not_delivered' => count($result['paidNotDelivered']),
                'delivered_not_paid' => count($result['deliveredNotPaid']),
                'stuck_delivering' => count($result['stuckDelivering']),
            ],
            'details' => [
                'paid_not_delivered' => $result['paidNotDelivered'],
                'delivered_not_paid' => $result['deliveredNotPaid'],
                'stuck_delivering' => $result['stuckDelivering'],
            ],
        ]);
    }
}
