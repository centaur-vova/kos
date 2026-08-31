<?php

declare(strict_types=1);

namespace App;

use Swoole\Http\Request;
use Swoole\Http\Response;
use App\Container;

class Router
{
    private array $routes = [];

    public function post(string $path, callable|array $handler): self
    {
        $this->routes['POST'][$path] = $handler;
        return $this;
    }

    public function get(string $path, callable|array $handler): self
    {
        $this->routes['GET'][$path] = $handler;
        return $this;
    }

    public function dispatch(Request $request, Response $response): void
    {
        $method = $request->server['request_method'] ?? 'GET';
        $uri = $request->server['request_uri'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        // Точное совпадение
        if (isset($this->routes[$method][$path])) {
            $this->callHandler($this->routes[$method][$path], $request, $response, []);
            return;
        }

        // С параметрами
        foreach ($this->routes[$method] ?? [] as $routePath => $handler) {
            $params = $this->matchParams($routePath, $path);
            if ($params !== null) {
                $this->callHandler($handler, $request, $response, $params);
                return;
            }
        }

        $response->status(404);
        $response->end(json_encode([
            'status' => 'error',
            'message' => 'Not Found',
        ]));
    }

    private function callHandler(callable|array $handler, Request $request, Response $response, array $params): void
    {
        if (is_array($handler) && count($handler) === 2) {
            [$controllerClass, $method] = $handler;

            // Получаем контроллер из DI-контейнера
            $controller = Container::get($controllerClass);
            $controller->$method($request, $response, $params);
        } else {
            $handler($request, $response, $params);
        }
    }

    private function matchParams(string $routePath, string $uriPath): ?array
    {
        $routeParts = explode('/', trim($routePath, '/'));
        $uriParts = explode('/', trim($uriPath, '/'));

        if (count($routeParts) !== count($uriParts)) {
            return null;
        }

        $params = [];
        foreach ($routeParts as $index => $part) {
            if (str_starts_with($part, '{') && str_ends_with($part, '}')) {
                $paramName = trim($part, '{}');
                $params[$paramName] = urldecode($uriParts[$index]);
            } elseif ($part !== $uriParts[$index]) {
                return null;
            }
        }

        return $params;
    }
}
