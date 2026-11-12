<?php

declare(strict_types=1);

/**
 * Stand-in for the storefront's POST /api/revalidate while the API tests run (tests/run.php
 * starts it with `php -S`). Each request is appended to REVALIDATE_STUB_LOG as one JSON line;
 * writing "fail" to "<log>.mode" makes it answer 503, so tests can check failure handling.
 */

$log = (string) getenv('REVALIDATE_STUB_LOG');
$secret = (string) getenv('REVALIDATE_STUB_SECRET');
$mode = trim((string) @file_get_contents($log . '.mode')) ?: 'ok';
$given = (string) ($_SERVER['HTTP_X_REVALIDATE_SECRET'] ?? '');
$secretOk = $secret !== '' && hash_equals($secret, $given);

file_put_contents($log, json_encode([
    'at' => microtime(true),
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
    'secret_ok' => $secretOk,
]) . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');
if ($mode === 'fail') {
    http_response_code(503);
    echo '{"ok":false}';
} elseif (!$secretOk) {
    http_response_code(401);
    echo '{"ok":false}';
} else {
    echo '{"ok":true}';
}
return true;
