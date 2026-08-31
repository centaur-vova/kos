<?php

declare(strict_types=1);

namespace App;

use App\Config\Options;
use Swoole\Http\Request;
use Swoole\Http\Response;

class Application
{
    private Router $router;

    public function __construct(
        private readonly Options $options,
    ) {
        $this->router = new Router();
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        $this->router->post('/orders', [Controller\OrderController::class, 'create']);
        $this->router->get('/orders/{id}', [Controller\OrderController::class, 'show']);
        $this->router->post('/webhook/payment', [Controller\WebhookController::class, 'handle']);
        $this->router->get('/health', function (Request $req, Response $res) {
            $res->end(json_encode(['status' => 'ok']));
        });
    }

    public function handle(Request $request, Response $response): void
    {
        $this->router->dispatch($request, $response);
    }

    public function getOptions(): Options
    {
        return $this->options;
    }
}
