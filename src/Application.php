<?php

declare(strict_types=1);

namespace App;

use App\Config\Options;
use App\Http\ApiResponse;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;

class Application
{
    private Router $router;

    public function __construct(
        private readonly Options $options,
        private readonly LoggerInterface $logger,
    ) {
        $this->router = Container::get(Router::class);
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        $this->router->get('/reconciliation', [Controller\ReconciliationController::class, 'index']);
        $this->router->post('/orders', [Controller\OrderController::class, 'create']);
        $this->router->get('/orders/{id}', [Controller\OrderController::class, 'show']);
        $this->router->post('/webhook/payment', [Controller\WebhookController::class, 'handle']);
        $this->router->get('/health', static fn () => ApiResponse::success(['status' => 'ok']));
    }

    public function handle(Request $request, Response $response): void
    {
        try {
            $apiResponse = $this->router->dispatch($request);
            $this->sendResponse($apiResponse, $response);
        } catch (\Throwable $e) {
            $this->logger->error('Application exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->sendResponse(
                ApiResponse::error('Internal Server Error', 500, 'internal_error'),
                $response
            );
        }
    }

    private function sendResponse(ApiResponse $apiResponse, Response $response): void
    {
        $response->status($apiResponse->status);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($apiResponse->payload, JSON_UNESCAPED_UNICODE));
    }

    public function getOptions(): Options
    {
        return $this->options;
    }
}
