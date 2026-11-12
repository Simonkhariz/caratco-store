<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Standardized JSON response helper for the REST API.
 * Ensures consistent output schemas and prevents credential/stack trace leaks.
 */
class Response
{
    /**
     * Send a standardized success JSON response.
     *
     * @param mixed $data Primary payload (object, list, or scalar)
     * @param string $message Friendly status description
     * @param int $status HTTP status code (default: 200 OK)
     * @param array<string, mixed> $meta Optional metadata (pagination, counts, execution timestamp)
     */
    public static function success(
        mixed $data = null,
        string $message = 'Operation completed successfully.',
        int $status = 200,
        array $meta = []
    ): void {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }

        self::json($payload, $status);
    }

    /**
     * Send a standardized paginated collection JSON response.
     */
    public static function paginated(
        array $items,
        int $total,
        int $page,
        int $perPage,
        string $message = 'List retrieved successfully.'
    ): void {
        $totalPages = (int) ceil($total / max(1, $perPage));
        $meta = [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];

        self::success([
            'items' => $items,
            'pagination' => $meta,
        ], $message, 200, $meta);
    }

    /**
     * Send a standardized error JSON response.
     *
     * @param string $message User-safe error description
     * @param int $status HTTP status code (400, 401, 403, 404, 422, 500)
     * @param string $errorCode Machine-readable uppercase error token (e.g. 'VALIDATION_ERROR')
     * @param array<string, mixed> $details Field-level validation issues or safe metadata
     */
    public static function error(
        string $message = 'An unexpected error occurred.',
        int $status = 400,
        string $errorCode = 'BAD_REQUEST',
        array $details = []
    ): void {
        $errorPayload = [
            'code' => $errorCode,
            'message' => $message,
        ];

        if (!empty($details)) {
            $errorPayload['details'] = $details;
        }

        $payload = [
            'success' => false,
            'error' => $errorPayload,
        ];

        self::json($payload, $status);
    }

    /**
     * Output raw JSON with standard HTTP headers and terminate script execution.
     *
     * @param mixed $payload Array or object to encode as JSON
     * @param int $status HTTP status code
     * @param array<string, string> $headers Additional custom HTTP headers
     */
    public static function json(mixed $payload, int $status = 200, array $headers = []): void
    {
        // Discard any buffered stray echo output before emitting clean JSON
        if (ob_get_length()) {
            ob_clean();
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            http_response_code(500);
            echo '{"success":false,"error":{"code":"JSON_ENCODE_ERROR","message":"Failed to serialize response payload."}}';
            exit;
        }

        $public = $status === 200 && self::$publicCache !== null;
        $etag = $public ? '"' . md5($json) . '"' : null;

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            // Only public catalogue data may be kept by browsers; everything else (accounts,
            // orders, admin) is never stored.
            header('Cache-Control: ' . ($public ? self::$publicCache : 'no-store'));
            // Added to (not replacing) the Vary: Origin that CORS may have set, so a shared cache
            // never hands one site's CORS answer to another.
            header('Vary: Accept-Encoding', false);
            if ($etag !== null) {
                header('ETag: ' . $etag);
            }

            foreach ($headers as $key => $value) {
                header("{$key}: {$value}");
            }

            // Unchanged since the browser's copy: answer with no body.
            if ($etag !== null && trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
                http_response_code(304);
                if (self::$exitOnResponse) {
                    exit;
                }
                return;
            }

            // JSON shrinks 5-10x with gzip, which matters most on mobile data.
            if (strlen($json) > 1024 && self::clientAcceptsGzip() && !ini_get('zlib.output_compression') && function_exists('gzencode') && DevSwitches::enabled(DevSwitches::GZIP)) {
                $compressed = gzencode($json, 5);
                if ($compressed !== false) {
                    header('Content-Encoding: gzip');
                    $json = $compressed;
                }
            }

            // With the length known, the client has the whole answer as soon as it arrives, even
            // while this request still does work after it (see finishForClient). PHP's own output
            // compression would change the length, so it is left to that case.
            if (!ini_get('zlib.output_compression')) {
                header('Content-Length: ' . strlen($json));
            }
        }

        echo $json;
        if (self::$exitOnResponse) {
            exit;
        }
    }

    /**
     * Hand the finished response to the client now, so work done after this (sending email,
     * refreshing the storefront) never makes the shopper or admin wait. Under PHP-FPM or LiteSpeed
     * (most shared hosts) the request is completed; elsewhere the buffered response is flushed,
     * and the client, which knows its length, does not wait for the rest.
     */
    public static function finishForClient(): void
    {
        // The work that follows (emails, storefront refresh) must finish even if the visitor has
        // already gone: otherwise an email accepted by the mail server but not yet marked sent
        // would be sent again after its lease.
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }
        while (ob_get_level() > 0) {
            if (!@ob_end_flush()) {
                break;
            }
        }
        @flush();
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    public static bool $exitOnResponse = true;

    /** Cache-Control for the current response when it is public catalogue data (else no-store). */
    private static ?string $publicCache = null;

    /**
     * Mark the response as public data browsers may reuse for $maxAge seconds (and serve while
     * refreshing for $staleSeconds more). Never use for anything tied to a customer or admin.
     */
    public static function cachePublic(int $maxAge, int $staleSeconds = 0): void
    {
        self::$publicCache = sprintf('public, max-age=%d%s', $maxAge, $staleSeconds > 0 ? ", stale-while-revalidate={$staleSeconds}" : '');
    }

    private static function clientAcceptsGzip(): bool
    {
        return (bool) preg_match('/\bgzip\b/i', (string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
    }
}
