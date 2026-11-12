<?php

declare(strict_types=1);

namespace App\Storage;

use Throwable;

/**
 * Serves GET/HEAD /uploads/<key> from the picture storage when it is not the server's disk (on
 * disk, the web server sends the file itself). The bucket stays private: only valid picture keys
 * are served, with a picture content type, and the same caching as files on disk (admin uploads
 * for a year, others for a day). The storefront's image optimiser and browsers keep copies, so
 * the bucket is read rarely.
 */
final class MediaServer
{
    private const TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    /** Send the picture for $key and end the request. */
    public static function serve(ObjectStorage $storage, string $key, string $method): never
    {
        $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        $type = self::TYPES[$extension] ?? null;
        if ($type === null || !StorageKey::isValid($key)) {
            self::plain(404, 'Not found');
        }
        try {
            $body = $storage->read($key);
        } catch (Throwable $e) {
            error_log('[MediaServer] ' . $e->getMessage());
            self::plain(503, 'Picture storage unavailable');
        }
        if ($body === null) {
            self::plain(404, 'Not found');
        }

        $etag = '"' . md5($body) . '"';
        self::clean();
        header('Content-Type: ' . $type);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . StorageKey::cacheControl($key));
        header('ETag: ' . $etag);
        // Which storage answered (s3 or local): lets checks tell a bucket answer from the web
        // server handing out a copy that is still on the disk.
        header('X-Upload-Source: ' . $storage->name());
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
        http_response_code(200);
        header('Content-Length: ' . strlen($body));
        if ($method !== 'HEAD') {
            echo $body;
        }
        exit;
    }

    private static function plain(int $status, string $message): never
    {
        self::clean();
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $message;
        exit;
    }

    private static function clean(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
