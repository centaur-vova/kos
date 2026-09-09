<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ApiResponse;
use App\Service\CatalogService;
use App\Service\OrderService;
use Swoole\Http\Request;

final readonly class CatalogController
{
    public function __construct(
        private CatalogService $catalogService,
        private OrderService $orderService,
    ) {
    }

    public function index(Request $request, array $params): ApiResponse
    {
        $products = $this->catalogService->getAvailableProducts();

        return ApiResponse::success(['products' => $products]);
    }

    public function resetDB(Request $request, array $params): ApiResponse
    {
        $this->catalogService->resetStock();
        $this->orderService->truncateOrders();

        return ApiResponse::success();
    }
}
