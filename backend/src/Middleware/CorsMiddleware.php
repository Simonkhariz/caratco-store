<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Env;

/**
 * Strict, environment-driven CORS middleware.
 * Enforces explicit frontend origin allowlists and handles browser OPTIONS preflights.
 */
class CorsMiddleware
{
    /**
     * Handle incoming CORS headers and respond to preflight requests.
     */
    public static function handle(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $allowedOriginsRaw = Env::get('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://127.0.0.1:3000,http://localhost:3001,http://127.0.0.1:3001');
        $allowedOrigins = array_map('trim', explode(',', $allowedOriginsRaw));

        $isAllowed = false;

        if ($origin !== null) {
            if (in_array($origin, $allowedOrigins, true)) {
                $isAllowed = true;
            } elseif (strtolower((string) Env::get('APP_ENV', 'development')) === 'development') {
                // Local development only: permit any localhost/127.0.0.1 origin regardless of port
                $parsed = parse_url($origin);
                $host = $parsed['host'] ?? '';
                if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                    $isAllowed = true;
                }
            }
        }

        if ($isAllowed && $origin !== null) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With, X-CSRF-Token, X-Guest-Access-Token, X-Order-Email, X-Payment-Reference, X-Device-Id, Idempotency-Key');
            header('Access-Control-Max-Age: 86400');
            header('Vary: Origin');
        }

        // Handle preflight requests cleanly
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
