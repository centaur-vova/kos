<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;

class ReconciliationController
{
    public function __construct(
        private Database $db,
        private LoggerInterface $logger,
    ) {
    }

    public function index(Request $request, Response $response, array $params): void
    {
        $this->logger->info('Reconciliation requested');

        $pdo = $this->db->getConnection();

        // Оплачен, но не выдан
        $stmt = $pdo->prepare(
            "SELECT o.* FROM orders o
             WHERE o.status IN ('paid', 'delivering')
             AND o.paid_at IS NOT NULL
             AND o.delivered_at IS NULL
             AND o.updated_at < NOW() - INTERVAL '5 minutes'
             ORDER BY o.created_at"
        );
        $stmt->execute();
        $paidNotDelivered = $stmt->fetchAll();

        // Выдан, но не оплачен (подозрительно)
        $stmt = $pdo->prepare(
            "SELECT o.* FROM orders o
             WHERE o.status = 'delivered'
             AND o.paid_at IS NULL
             ORDER BY o.created_at"
        );
        $stmt->execute();
        $deliveredNotPaid = $stmt->fetchAll();

        // Зависшие в delivering
        $stmt = $pdo->prepare(
            "SELECT o.* FROM orders o
             WHERE o.status = 'delivering'
             AND o.updated_at < NOW() - INTERVAL '1 minute'
             ORDER BY o.updated_at"
        );
        $stmt->execute();
        $stuckDelivering = $stmt->fetchAll();

        $response->end(json_encode([
            'status' => 'ok',
            'timestamp' => (new \DateTimeImmutable())->format('c'),
            'summary' => [
                'paid_not_delivered' => count($paidNotDelivered),
                'delivered_not_paid' => count($deliveredNotPaid),
                'stuck_delivering' => count($stuckDelivering),
            ],
            'details' => [
                'paid_not_delivered' => $paidNotDelivered,
                'delivered_not_paid' => $deliveredNotPaid,
                'stuck_delivering' => $stuckDelivering,
            ],
        ], JSON_UNESCAPED_UNICODE));
    }
}
