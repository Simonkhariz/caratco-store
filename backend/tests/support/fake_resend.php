<?php

declare(strict_types=1);

/**
 * Stand-in for Resend's POST /emails while ResendTransportTest runs (`php -S` router). It follows
 * the documented behaviour: Bearer key, JSON body, {"id": …} on success, error bodies with
 * statusCode/name/message, and idempotency keys (a repeat with the same body returns the first
 * answer without sending again; a reused key with another body is refused with 409).
 *
 * FAKE_RESEND_DIR holds: mode (ok | drop | 401 | 422 | 429 | 429quota | 500 | slow), requests.log (one JSON
 * line per request), sent.log (one line per email actually "sent") and keys.json.
 */

$dir = (string) getenv('FAKE_RESEND_DIR');
$mode = trim((string) @file_get_contents("{$dir}/mode")) ?: 'ok';
$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
$key = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');

file_put_contents("{$dir}/requests.log", json_encode([
    'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    'key' => $key,
    'body' => $body,
]) . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');
$answer = static function (int $status, array $json): bool {
    http_response_code($status);
    echo json_encode($json);
    return true;
};
$error = static fn (int $status, string $name, string $message): bool => $answer($status, ['statusCode' => $status, 'name' => $name, 'message' => $message]);

if ($mode === 'slow') {
    sleep(5);
}

$keys = json_decode((string) @file_get_contents("{$dir}/keys.json"), true) ?: [];
if ($key !== '' && isset($keys[$key])) {
    if ($keys[$key]['hash'] !== hash('sha256', $raw)) {
        return $error(409, 'invalid_idempotent_request', 'This idempotency key has already been used on a request that had a different payload.');
    }
    return $answer(200, ['id' => $keys[$key]['id']]); // the original answer; nothing is sent again
}

$send = static function () use ($dir, $body, $key, $raw, &$keys): string {
    $id = sprintf('%08x-fake-%04x', random_int(0, 0xffffffff), random_int(0, 0xffff));
    file_put_contents("{$dir}/sent.log", strtolower((string) ($body['to'][0] ?? '')) . "\n", FILE_APPEND | LOCK_EX);
    if ($key !== '') {
        $keys[$key] = ['hash' => hash('sha256', $raw), 'id' => $id];
        file_put_contents("{$dir}/keys.json", json_encode($keys), LOCK_EX);
    }
    return $id;
};

return match ($mode) {
    'ok', 'slow' => $answer(200, ['id' => $send()]),
    // Sent, but the answer is lost on the way back (the case idempotency keys exist for).
    'drop' => (function () use ($send, $error): bool {
        $send();
        return $error(500, 'application_error', 'An unexpected error occurred.');
    })(),
    '401' => $error(401, 'validation_error', 'API key is invalid'),
    '422' => $error(422, 'missing_required_field', 'Missing `to` field.'),
    '429' => $error(429, 'rate_limit_exceeded', 'Too many requests. You can only make 10 requests per second.'),
    '429quota' => $error(429, 'daily_quota_exceeded', 'You have reached your daily email sending quota.'),
    default => $error(500, 'application_error', 'An unexpected error occurred.'),
};
