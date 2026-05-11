<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $config;
    private array $routes = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function get(string $path, callable $handler): void
    {
        $this->map('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->map('POST', $path, $handler);
    }

    private function map(string $method, string $path, callable $handler): void
    {
        $this->routes[$method][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $handler = $this->routes[$method][$path] ?? null;

        if ($handler === null) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $request = [
            'query' => $_GET,
            'body' => $_POST,
            'files' => $_FILES,
            'method' => $method,
            'path' => $path,
        ];

        if ($method === 'POST' && !Auth::validateCsrfToken($request['body']['_csrf_token'] ?? null)) {
            http_response_code(419);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Your session security token expired. Please go back, refresh the page, and try again.';
            return;
        }

        $response = $handler($request, $this->config);

        if (is_array($response) || is_object($response)) {
            header('Content-Type: application/json');
            echo json_encode($response, JSON_PRETTY_PRINT);
            return;
        }

        echo (string)$response;
    }
}
