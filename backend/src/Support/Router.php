<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lightweight, zero-dependency REST router supporting parameterized paths,
 * HTTP method validation, middleware pipelines, and structured 404/405 errors.
 */
class Router
{
    /** @var array<int, array{method: string, pattern: string, regex: string, handler: callable|array{class-string, string}, params: array<int, string>, middleware: array<int, callable>}> */
    private array $routes = [];

    /** @var array<int, callable> */
    private array $globalMiddleware = [];

    /** The route pattern that matched the current request (e.g. /api/v1/products/{id}), if any. */
    public static ?string $matchedRoute = null;

    /**
     * Register a global middleware executed before every matched route.
     *
     * @param callable $middleware
     */
    public function use(callable $middleware): self
    {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }

    /**
     * Register a route for any HTTP method.
     *
     * @param string $method HTTP verb (GET, POST, PUT, DELETE, PATCH, OPTIONS)
     * @param string $path Route path with optional parameters e.g. /api/v1/products/{id}. A parameter
     *                     takes letters, digits, _ and - unless it names its own pattern, e.g. {code:[^/]+}.
     *                     It is matched as sent, so a wider pattern's handler must URL-decode it.
     * @param callable|array{class-string, string} $handler Closure or [ControllerClass, 'method']
     * @param array<int, callable> $middleware Route-specific middleware
     */
    public function add(string $method, string $path, callable|array $handler, array $middleware = []): self
    {
        // Normalize path
        $path = '/' . trim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        // Convert {param} into regex capture groups
        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z0-9_]+)(?::([^{}]+))?\}/', function ($matches) use (&$paramNames) {
            $paramNames[] = $matches[1];
            return '(' . ($matches[2] ?? '[a-zA-Z0-9_\-]+') . ')';
        }, $path);

        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => preg_replace('/\{([a-zA-Z0-9_]+):[^{}]+\}/', '{$1}', $path),
            'regex' => $regex,
            'handler' => $handler,
            'params' => $paramNames,
            'middleware' => $middleware,
        ];

        return $this;
    }

    public function get(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->add('DELETE', $path, $handler, $middleware);
    }

    /**
     * Dispatch the current HTTP request to the matching route handler.
     */
    public function dispatch(): void
    {
        $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $rawUri = $_SERVER['REQUEST_URI'] ?? '/';

        // Parse path ignoring query string
        $path = parse_url($rawUri, PHP_URL_PATH) ?? '/';

        // Strip any base folder prefix if running under XAMPP subdirectory (e.g. /shop-api/public/api/v1 -> /api/v1)
        $scriptName = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptName !== '/' && $scriptName !== '\\' && str_starts_with($path, $scriptName)) {
            $path = substr($path, strlen($scriptName));
        }

        $path = '/' . trim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        // Run global middleware first
        foreach ($this->globalMiddleware as $mw) {
            $mw($requestMethod, $path);
        }

        $matchedPath = false;
        $allowedMethods = [];

        // Exact (parameter-free) routes win over parameterised ones, so e.g.
        // /admin/orders/export is never swallowed by /admin/orders/{idOrNumber}.
        $orderedRoutes = array_merge(
            array_filter($this->routes, static fn (array $r): bool => empty($r['params'])),
            array_filter($this->routes, static fn (array $r): bool => !empty($r['params']))
        );

        foreach ($orderedRoutes as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                $matchedPath = true;
                $allowedMethods[] = $route['method'];

                if ($route['method'] === $requestMethod) {
                    self::$matchedRoute = $route['pattern'];
                    // Extract named parameters
                    array_shift($matches); // drop full match
                    $params = [];
                    foreach ($route['params'] as $i => $name) {
                        $params[$name] = $matches[$i] ?? null;
                    }

                    // Run route-specific middleware
                    foreach ($route['middleware'] as $mw) {
                        $mw($params);
                    }

                    // Invoke handler
                    $handler = $route['handler'];
                    if (is_array($handler)) {
                        [$controllerClass, $action] = $handler;
                        $controller = new $controllerClass();
                        $controller->$action($params);
                    } else {
                        $handler($params);
                    }
                    return;
                }
            }
        }

        if ($matchedPath) {
            header('Allow: ' . implode(', ', array_unique($allowedMethods)));
            Response::error(
                sprintf('Method %s not allowed for this route. Allowed: %s', $requestMethod, implode(', ', array_unique($allowedMethods))),
                405,
                'METHOD_NOT_ALLOWED'
            );
        }

        Response::error(
            sprintf('Endpoint not found: %s %s', $requestMethod, $path),
            404,
            'ROUTE_NOT_FOUND'
        );
    }
}
