<?php

declare(strict_types=1);

/**
 * Places one order in a process of its own, so PaymentIntegrityTest can have two checkouts running
 * at the same moment: php place_order.php '<order JSON>' <idempotency key>
 * Prints the order as JSON on the last line, or {"error": "..."}.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/src/autoload.php';

\App\Config\Env::load(BASE_PATH . '/.env');
date_default_timezone_set(\App\Config\Env::get('APP_TIMEZONE', 'Africa/Lagos') ?: 'Africa/Lagos');

try {
    $order = (new \App\Services\CheckoutService())->createOrder((array) json_decode($argv[1], true), null, $argv[2]);
    echo "\n" . json_encode(['order_number' => $order['order_number'], 'replayed' => $order['replayed'] ?? false]) . "\n";
} catch (Throwable $e) {
    echo "\n" . json_encode(['error' => $e->getMessage()]) . "\n";
}
