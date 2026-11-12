<?php

/**
 * Router for PHP's built-in development server, so it behaves like the Apache/nginx setup:
 *
 *   php -S 127.0.0.1:8000 -t backend/public backend/dev-router.php
 *
 * Existing files under public/ are sent as they are; everything else (API routes, and
 * /uploads/<key> when pictures live in a bucket with STORAGE_DRIVER=s3) goes to index.php.
 * Without it, `php -S` answers a missing *.jpg/*.webp itself with a 404. Not used in production.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/' && !str_contains($path, '..') && is_file(__DIR__ . '/public' . $path)) {
    return false;
}
// With a dot in the address, php -S takes it for a file and sets SCRIPT_NAME to it, which the API's
// router would then strip from the path. On a real web server this is always the entry file.
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/public/index.php';
