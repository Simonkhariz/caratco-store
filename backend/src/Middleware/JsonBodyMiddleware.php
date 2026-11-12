<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Response;

/**
 * Middleware ensuring incoming mutating HTTP requests supply valid JSON Content-Type
 * and parsing the raw input stream into structured data.
 */
class JsonBodyMiddleware
{
    /** @var array<string, mixed> */
    private static array $parsedBody = [];

    /**
     * Inspect and parse the request body.
     */
    public static function handle(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return;
        }

        $rawBody = file_get_contents('php://input');

        // If body is empty, nothing to parse
        if ($rawBody === false || trim($rawBody) === '') {
            self::$parsedBody = [];
            return;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

        if (str_contains(strtolower($contentType), 'multipart/form-data')) {
            self::$parsedBody = $_POST;
            return;
        }

        if (!str_contains(strtolower($contentType), 'application/json')) {
            Response::error(
                'Invalid Content-Type header. Expected application/json for payload requests.',
                415,
                'UNSUPPORTED_MEDIA_TYPE'
            );
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                Response::error(
                    'Invalid JSON body. Root element must be a valid JSON object or array.',
                    400,
                    'MALFORMED_JSON'
                );
            }
            self::$parsedBody = $decoded;
        } catch (\JsonException $e) {
            Response::error(
                'Malformed JSON body: ' . $e->getMessage(),
                400,
                'INVALID_JSON'
            );
        }
    }

    /**
     * Retrieve the parsed JSON payload.
     *
     * @return array<string, mixed>
     */
    public static function getBody(): array
    {
        return self::$parsedBody;
    }

    /**
     * Set the parsed JSON payload (useful for testing and programmatic invocation).
     *
     * @param array<string, mixed> $body
     */
    public static function setBody(array $body): void
    {
        self::$parsedBody = $body;
    }
}
