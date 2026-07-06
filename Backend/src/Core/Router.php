<?php
declare(strict_types=1);

namespace App\Core;

use Closure;

class Router
{
    private array $routes = [];

    public function add(string $method, string $path, array|Closure $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'path'    => $path,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $uri    = $request->uri();

        // Answer OPTIONS preflight immediately
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) continue;

            // Convert {param} placeholders to named capture groups
            $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $this->invoke($route['handler'], $request, $params);
                return;
            }
        }

        Response::error('Endpoint not found', 404);
    }

    private function invoke(array|Closure $handler, Request $request, array $params): void
    {
        if ($handler instanceof Closure) {
            $handler($request, $params);
            return;
        }
        [$class, $method] = $handler;
        (new $class())->$method($request, $params);
    }
}
