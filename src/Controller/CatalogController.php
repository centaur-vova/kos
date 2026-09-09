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
        $limit = (int)($request->get['limit'] ?? 100);
        $offset = (int)($request->get['offset'] ?? 0);

        // Ограничиваем
        $limit = min(max($limit, 1), 500); // max 500 за запрос

        $products = $this->catalogService->getAvailableProducts($limit, $offset);

        return ApiResponse::success(['products' => $products]);
    }
}
