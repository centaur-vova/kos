<?php

declare(strict_types=1);

namespace App;

use App\Http\ApiResponse;
use Swoole\Http\Request;

final class Router
{
    private const METHOD_GET = 'GET';
    private const METHOD_POST = 'POST';

    private array $routes = [];

    public function post(string $path, callable|array $handler): self
    {
        $this->routes[self::METHOD_POST][$path] = $handler;
        return $this;
    }

    public function get(string $path, callable|array $handler): self
    {
        $this->routes[self::METHOD_GET][$path] = $handler;
        return $this;
    }

    public function dispatch(Request $request): ApiResponse
    {
        $method = $request->server['request_method'] ?? self::METHOD_GET;
        $uri = $request->server['request_uri'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        // Точное совпадение
        if (isset($this->routes[$method][$path])) {
            return $this->callHandler($this->routes[$method][$path], $request, []);
        }

        // С параметрами
        foreach ($this->routes[$method] ?? [] as $routePath => $handler) {
            $params = $this->matchParams($routePath, $path);
            if ($params !== null) {
                return $this->callHandler($handler, $request, $params);
            }
        }

        return ApiResponse::error('Not Found', 404, 'not_found');
    }

    private function callHandler(callable|array $handler, Request $request, array $params): ApiResponse
    {
        if (is_array($handler) && count($handler) === 2) {
            [$controllerClass, $method] = $handler;
            $controller = Container::get($controllerClass);
            return $controller->$method($request, $params);
        }

        return $handler($request, $params);
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
