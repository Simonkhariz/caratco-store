<?php

declare(strict_types=1);

/**
 * Phase 2 integration test: orders, payments, stock and fulfilment integrity.
 *
 * Runs against the configured database (DB_DATABASE) and, for HTTP checks, the running API
 * (API_URL, default http://127.0.0.1:8000/api/v1). It creates its own category, product,
 * promotion and temporary admin, and deletes everything it created when it finishes.
 *
 * Usage: php backend/tests/PaymentIntegrityTest.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/src/autoload.php';

use App\Config\Env;
use App\Database\Database;
use App\Services\CheckoutService;
use App\Services\FulfillmentService;
use App\Services\KoraService;
use App\Services\MonnifyService;
use App\Services\PaymentAttempts;
use App\Services\PaymentFinalizer;
use App\Services\PaymentMethods;

Env::load(BASE_PATH . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Africa/Lagos') ?: 'Africa/Lagos');

if (getenv('SHOPCO_ENV_FILE') === false && getenv('ALLOW_DEV_DB') !== '1') {
    fwrite(STDERR, "Run this through php backend/tests/run.php (throw-away database), or set ALLOW_DEV_DB=1 to use the configured database on purpose.
");
    exit(2);
}

$api = rtrim(getenv('API_URL') ?: 'http://127.0.0.1:8000/api/v1', '/');
$db = Database::getInstance();
$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    }
}

function expectThrows(string $name, callable $fn, string $contains = ''): void
{
    try {
        $fn();
        check($name, false, 'no exception');
    } catch (Throwable $e) {
        check($name, $contains === '' || stripos($e->getMessage(), $contains) !== false, $e->getMessage());
    }
}

function http(string $method, string $url, ?string $body = null, array $headers = [], ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADER => true,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($jar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $text = substr($raw, $headerSize);
    return ['status' => $status, 'headers' => substr($raw, 0, $headerSize), 'text' => $text, 'json' => json_decode($text, true)];
}

function orderRow(string $orderNumber): array
{
    return Database::getInstance()->fetchOne("SELECT * FROM `orders` WHERE `order_number` = ?", 's', [$orderNumber]);
}

function stockOf(int $variantId): int
{
    return (int) Database::getInstance()->fetchOne("SELECT `stock_quantity` FROM `product_variants` WHERE `id` = ?", 'i', [$variantId])['stock_quantity'];
}

$stamp = date('His') . bin2hex(random_bytes(2));
$created = ['orders' => [], 'users' => []];
$maxIds = [];
foreach (['admin_audit_logs', 'email_outbox', 'payment_webhook_events', 'inventory_logs'] as $t) {
    $maxIds[$t] = (int) ($db->fetchOne("SELECT COALESCE(MAX(id), 0) AS m FROM `{$t}`")['m']);
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------
$zone = $db->fetchOne("SELECT * FROM `shipping_zones` WHERE `is_active` = 1 AND JSON_SEARCH(LOWER(`states_covered`), 'one', 'lagos') IS NOT NULL LIMIT 1");
if ($zone === null) {
    fwrite(STDERR, "No active shipping zone covering Lagos; cannot run checkout tests.\n");
    exit(1);
}

$categoryId = (int) $db->insert(
    "INSERT INTO `categories` (`name`, `slug`, `description`, `display_order`, `is_active`) VALUES (?, ?, 'Phase 2 test', 99, 1)",
    'ss',
    ["P2 Test {$stamp}", "p2-test-{$stamp}"]
);
$productId = (int) $db->insert(
    "INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `currency`, `sku`)
     VALUES (UUID(), ?, ?, ?, 'Integrity test product', 'physical', 'published', 10000.00, 'NGN', ?)",
    'isss',
    [$categoryId, "P2 Tee {$stamp}", "p2-tee-{$stamp}", "P2-{$stamp}"]
);
$variantA = (int) $db->insert(
    "INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`) VALUES (?, ?, 'Medium', 'Black', '#000000', 10)",
    'is',
    [$productId, "P2-{$stamp}-M-BLK"]
);
$variantB = (int) $db->insert(
    "INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `price_override`, `stock_quantity`) VALUES (?, ?, 'Large', 'White', '#ffffff', 12000.00, 5)",
    'is',
    [$productId, "P2-{$stamp}-L-WHT"]
);
$promoCode = strtoupper("P2UNL{$stamp}");
$db->insert(
    "INSERT INTO `promotions` (`code`, `description`, `discount_percent`, `max_uses`, `used_count`, `is_active`) VALUES (?, 'Unlimited test promo', 10, 0, 0, 1)",
    's',
    [$promoCode]
);

$checkout = new CheckoutService($db);
$fulfillment = new FulfillmentService($db);
$finalizer = new PaymentFinalizer($db);
$koraMock = new KoraService($db, static function (string $method, string $url): array {
    return ['status_code' => 200, 'error' => null, 'body' => json_encode([
        'status' => true,
        'data' => ['checkout_url' => 'https://checkout.korapay.com/test/' . bin2hex(random_bytes(4))],
    ])];
});

$address = ['full_name' => 'Chíoma Test', 'phone' => '08000000000', 'address_line1' => '1 Test Road', 'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria'];

$placeOrder = function (array $items, ?string $key = null, ?string $promo = null) use ($checkout, $address, $zone, $stamp, &$created): array {
    $order = $checkout->createOrder([
        'email' => "p2.buyer.{$stamp}@example.test",
        'items' => $items,
        'shipping_address' => $address,
        'shipping_zone_id' => (int) $zone['id'],
        'coupon_code' => $promo,
    ], null, $key);
    $created['orders'][] = $order['order_number'];
    return $order;
};

try {
    // -----------------------------------------------------------------------
    echo "== Database: nested transactions and after-commit hooks\n";
    $marker = "p2-tx-{$stamp}";
    $afterRan = false;
    $db->transaction(function (Database $tx) use ($marker, &$afterRan): void {
        $tx->execute("INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, 'outer')", 's', [$marker . '-outer']);
        try {
            $tx->transaction(function (Database $inner) use ($marker): void {
                $inner->execute("INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, 'inner')", 's', [$marker . '-inner']);
                throw new RuntimeException('inner failure');
            });
        } catch (RuntimeException) {
        }
        $tx->afterCommit(function () use (&$afterRan): void {
            $afterRan = true;
        });
        check('after-commit hook does not run inside the transaction', $afterRan === false);
    });
    check('outer write committed after inner rollback', $db->fetchOne("SELECT 1 AS x FROM `store_settings` WHERE `key_name` = ?", 's', [$marker . '-outer']) !== null);
    check('inner write rolled back to its savepoint', $db->fetchOne("SELECT 1 AS x FROM `store_settings` WHERE `key_name` = ?", 's', [$marker . '-inner']) === null);
    check('after-commit hook ran after commit', $afterRan === true);
    $rolledBackHook = false;
    try {
        $db->transaction(function (Database $tx) use (&$rolledBackHook): void {
            $tx->afterCommit(function () use (&$rolledBackHook): void {
                $rolledBackHook = true;
            });
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    check('after-commit hook discarded on rollback', $rolledBackHook === false);

    $beforeOrder = [];
    $db->transaction(function (Database $tx) use ($marker, &$beforeOrder): void {
        $tx->beforeCommit(function () use ($tx, $marker, &$beforeOrder): void {
            $beforeOrder[] = $tx->inTransaction() ? 'in-transaction' : 'outside';
            $tx->execute("INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, 'before')", 's', [$marker . '-before']);
        });
        $tx->afterCommit(function () use (&$beforeOrder): void {
            $beforeOrder[] = 'after';
        });
        $beforeOrder[] = 'body';
    });
    check('before-commit hook runs after the body, inside the transaction, before after-commit hooks', $beforeOrder === ['body', 'in-transaction', 'after'], implode(',', $beforeOrder));
    check('before-commit write is committed', $db->fetchOne("SELECT 1 AS x FROM `store_settings` WHERE `key_name` = ?", 's', [$marker . '-before']) !== null);
    try {
        $db->transaction(function (Database $tx) use ($marker): void {
            $tx->execute("INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, 'x')", 's', [$marker . '-failed-body']);
            $tx->beforeCommit(function () use ($tx, $marker): void {
                $tx->execute("INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, 'x')", 's', [$marker . '-failed-hook']);
                throw new RuntimeException('hook failure');
            });
        });
    } catch (RuntimeException) {
    }
    check('a failing before-commit hook rolls back the whole transaction', $db->fetchOne(
        "SELECT 1 AS x FROM `store_settings` WHERE `key_name` IN (?, ?)", 'ss', [$marker . '-failed-body', $marker . '-failed-hook']
    ) === null);
    $db->execute("DELETE FROM `store_settings` WHERE `key_name` LIKE ?", 's', [$marker . '%']);

    // -----------------------------------------------------------------------
    echo "== Checkout: exact variants, idempotency, promos, reservation\n";
    expectThrows('unknown size/colour combination is rejected (no first-variant fallback)', function () use ($placeOrder, $productId): void {
        $placeOrder([['product_id' => $productId, 'size' => 'Large', 'color' => 'Black', 'quantity' => 1]]);
    }, 'not offered');
    expectThrows('quantity above the per-line cap is rejected', function () use ($placeOrder, $productId, $variantA): void {
        $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 21]]);
    }, 'between 1 and');

    $key = 'p2key' . bin2hex(random_bytes(12));
    $o1 = $placeOrder([['product_id' => $productId, 'variant_id' => $variantB, 'quantity' => 2]], $key, $promoCode);
    $o1Again = $placeOrder([['product_id' => $productId, 'variant_id' => $variantB, 'quantity' => 2]], $key, $promoCode);
    check('price_override is charged for the chosen variant', abs($o1['subtotal'] - 24000.0) < 0.01, (string) $o1['subtotal']);
    check('same idempotency key returns the same order', $o1['order_number'] === $o1Again['order_number']);
    check('replayed order issues a working fresh guest token', ($o1Again['replayed'] ?? false) === true && strlen($o1Again['guest_access_token']) === 64);
    check('stock reserved once despite the double submit', stockOf($variantB) === 3, (string) stockOf($variantB));
    $promoRow = $db->fetchOne("SELECT `used_count` FROM `promotions` WHERE `code` = ?", 's', [$promoCode]);
    check('unlimited promo (max_uses = 0) usable and counted once', (int) $promoRow['used_count'] === 1, (string) $promoRow['used_count']);
    $row1 = orderRow($o1['order_number']);
    check('order gets a payment window (expires_at)', !empty($row1['expires_at']) && strtotime($row1['expires_at']) > time());
    check('no payment row is created before a gateway attempt', (int) $db->fetchOne("SELECT COUNT(*) c FROM `payments` WHERE `order_id` = ?", 'i', [(int) $row1['id']])['c'] === 0);

    // -----------------------------------------------------------------------
    echo "== Expiry releases stock and promo exactly once\n";
    $db->execute("UPDATE `orders` SET `expires_at` = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE `id` = ?", 'i', [(int) $row1['id']]);
    $expiredNow = $fulfillment->expireOrder((int) $row1['id']);
    $expiredAgain = $fulfillment->expireOrder((int) $row1['id']);
    check('overdue order expires', $expiredNow === true && orderRow($o1['order_number'])['order_status'] === 'cancelled');
    check('second expiry run is a no-op', $expiredAgain === false);
    check('stock released exactly once', stockOf($variantB) === 5, (string) stockOf($variantB));
    $promoRow = $db->fetchOne("SELECT `used_count` FROM `promotions` WHERE `code` = ?", 's', [$promoCode]);
    check('promo use released on expiry', (int) $promoRow['used_count'] === 0, (string) $promoRow['used_count']);
    expectThrows('expired order cannot start a new payment', function () use ($koraMock, $o1): void {
        $koraMock->initializeCheckout($o1['order_number']);
    }, 'cancelled');

    // The quick sweep (checkout, admin lists) does not ask the gateway, so an overdue order whose
    // Kora/Monnify payment may still be clearing gets a grace period; one with no gateway
    // attempt is released at its deadline.
    $graceOrder = $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 1]]);
    $koraMock->initializeCheckout($graceOrder['order_number']);
    $plainOrder = $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 1]]);
    $db->execute("UPDATE `orders` SET `expires_at` = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE `order_number` IN (?, ?)", 'ss', [$graceOrder['order_number'], $plainOrder['order_number']]);
    $swept = $fulfillment->expireOverdueOrders(500);
    check('quick sweep releases an overdue order with no gateway attempt', in_array($plainOrder['order_number'], $swept, true) && orderRow($plainOrder['order_number'])['order_status'] === 'cancelled');
    check('quick sweep leaves an order with an open Kora attempt during the grace period', !in_array($graceOrder['order_number'], $swept, true) && orderRow($graceOrder['order_number'])['order_status'] === 'pending_payment');
    $db->execute("UPDATE `orders` SET `expires_at` = DATE_SUB(NOW(), INTERVAL ? MINUTE) WHERE `order_number` = ?", 'is', [FulfillmentService::GATEWAY_GRACE_MINUTES + 1, $graceOrder['order_number']]);
    $swept = $fulfillment->expireOverdueOrders(500);
    check('after the grace period the quick sweep releases it', in_array($graceOrder['order_number'], $swept, true) && orderRow($graceOrder['order_number'])['order_status'] === 'cancelled');

    // -----------------------------------------------------------------------
    echo "== Kora: attempts, signed webhooks, idempotency, mismatches\n";
    $o2 = $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 1]]);
    $init1 = $koraMock->initializeCheckout($o2['order_number']);
    $init2 = $koraMock->initializeCheckout($o2['order_number']);
    check('each Kora attempt gets its own reference', $init1['provider_reference'] !== $init2['provider_reference']);
    check('earlier attempt reference is preserved', $db->fetchOne("SELECT 1 AS x FROM `payments` WHERE `provider_reference` = ?", 's', [$init1['provider_reference']]) !== null);

    $secret = trim((string) Env::get('KORA_SECRET_KEY', ''));
    $signedKora = function (string $event, string $reference, string $amountLiteral, string $currency, string $status) use ($secret): array {
        // Hand-built JSON: float formatting and non-ASCII text differ from PHP's json_encode,
        // proving the signature is checked over the raw bytes Kora sent.
        $data = '{"reference":"' . $reference . '","status":"' . $status . '","amount":' . $amountLiteral
            . ',"currency":"' . $currency . '","customer":{"name":"Chíoma Test"},"payment_method":"card"}';
        $body = '{"event":"' . $event . '","data":' . $data . '}';
        return [$body, hash_hmac('sha256', $data, $secret)];
    };

    $amountLiteral = number_format((float) $o2['grand_total'], 2, '.', '');
    [$body, $sig] = $signedKora('charge.success', $init2['provider_reference'], $amountLiteral, 'NGN', 'success');
    if ($secret === '') {
        echo "SKIP  Kora webhook HTTP checks (KORA_SECRET_KEY not set)\n";
    } else {
        $bad = http('POST', "{$api}/webhooks/kora", $body, ['Content-Type: application/json', 'x-korapay-signature: ' . str_repeat('0', 64)]);
        check('Kora webhook with a bad signature -> 401', $bad['status'] === 401, (string) $bad['status']);
        $logged = $db->fetchOne("SELECT 1 AS x FROM `payment_webhook_events` WHERE `provider` = 'kora' AND `signature_valid` = 0 AND `id` > ?", 'i', [$maxIds['payment_webhook_events']]);
        check('rejected webhook is recorded (not rolled back)', $logged !== null);

        // Unsigned requests can't fill the database: a short record, a capped reference, a limit.
        $db->execute("DELETE FROM `rate_limits`");
        $badCount = static fn (): int => (int) $db->fetchOne("SELECT COUNT(*) AS c FROM `payment_webhook_events` WHERE `signature_valid` = 0")['c'];
        $badBefore = $badCount();
        $badRow = str_repeat('R', 5000);
        $badBody = static fn (int $i): string => json_encode(['event' => 'charge.success', 'nonce' => $i, 'filler' => str_repeat('x', 2000), 'data' => ['reference' => $badRow]]);
        $badHeaders = ['Content-Type: application/json', 'x-korapay-signature: ' . str_repeat('0', 64)];
        $statuses = [];
        for ($i = 1; $i <= \App\Services\KoraService::BAD_SIGNATURE_LIMIT; $i++) {
            $statuses[] = http('POST', "{$api}/webhooks/kora", $badBody($i), $badHeaders)['status'];
        }
        check('wrong signatures up to the limit -> 401', array_unique($statuses) === [401], json_encode(array_unique($statuses)));
        check('each leaves one record', $badCount() === $badBefore + \App\Services\KoraService::BAD_SIGNATURE_LIMIT, (string) ($badCount() - $badBefore));
        $badMeta = (string) $db->fetchOne("SELECT `payload_metadata` AS m FROM `payment_webhook_events` WHERE `signature_valid` = 0 ORDER BY `id` DESC LIMIT 1")['m'];
        check('the unsigned reference is capped at 100 characters', strlen((string) (json_decode($badMeta, true)['reference'] ?? '')) === 100, $badMeta);
        check('and the body is not kept', strlen($badMeta) < 300 && !str_contains($badMeta, 'xxxx'), (string) strlen($badMeta));
        $overLimit = http('POST', "{$api}/webhooks/kora", $badBody(0), $badHeaders);
        check('past the limit -> 429', $overLimit['status'] === 429, (string) $overLimit['status']);
        check('and nothing more is stored', $badCount() === $badBefore + \App\Services\KoraService::BAD_SIGNATURE_LIMIT, (string) ($badCount() - $badBefore));
        $monnifyBad = http('POST', "{$api}/webhooks/monnify", '{"eventType":"x"}', ['Content-Type: application/json', 'monnify-signature: ' . str_repeat('0', 128)]);
        if (trim((string) Env::get('MONNIFY_SECRET_KEY', '')) !== '') {
            check('Monnify shares the limit', $monnifyBad['status'] === 429, (string) $monnifyBad['status']);
        }

        // A signature covers the first "data"; a second one added to the body must be ignored.
        $o2b = $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 1]]);
        $o2bRef = $koraMock->initializeCheckout($o2b['order_number'])['provider_reference'];
        [$failedBody, $failedSig] = $signedKora('charge.failed', $o2bRef, number_format((float) $o2b['grand_total'], 2, '.', ''), 'NGN', 'failed');
        $forged = substr($failedBody, 0, -1) . ',"data":{"reference":"' . $o2bRef . '","status":"success","amount":' . (float) $o2b['grand_total'] . ',"currency":"NGN"}}';
        $forgedEvent = str_replace('"charge.failed"', '"charge.success"', $forged);
        check('(json_decode really keeps the last "data")', json_decode($forgedEvent, true)['data']['status'] === 'success');
        $forgedRes = http('POST', "{$api}/webhooks/kora", $forgedEvent, ['Content-Type: application/json', 'x-korapay-signature: ' . $failedSig]);
        check('a forged second "data" does not pay the order', orderRow($o2b['order_number'])['order_status'] === 'pending_payment', $forgedRes['text']);
        check('the signed member is what was processed', (string) $db->fetchOne("SELECT `status` FROM `payments` WHERE `provider_reference` = ?", 's', [$o2bRef])['status'] === 'failed', $forgedRes['text']);
        $storedPayload = (string) $db->fetchOne("SELECT `provider_payload_redacted` AS p FROM `payments` WHERE `provider_reference` = ?", 's', [$o2bRef])['p'];
        check('and is what the payment record keeps', (json_decode($storedPayload, true)['data']['status'] ?? '') === 'failed', $storedPayload);
        $db->execute("DELETE FROM `rate_limits`");

        $ok = http('POST', "{$api}/webhooks/kora", $body, ['Content-Type: application/json', 'x-korapay-signature: ' . $sig]);
        check('signed Kora webhook (raw-byte signature) -> 200', $ok['status'] === 200, $ok['text']);
        check('order marked paid', orderRow($o2['order_number'])['order_status'] === 'paid');
        $dup = http('POST', "{$api}/webhooks/kora", $body, ['Content-Type: application/json', 'x-korapay-signature: ' . $sig]);
        check('duplicate webhook is ignored', ($dup['json']['data']['status'] ?? '') === 'ignored_duplicate', $dup['text']);
    }

    // Same success delivered under a different event key (e.g. the verify path) after the order moved on.
    $fulfillment->updateOrderStatus((int) orderRow($o2['order_number'])['id'], 'processing', 'Packing', null);
    $again = $koraMock->processPaymentEvent('charge.success', [
        'reference' => $init2['provider_reference'], 'status' => 'success', 'amount' => $o2['grand_total'], 'currency' => 'NGN',
    ], 'query:' . $init2['provider_reference'] . ':success', []);
    check('repeat success under another key is a no-op', ($again['outcome'] ?? '') === PaymentFinalizer::OUTCOME_ALREADY_PROCESSED, json_encode($again));
    check('order is NOT moved back to paid', orderRow($o2['order_number'])['order_status'] === 'processing');
    $paidHistory = (int) $db->fetchOne("SELECT COUNT(*) c FROM `order_status_history` WHERE `order_id` = ? AND `new_status` = 'paid'", 'i', [(int) orderRow($o2['order_number'])['id']])['c'];
    check('exactly one paid transition in history', $paidHistory === 1, (string) $paidHistory);

    $dupPay = $koraMock->processPaymentEvent('charge.success', [
        'reference' => $init1['provider_reference'], 'status' => 'success', 'amount' => $o2['grand_total'], 'currency' => 'NGN',
    ], 'test-dup-' . $stamp, []);
    check('second successful attempt is flagged as a duplicate payment', ($dupPay['outcome'] ?? '') === PaymentFinalizer::OUTCOME_DUPLICATE_PAYMENT);
    check('duplicate payment raises the admin attention flag', (int) orderRow($o2['order_number'])['requires_attention'] === 1);

    $o3 = $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 1]]);
    $init3 = $koraMock->initializeCheckout($o3['order_number']);
    $under = $koraMock->processPaymentEvent('charge.success', [
        'reference' => $init3['provider_reference'], 'status' => 'success', 'amount' => 10, 'currency' => 'NGN',
    ], 'test-under-' . $stamp, []);
    check('underpayment is not marked paid', ($under['outcome'] ?? '') === PaymentFinalizer::OUTCOME_UNDERPAID && orderRow($o3['order_number'])['order_status'] === 'pending_payment');
    $wrongCur = $koraMock->processPaymentEvent('charge.success', [
        'reference' => $init3['provider_reference'], 'status' => 'success', 'amount' => $o3['grand_total'], 'currency' => 'USD',
    ], 'test-cur-' . $stamp, []);
    check('currency mismatch is rejected', ($wrongCur['outcome'] ?? '') === PaymentFinalizer::OUTCOME_REJECTED && orderRow($o3['order_number'])['order_status'] === 'pending_payment');
    $noCur = $finalizer->finalizeSuccess($init3['provider_reference'], ['amount' => $o3['grand_total'], 'currency' => '', 'label' => 'Test']);
    check('missing currency is rejected', ($noCur['outcome'] ?? '') === PaymentFinalizer::OUTCOME_REJECTED);

    // The receipt is queued in the payment's own transaction: both happen or neither does.
    $o3b = $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 1]]);
    $init3b = $koraMock->initializeCheckout($o3b['order_number']);
    $receiptRows = static fn (): int => (int) $db->fetchOne(
        "SELECT COUNT(*) c FROM `email_outbox` WHERE `dedup_key` = ?", 's', ['payment_verified:' . $o3b['order_number']]
    )['c'];
    $receiptBeforeCommit = -1;
    try {
        $db->transaction(function (Database $tx) use ($finalizer, $init3b, $o3b, $receiptRows, &$receiptBeforeCommit): void {
            $finalizer->finalizeSuccess($init3b['provider_reference'], ['amount' => $o3b['grand_total'], 'currency' => 'NGN', 'label' => 'Kora']);
            $tx->beforeCommit(function () use ($receiptRows, &$receiptBeforeCommit): void {
                $receiptBeforeCommit = $receiptRows();
                throw new RuntimeException('crash just before commit');
            });
        });
    } catch (RuntimeException) {
    }
    check('receipt is queued inside the payment transaction', $receiptBeforeCommit === 1, (string) $receiptBeforeCommit);
    check('rolled-back payment leaves no receipt in the outbox', $receiptRows() === 0 && orderRow($o3b['order_number'])['order_status'] === 'pending_payment');
    $paid3b = $finalizer->finalizeSuccess($init3b['provider_reference'], ['amount' => $o3b['grand_total'], 'currency' => 'NGN', 'label' => 'Kora']);
    check('confirmed payment commits with exactly one receipt in the outbox', ($paid3b['outcome'] ?? '') === PaymentFinalizer::OUTCOME_PAID && $receiptRows() === 1, (string) $receiptRows());

    // Late payment for an expired order
    $db->execute("UPDATE `orders` SET `expires_at` = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE `order_number` = ?", 's', [$o3['order_number']]);
    $fulfillment->expireOrder((int) orderRow($o3['order_number'])['id']);
    $stockBeforeLate = stockOf($variantA);
    $late = $finalizer->finalizeSuccess($init3['provider_reference'], ['amount' => $o3['grand_total'], 'currency' => 'NGN', 'label' => 'Kora']);
    check('payment after expiry is recorded as a late payment', ($late['outcome'] ?? '') === PaymentFinalizer::OUTCOME_LATE_PAYMENT);
    check('expired order is not revived', orderRow($o3['order_number'])['order_status'] === 'cancelled');
    check('late payment does not take stock again', stockOf($variantA) === $stockBeforeLate);
    check('late payment flags the order for a refund', (int) orderRow($o3['order_number'])['requires_attention'] === 1);

    // -----------------------------------------------------------------------
    echo "== Monnify: mandatory signature, amounts\n";
    $mSecret = trim((string) Env::get('MONNIFY_SECRET_KEY', ''));
    $o4 = $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 1]]);
    $mRef = 'MNFY-' . $o4['order_number'] . '-TEST' . strtoupper(bin2hex(random_bytes(2)));
    $db->insert(
        "INSERT INTO `payments` (`order_id`, `provider`, `provider_reference`, `amount`, `currency`, `status`) VALUES (?, 'monnify', ?, ?, 'NGN', 'pending')",
        'isd',
        [(int) orderRow($o4['order_number'])['id'], $mRef, $o4['grand_total']]
    );
    $mBody = function (string $status, float $paid) use ($mRef): string {
        return json_encode(['eventType' => 'SUCCESSFUL_TRANSACTION', 'eventData' => [
            'transactionReference' => 'MNFY|TEST|' . $mRef . '|' . $status, 'paymentReference' => $mRef,
            'amountPaid' => number_format($paid, 2, '.', ''), 'totalPayable' => $paid, 'paymentStatus' => $status,
            'currency' => 'NGN', 'paymentMethod' => 'ACCOUNT_TRANSFER',
        ]]);
    };
    $unsigned = http('POST', "{$api}/webhooks/monnify", $mBody('PAID', (float) $o4['grand_total']), ['Content-Type: application/json']);
    check('Monnify webhook without a signature -> rejected', in_array($unsigned['status'], [401, 503], true), (string) $unsigned['status']);
    check('unsigned Monnify webhook does not pay the order', orderRow($o4['order_number'])['order_status'] === 'pending_payment');
    if ($mSecret === '') {
        echo "SKIP  signed Monnify checks (MONNIFY_SECRET_KEY not set)\n";
    } else {
        $partial = $mBody('PARTIALLY_PAID', 100.0);
        $pr = http('POST', "{$api}/webhooks/monnify", $partial, ['Content-Type: application/json', 'monnify-signature: ' . hash_hmac('sha512', $partial, $mSecret)]);
        check('Monnify PARTIALLY_PAID is accepted but not marked paid', $pr['status'] === 200 && orderRow($o4['order_number'])['order_status'] === 'pending_payment', $pr['text']);
        $full = $mBody('PAID', (float) $o4['grand_total']);
        $fr = http('POST', "{$api}/webhooks/monnify", $full, ['Content-Type: application/json', 'monnify-signature: ' . hash_hmac('sha512', $full, $mSecret)]);
        check('signed Monnify PAID with the right amount marks the order paid', $fr['status'] === 200 && orderRow($o4['order_number'])['order_status'] === 'paid', $fr['text']);
    }

    // -----------------------------------------------------------------------
    echo "== Fulfilment, refunds and manual payments\n";
    $o5 = $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 1]]);
    $o5Id = (int) orderRow($o5['order_number'])['id'];
    expectThrows('shipment rejected for an unpaid order', fn () => $fulfillment->assignShipment($o5Id, ['carrier' => 'GIG', 'status' => 'in_transit']), 'paid orders');
    expectThrows('status endpoint cannot mark an order paid', fn () => $fulfillment->updateOrderStatus($o5Id, 'paid'), 'Confirm payment');
    expectThrows('manual confirmation needs a real reference', fn () => $finalizer->recordManualPayment($o5Id, 'ab', null), 'reference');
    $manual = $finalizer->recordManualPayment($o5Id, "GTB-{$stamp}", null, 'Transfer seen in bank app');
    check('manual confirmation with a reference marks the order paid', $manual['outcome'] === PaymentFinalizer::OUTCOME_PAID && orderRow($o5['order_number'])['order_status'] === 'paid');

    // WhatsApp: confirming the transfer settles the customer's open WhatsApp attempt (one row),
    // and the Kora checkout they opened but never paid is closed instead of staying "pending".
    $o6 = $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 1]]);
    $o6Id = (int) orderRow($o6['order_number'])['id'];
    $koraMock->initializeCheckout($o6['order_number']);
    (new PaymentAttempts($db))->open(orderRow($o6['order_number']), 'whatsapp', 'WA-' . $o6['order_number'] . '-T1', 60);
    $wa = $finalizer->recordManualPayment($o6Id, "OPAY-{$stamp}", null, 'Seen in bank app');
    $o6Payments = $db->fetchAll("SELECT `provider`, `status` FROM `payments` WHERE `order_id` = ? ORDER BY `id`", 'i', [$o6Id]);
    check('WhatsApp confirmation marks the order paid', $wa['outcome'] === PaymentFinalizer::OUTCOME_PAID && orderRow($o6['order_number'])['order_status'] === 'paid');
    check('WhatsApp confirmation settles the WhatsApp attempt (no extra manual row)', count($o6Payments) === 2
        && $o6Payments[1]['provider'] === 'whatsapp' && $o6Payments[1]['status'] === 'successful', json_encode($o6Payments));
    check('unused Kora attempt is closed once the order is paid', ($o6Payments[0]['provider'] ?? '') === 'kora' && ($o6Payments[0]['status'] ?? '') === 'cancelled', json_encode($o6Payments));
    expectThrows('one bank reference cannot confirm two orders', function () use ($placeOrder, $productId, $variantA, $finalizer, $stamp): void {
        $o7 = $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 1]]);
        $finalizer->recordManualPayment((int) orderRow($o7['order_number'])['id'], "OPAY-{$stamp}", null);
    }, 'already used');

    expectThrows('markDelivered on a cancelled order fails without touching shipments', fn () => $fulfillment->markDelivered((int) orderRow($o1['order_number'])['id']), 'Only paid');
    $shipped = $fulfillment->assignShipment($o5Id, ['carrier' => 'GIG Logistics', 'tracking_number' => "GIG-{$stamp}", 'status' => 'shipped']);
    check("legacy 'shipped' shipment status is accepted and ships the order", $shipped['order_status'] === 'shipped' && ($shipped['shipment']['status'] ?? '') === 'in_transit');
    $delivered = $fulfillment->markDelivered($o5Id, 'Handed to customer');
    check('markDelivered moves shipped -> delivered', $delivered['order_status'] === 'delivered');

    expectThrows('refund larger than the payment is rejected', fn () => $fulfillment->recordRefund($o5Id, 999999, 'Too much', 'completed', false, null), 'exceeds');
    expectThrows('refund for an unpaid order is rejected', function () use ($placeOrder, $productId, $variantA, $fulfillment): void {
        $u = $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 1]]);
        $fulfillment->recordRefund((int) orderRow($u['order_number'])['id'], 100, 'No payment', 'completed', false, null);
    }, 'no successful payment');
    $half = round($o5['grand_total'] / 2, 2);
    $fulfillment->recordRefund($o5Id, $half, 'Partial goodwill refund', 'completed', false, null);
    check('partial refund -> payment_status partially_refunded', orderRow($o5['order_number'])['payment_status'] === 'partially_refunded');
    $pending = $fulfillment->recordRefund($o5Id, round($o5['grand_total'] - $half, 2), 'Rest of the money', 'pending', false, null);
    check('pending refund does not change totals yet', orderRow($o5['order_number'])['payment_status'] === 'partially_refunded');
    $fulfillment->resolveRefund((int) $pending['id'], 'completed', null);
    $finalRow = orderRow($o5['order_number']);
    check('full refund -> order and payment refunded', $finalRow['order_status'] === 'refunded' && $finalRow['payment_status'] === 'refunded');

    // Shipment, cancellation and refund emails are queued in the change's own transaction:
    // a change that rolls back leaves no email, a committed one leaves exactly one.
    $outboxRows = static fn (string $keyPrefix): int => (int) $db->fetchOne(
        "SELECT COUNT(*) c FROM `email_outbox` WHERE `dedup_key` LIKE ?", 's', [$keyPrefix . '%']
    )['c'];
    // Runs $change, then fails at the last moment before COMMIT; returns how many matching emails
    // were already in the outbox at that moment (1 = queued inside the transaction).
    $rolledBack = static function (callable $change, string $keyPrefix) use ($db, $outboxRows): int {
        $seenBeforeCommit = -1;
        try {
            $db->transaction(function (Database $tx) use ($change, $keyPrefix, $outboxRows, &$seenBeforeCommit): void {
                $change();
                $tx->beforeCommit(function () use ($keyPrefix, $outboxRows, &$seenBeforeCommit): void {
                    $seenBeforeCommit = $outboxRows($keyPrefix);
                    throw new RuntimeException('crash just before commit');
                });
            });
        } catch (RuntimeException) {
        }
        return $seenBeforeCommit;
    };

    $o8 = $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 1]]);
    $o8Id = (int) orderRow($o8['order_number'])['id'];
    $finalizer->recordManualPayment($o8Id, "ZEN-{$stamp}", null, 'Transfer seen');
    $ship8 = fn () => $fulfillment->assignShipment($o8Id, ['carrier' => 'GIG Logistics', 'tracking_number' => "GIG8-{$stamp}", 'status' => 'in_transit']);
    check('shipping email is queued inside the shipment transaction', $rolledBack($ship8, 'shipment_tracking:' . $o8['order_number']) === 1);
    check('rolled-back shipment leaves no shipping email', $outboxRows('shipment_tracking:' . $o8['order_number']) === 0 && orderRow($o8['order_number'])['order_status'] === 'paid');
    $ship8();
    check('committed shipment leaves exactly one shipping email', $outboxRows('shipment_tracking:' . $o8['order_number']) === 1 && orderRow($o8['order_number'])['order_status'] === 'shipped');

    $refund8 = fn () => $fulfillment->recordRefund($o8Id, 100, 'Delivery fee goodwill', 'completed', false, null);
    check('refund email is queued inside the refund transaction', $rolledBack($refund8, 'refund_processed:' . $o8['order_number']) === 1);
    check('rolled-back refund leaves no refund email', $outboxRows('refund_processed:' . $o8['order_number']) === 0 && orderRow($o8['order_number'])['payment_status'] === 'successful');
    $refund8();
    check('committed refund leaves exactly one refund email', $outboxRows('refund_processed:' . $o8['order_number']) === 1 && orderRow($o8['order_number'])['payment_status'] === 'partially_refunded');

    $o9 = $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 1]]);
    $o9Id = (int) orderRow($o9['order_number'])['id'];
    $stockHeld = stockOf($variantA);
    $cancel9 = fn () => $fulfillment->updateOrderStatus($o9Id, 'cancelled', 'Customer changed their mind', null);
    check('cancellation email is queued inside the cancellation transaction', $rolledBack($cancel9, 'order_cancelled:' . $o9['order_number']) === 1);
    check('rolled-back cancellation leaves no email and keeps the stock reserved', $outboxRows('order_cancelled:' . $o9['order_number']) === 0
        && orderRow($o9['order_number'])['order_status'] === 'pending_payment' && stockOf($variantA) === $stockHeld);
    $cancel9();
    check('committed cancellation leaves exactly one email and releases the stock', $outboxRows('order_cancelled:' . $o9['order_number']) === 1
        && orderRow($o9['order_number'])['order_status'] === 'cancelled' && stockOf($variantA) === $stockHeld + 1);

    // -----------------------------------------------------------------------
    echo "== Admin HTTP: routes, creates, inventory, variant upsert\n";
    $jar = tempnam(sys_get_temp_dir(), 'p2jar');
    $adminEmail = "p2.admin.{$stamp}@example.test";
    $adminPass = 'P2-Admin-' . bin2hex(random_bytes(6));
    $created['users'][] = $adminEmail;
    $db->insert(
        "INSERT INTO `users` (`uuid`, `email`, `password_hash`, `full_name`, `role`, `status`, `email_verified_at`) VALUES (UUID(), ?, ?, 'P2 Temp Admin', 'admin', 'active', NOW())",
        'ss',
        [$adminEmail, password_hash($adminPass, PASSWORD_BCRYPT)]
    );
    $login = http('POST', "{$api}/auth/login", json_encode(['email' => $adminEmail, 'password' => $adminPass]), ['Content-Type: application/json'], $jar);
    $csrf = $login['json']['data']['csrf_token'] ?? '';
    $h = ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf];
    check('temporary admin can sign in', $login['status'] === 200, (string) $login['status']);

    $export = http('GET', "{$api}/admin/orders/export", null, [], $jar);
    check('GET /admin/orders/export returns CSV (route no longer shadowed)', $export['status'] === 200 && stripos($export['headers'], 'text/csv') !== false, (string) $export['status']);

    $promo = http('POST', "{$api}/admin/promotions", json_encode(['code' => "P2HTTP{$stamp}", 'description' => 'HTTP test', 'discount_percent' => 5, 'max_uses' => 10, 'is_active' => true]), $h, $jar);
    check('POST /admin/promotions returns 201 with an id (no crash)', $promo['status'] === 201 && !empty($promo['json']['data']['id']), $promo['text']);
    $banner = http('POST', "{$api}/admin/storefront/banners", json_encode(['title' => "P2 {$stamp}", 'image_url' => '/images/hero/african_models_hero.webp', 'is_active' => false]), $h, $jar);
    check('POST /admin/storefront/banners returns 201 with an id', $banner['status'] === 201 && !empty($banner['json']['data']['id']), $banner['text']);

    $adj = http('POST', "{$api}/admin/inventory/adjust", json_encode(['variant_id' => $variantA, 'change_amount' => 3, 'reason' => 'Restock (test)']), $h, $jar);
    check('Inventory screen adjust (change_amount) works', $adj['status'] === 200, $adj['text']);
    $logRow = $db->fetchOne("SELECT * FROM `inventory_logs` WHERE `variant_id` = ? AND `id` > ? ORDER BY id DESC LIMIT 1", 'ii', [$variantA, $maxIds['inventory_logs']]);
    check('adjustment writes an inventory log with the admin as actor', $logRow !== null && !empty($logRow['adjusted_by_user_id']));

    $paidOrderItemVariant = (int) $db->fetchOne("SELECT `variant_id` FROM `order_items` WHERE `order_id` = ? LIMIT 1", 'i', [$o5Id])['variant_id'];
    $currentA = stockOf($variantA);
    $currentB = stockOf($variantB);
    $upd = http('PUT', "{$api}/admin/products/{$productId}", json_encode(['variants' => [
        ['id' => $variantA, 'sku' => "P2-{$stamp}-M-BLK", 'size' => 'Medium', 'color_name' => 'Black', 'color_hex' => '#000000', 'stock_quantity' => $currentA + 1, 'original_stock_quantity' => $currentA],
        ['sku' => "P2-{$stamp}-S-RED", 'size' => 'Small', 'color_name' => 'Red', 'color_hex' => '#ff0000', 'stock_quantity' => 4],
    ]]), $h, $jar);
    check('product update with variants succeeds', $upd['status'] === 200, $upd['text']);
    check('existing variant keeps its id', $db->fetchOne("SELECT 1 AS x FROM `product_variants` WHERE `id` = ? AND `is_active` = 1", 'i', [$variantA]) !== null);
    check('order items still point at their variant', (int) $db->fetchOne("SELECT `variant_id` FROM `order_items` WHERE `order_id` = ? LIMIT 1", 'i', [$o5Id])['variant_id'] === $paidOrderItemVariant);
    check('form stock edit applied via compare-and-set', stockOf($variantA) === $currentA + 1);
    $bRow = $db->fetchOne("SELECT `is_active` FROM `product_variants` WHERE `id` = ?", 'i', [$variantB]);
    check('removed variant with order history is archived, not deleted', $bRow !== null && (int) $bRow['is_active'] === 0);
    $stale = http('PUT', "{$api}/admin/products/{$productId}", json_encode(['variants' => [
        ['id' => $variantA, 'sku' => "P2-{$stamp}-M-BLK", 'size' => 'Medium', 'color_name' => 'Black', 'color_hex' => '#000000', 'stock_quantity' => 50, 'original_stock_quantity' => $currentA],
    ]]), $h, $jar);
    check('stale stock edit is refused (422) instead of overwriting reservations', $stale['status'] === 422, (string) $stale['status']);
    check('stale edit left stock unchanged', stockOf($variantA) === $currentA + 1);
    unset($currentB);

    $toPaid = http('PATCH', "{$api}/admin/orders/{$o5['order_number']}/status", json_encode(['status' => 'paid']), $h, $jar);
    check("PATCH status 'paid' is refused", $toPaid['status'] === 422, (string) $toPaid['status']);

    // -----------------------------------------------------------------------
    echo "== Product editor: clearing fields, SKU, photos, clear duplicate messages
";
    $db->execute("UPDATE `products` SET `original_price` = 9999, `dress_style` = 'casual' WHERE `id` = ?", 'i', [$productId]);
    $put = static fn (array $body): array => http('PUT', "{$api}/admin/products/{$productId}", json_encode($body), $h, $jar);
    $prodRow = static fn (): array => $db->fetchOne("SELECT `sku`, `original_price`, `category_id`, `dress_style` FROM `products` WHERE `id` = ?", 'i', [$productId]);
    $cleared = $put(['original_price' => null, 'category_id' => null, 'dress_style' => null]);
    $row = $prodRow();
    check('clearing "Was price", category and style saves them as empty', $cleared['status'] === 200 && $row['original_price'] === null && $row['category_id'] === null && $row['dress_style'] === '', json_encode($row) . ' ' . $cleared['status']);
    $db->execute("UPDATE `products` SET `category_id` = ? WHERE `id` = ?", 'ii', [$categoryId, $productId]);
    $newSku = "P2E-{$stamp}";
    $put(['sku' => $newSku]);
    check('editing the SKU is saved', $prodRow()['sku'] === $newSku, (string) $prodRow()['sku']);
    $newSku2 = "P2F-{$stamp}";
    $activeVariants = $db->fetchAll("SELECT `id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity` FROM `product_variants` WHERE `product_id` = ? AND `is_active` = 1", 'i', [$productId]);
    $variantsBody = array_map(static fn (array $v): array => ['id' => (int) $v['id'], 'sku' => $v['sku'], 'size' => $v['size'], 'color_name' => $v['color_name'], 'color_hex' => $v['color_hex'], 'stock_quantity' => (int) $v['stock_quantity'], 'original_stock_quantity' => (int) $v['stock_quantity']], $activeVariants);
    $variantsBody[] = ['sku' => '', 'size' => 'XL', 'color_name' => 'Teal', 'color_hex' => '#008080', 'stock_quantity' => 3];
    $withVariant = $put(['sku' => $newSku2, 'variants' => $variantsBody]);
    $tealSku = (string) ($db->fetchOne("SELECT `sku` FROM `product_variants` WHERE `product_id` = ? AND `size` = 'XL' AND `color_name` = 'Teal'", 'i', [$productId])['sku'] ?? '');
    check('a new variant added in the same save as a SKU change is named after the new SKU', $withVariant['status'] === 200 && str_starts_with($tealSku, $newSku2 . '-'), $tealSku . ' ' . $withVariant['status']);
    $put(['images' => [['image_url' => '/uploads/p2-only.jpg', 'alt_text' => 'only', 'is_primary' => 1]]]);
    $put(['images' => []]);
    check('removing every photo is saved', (int) $db->fetchOne("SELECT COUNT(*) AS c FROM `product_images` WHERE `product_id` = ?", 'i', [$productId])['c'] === 0);
    $otherSlug = "p2-other-{$stamp}";
    $otherId = (int) $db->insert("INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `sku`, `base_price`, `status`) VALUES (UUID(), ?, 'P2 other', ?, ?, 1000, 'draft')", 'iss', [$categoryId, $otherSlug, "P2O-{$stamp}"]);
    $dupSlug = $put(['slug' => $otherSlug]);
    check('a web address another product uses gets its own message', $dupSlug['status'] === 422 && str_contains($dupSlug['text'], 'DUPLICATE_SLUG'), $dupSlug['text']);
    $dupSku = $put(['sku' => "P2O-{$stamp}"]);
    check('an SKU another product uses gets its own message', $dupSku['status'] === 422 && str_contains($dupSku['text'], 'DUPLICATE_SKU'), $dupSku['text']);
    $db->execute("DELETE FROM `products` WHERE `id` = ?", 'i', [$otherId]);

    // -----------------------------------------------------------------------
    echo "== Review fixes: stats after the commit, one expiry sweep at a time, capped holds, no repeated alerts\n";
    $db->execute("UPDATE `product_variants` SET `stock_quantity` = 100 WHERE `id` = ?", 'i', [$variantA]);
    $line = [['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 2]];
    $soldOf = static fn (): int => (int) $db->fetchOne("SELECT `units_sold` FROM `products` WHERE `id` = ?", 'i', [$productId])['units_sold'];

    $r1 = $placeOrder($line);
    $r1Id = (int) orderRow($r1['order_number'])['id'];
    $soldBefore = $soldOf();
    $soldInside = null;
    $db->transaction(function (Database $tx) use ($r1Id, $soldOf, &$soldInside): void {
        $tx->execute("UPDATE `orders` SET `order_status` = 'paid', `payment_status` = 'successful' WHERE `id` = ?", 'i', [$r1Id]);
        App\Services\ProductSales::refreshForOrder($tx, $r1Id);
        $soldInside = $soldOf();
    });
    check('units-sold recount waits until after the commit (no shared locks inside a payment)', $soldInside === $soldBefore && $soldOf() === $soldBefore + 2, "inside {$soldInside}, after {$soldOf()}, before {$soldBefore}");

    $r2 = $placeOrder($line);
    $db->execute("UPDATE `orders` SET `expires_at` = NOW() - INTERVAL 2 HOUR WHERE `order_number` = ?", 's', [$r2['order_number']]);
    $other = new mysqli(Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_USERNAME', 'root'), Env::get('DB_PASSWORD', ''), Env::get('DB_DATABASE'), (int) Env::get('DB_PORT', '3306'));
    $other->query("SELECT GET_LOCK(CONCAT(LEFT(DATABASE(), 40), ':quick_expiry_sweep'), 5)");
    $busyStart = microtime(true);
    $whileBusy = (new FulfillmentService($db))->expireOverdueOrders(50);
    check('while another request sweeps, the next one skips the sweep instead of waiting', $whileBusy === [] && orderRow($r2['order_number'])['order_status'] === 'pending_payment' && microtime(true) - $busyStart < 1);
    $other->query("SELECT RELEASE_LOCK(CONCAT(LEFT(DATABASE(), 40), ':quick_expiry_sweep'))");
    $other->close();
    $free = (new FulfillmentService($db))->expireOverdueOrders(50);
    check('once free, the sweep expires the overdue order', in_array($r2['order_number'], $free, true) && orderRow($r2['order_number'])['order_status'] === 'cancelled');

    $r3 = $placeOrder($line);
    $db->execute("UPDATE `orders` SET `created_at` = NOW() - INTERVAL 30 DAY, `expires_at` = NOW() + INTERVAL 10 MINUTE WHERE `order_number` = ?", 's', [$r3['order_number']]);
    $heldUntil = orderRow($r3['order_number'])['expires_at'];
    (new PaymentAttempts($db))->open(orderRow($r3['order_number']), 'whatsapp', 'WA-CAP-' . $stamp, PaymentAttempts::manualWindowMinutes());
    check("starting payment again can't hold an old order's stock past the overall limit", orderRow($r3['order_number'])['expires_at'] === $heldUntil, orderRow($r3['order_number'])['expires_at'] . " vs {$heldUntil}");
    $r4 = $placeOrder($line);
    (new PaymentAttempts($db))->open(orderRow($r4['order_number']), 'whatsapp', 'WA-NEW-' . $stamp, PaymentAttempts::manualWindowMinutes());
    $r4Row = orderRow($r4['order_number']);
    $r4Hold = (strtotime((string) $r4Row['expires_at']) - time()) / 60;
    check('a new order still gets the full manual-transfer window', $r4Hold > PaymentAttempts::manualWindowMinutes() - 5 && strtotime((string) $r4Row['expires_at']) <= strtotime((string) $r4Row['created_at']) + PaymentAttempts::maxHoldMinutes() * 60, round($r4Hold) . ' min');

    // Refunds: "return to stock" ticked only on the refund that completes first (not the full one).
    $r5 = $placeOrder($line);
    $r5Id = (int) orderRow($r5['order_number'])['id'];
    (new PaymentFinalizer($db))->recordManualPayment($r5Id, 'RF-' . $stamp, null, 'refund test');
    $paidTotal = (float) orderRow($r5['order_number'])['grand_total'];
    $stockAfterSale = stockOf($variantA);
    $fs = new FulfillmentService($db);
    $first = $fs->recordRefund($r5Id, round($paidTotal / 2, 2), 'first half', 'pending', false, null);
    $fs->recordRefund($r5Id, round($paidTotal - round($paidTotal / 2, 2), 2), 'second half', 'completed', true, null);
    check('a partial refund does not restock yet', stockOf($variantA) === $stockAfterSale);
    $firstId = (int) ($first['id'] ?? $db->fetchOne("SELECT `id` FROM `refunds` WHERE `order_id` = ? AND `reason` = 'first half'", 'i', [$r5Id])['id']);
    $fs->resolveRefund($firstId, 'completed', null);
    check('when the order becomes fully refunded the items go back to stock, once', stockOf($variantA) === $stockAfterSale + 2, stockOf($variantA) . " vs {$stockAfterSale}+2");
    check('and exactly one refund is marked as the one that restocked', (int) $db->fetchOne("SELECT COUNT(*) AS c FROM `refunds` WHERE `order_id` = ? AND `restocked` = 1", 'i', [$r5Id])['c'] === 1 && (int) $db->fetchOne("SELECT COUNT(*) AS c FROM `refunds` WHERE `order_id` = ? AND `restocked` = 2", 'i', [$r5Id])['c'] === 0);

    // The provider's own words stay in the log and the payment record.
    $koraRejecting = new KoraService($db, static fn (): array => ['status_code' => 400, 'error' => null, 'body' => json_encode(['status' => false, 'message' => 'Merchant account mx_12345 is not activated'])]);
    $envVarsK = new ReflectionProperty(Env::class, 'vars');
    $envVarsK->setAccessible(true);
    $rejected = $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 1]]);
    try {
        $koraRejecting->initializeCheckout($rejected['order_number']);
        check('a provider rejection fails', trim((string) Env::get('KORA_SECRET_KEY', '')) === '');
    } catch (RuntimeException $e) {
        check('a provider rejection does not repeat the provider\'s message', !str_contains($e->getMessage(), 'mx_12345'), $e->getMessage());
        check('the payment record keeps it for the store', str_contains((string) $db->fetchOne("SELECT `provider_payload_redacted` AS p FROM `payments` p2 JOIN `orders` o ON o.`id` = p2.`order_id` WHERE o.`order_number` = ? ORDER BY p2.`id` DESC LIMIT 1", 's', [$rejected['order_number']])['p'], 'mx_12345'));
    }

    // Monnify without a contract code: not offered, and its reason is not for the customer.
    $savedVars = $envVarsK->getValue();
    $envVarsK->setValue(null, ['MONNIFY_API_KEY' => 'made-up', 'MONNIFY_SECRET_KEY' => 'made-up', 'MONNIFY_CONTRACT_CODE' => ''] + $savedVars);
    $monnifyEnabled = PaymentMethods::isEnabled('monnify', $db);
    $monnifyMessage = '';
    try {
        (new MonnifyService($db, static fn (): array => ['status_code' => 500, 'error' => null, 'body' => '{}']))->initializeCheckout($rejected['order_number']);
    } catch (RuntimeException $e) {
        $monnifyMessage = $e->getMessage();
    }
    $envVarsK->setValue(null, $savedVars);
    check('Monnify without a contract code is not enabled', $monnifyEnabled === false);
    check('and its error does not name the setting', $monnifyMessage !== '' && !str_contains($monnifyMessage, 'MONNIFY_CONTRACT_CODE'), $monnifyMessage);

    // Overpaid N+2,000: the money that came in can all go back.
    $overpay = static function () use ($placeOrder, $koraMock, $finalizer, $productId, $variantA): array {
        $order = $placeOrder([['product_id' => $productId, 'variant_id' => $variantA, 'quantity' => 1]]);
        $reference = $koraMock->initializeCheckout($order['order_number'])['provider_reference'];
        $total = (float) $order['grand_total'];
        $finalizer->finalizeSuccess($reference, ['amount' => $total + 2000, 'currency' => 'NGN', 'label' => 'Kora']);
        return [(int) orderRow($order['order_number'])['id'], $total, $order['order_number'], $reference];
    };
    [$ov1Id, $ov1Total, $ov1Num, $ov1Ref] = $overpay();
    check('an overpaid order is paid and flagged', orderRow($ov1Num)['order_status'] === 'paid' && (int) orderRow($ov1Num)['requires_attention'] === 1);
    check('all the money shows as refundable', abs($fs->moneyOnOrder($db, $ov1Id)[0]['left'] - ($ov1Total + 2000)) < 0.01);
    $fs->recordRefund($ov1Id, 2000, 'Overpaid: the difference', 'completed', false, null);
    check('the difference can be refunded', abs($fs->moneyOnOrder($db, $ov1Id)[0]['left'] - $ov1Total) < 0.01);
    check('and the order is still paid and being fulfilled', orderRow($ov1Num)['order_status'] === 'paid' && orderRow($ov1Num)['payment_status'] === 'partially_refunded');
    $fs->recordRefund($ov1Id, $ov1Total, 'Changed their mind', 'completed', false, null);
    check('refunding the rest refunds the order', orderRow($ov1Num)['order_status'] === 'refunded' && orderRow($ov1Num)['payment_status'] === 'refunded');

    [$ov2Id, $ov2Total, $ov2Num, $ov2Ref] = $overpay();
    $fs->updateOrderStatus($ov2Id, 'cancelled', 'Out of stock', null);
    expectThrows('more than came in is still refused', fn () => $fs->recordRefund($ov2Id, $ov2Total + 2000.5, 'Too much', 'completed', false, null), 'exceeds');
    $fs->recordRefund($ov2Id, $ov2Total + 2000, 'Cancelled: all of it', 'completed', false, null);
    check('a cancelled overpaid order: the whole amount goes back', $db->fetchOne("SELECT `status` FROM `payments` WHERE `provider_reference` = ?", 's', [$ov2Ref])['status'] === 'refunded');
    check('it stays cancelled', orderRow($ov2Num)['order_status'] === 'cancelled' && orderRow($ov2Num)['payment_status'] === 'refunded');
    check('with nothing left to refund', $fs->moneyOnOrder($db, $ov2Id)[0]['left'] < 0.01);

    // Two submissions at once for the last unit, same key: both get the one order.
    $lastUnitSku = "P2-LAST-{$stamp}";
    $lastVariant = (int) $db->insert(
        "INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`) VALUES (?, ?, 'Z', 'Last', '#000000', 1)",
        'is',
        [$productId, $lastUnitSku]
    );
    $idemKey = 'lastunit' . bin2hex(random_bytes(10));
    $lock = new mysqli((string) Env::get('DB_HOST', '127.0.0.1'), (string) Env::get('DB_USERNAME', 'root'), (string) Env::get('DB_PASSWORD', ''), (string) Env::get('DB_DATABASE', ''), Env::int('DB_PORT', 3306));
    $lock->begin_transaction();
    $lock->query("SELECT `id` FROM `product_variants` WHERE `id` = {$lastVariant} FOR UPDATE");
    $childInput = json_encode([
        'email' => "p2.last.{$stamp}@example.test",
        'items' => [['product_id' => $productId, 'variant_id' => $lastVariant, 'quantity' => 1]],
        'shipping_address' => $address,
        'shipping_zone_id' => (int) $zone['id'],
    ]);
    $children = [];
    foreach ([1, 2] as $_) {
        $children[] = proc_open([PHP_BINARY, __DIR__ . '/support/place_order.php', $childInput, $idemKey], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $childPipes, BASE_PATH, getenv());
        $childOut[] = $childPipes;
    }
    sleep(2); // both have read 1 in stock and now wait for the row this test holds
    $lock->commit();
    $lock->close();
    $answers = [];
    foreach ($children as $i => $child) {
        $text = stream_get_contents($childOut[$i][1]);
        stream_get_contents($childOut[$i][2]);
        proc_close($child);
        $answers[] = json_decode((string) strrchr("\n" . trim((string) $text), "\n"), true) ?? ['raw' => $text];
    }
    $lastOrder = (string) ($answers[0]['order_number'] ?? '');
    if ($lastOrder !== '') {
        $created['orders'][] = $lastOrder;
    }
    check('the loser gets the winner\'s order, not a false "sold out"', $lastOrder !== '' && $lastOrder === ($answers[1]['order_number'] ?? null), json_encode($answers));
    check('one order for the key', (int) $db->fetchOne("SELECT COUNT(*) AS c FROM `orders` WHERE `idempotency_key` = ?", 's', [$idemKey])['c'] === 1);
    check('one unit taken', stockOf($lastVariant) === 0, (string) stockOf($lastVariant));
    $another = static fn () => $checkout->createOrder(['email' => "p2.other.{$stamp}@example.test", 'items' => [['product_id' => $productId, 'variant_id' => $lastVariant, 'quantity' => 1]], 'shipping_address' => $address, 'shipping_zone_id' => (int) $zone['id']], null, 'lastunit' . bin2hex(random_bytes(10)));
    expectThrows('a different customer still gets the real "sold out"', $another, 'Insufficient stock');

    // "Pay now" never points at a gateway without keys (made-up keys here; the real ones are put back).
    $envVars = new ReflectionProperty(Env::class, 'vars');
    $envVars->setAccessible(true);
    $savedEnv = $envVars->getValue();
    $envVars->setValue(null, ['KORA_SECRET_KEY' => '', 'MONNIFY_API_KEY' => '', 'MONNIFY_CONTRACT_CODE' => ''] + $savedEnv);
    $noKeys = PaymentMethods::payNowGateway($db);
    $envVars->setValue(null, ['KORA_SECRET_KEY' => 'sk_test_made_up', 'MONNIFY_API_KEY' => '', 'MONNIFY_CONTRACT_CODE' => ''] + $savedEnv);
    $koraOnly = PaymentMethods::payNowGateway($db);
    $envVars->setValue(null, $savedEnv);
    check('with no gateway keys, "Pay now" is not offered (WhatsApp only)', $noKeys === null, var_export($noKeys, true));
    check('with only Kora set up, "Pay now" uses Kora even though Monnify is the default', $koraOnly === 'kora', var_export($koraOnly, true));

    $flag = new ReflectionMethod(PaymentFinalizer::class, 'flagAttention');
    $flag->setAccessible(true);
    $finalizer = new PaymentFinalizer($db);
    $r4Id = (int) $r4Row['id'];
    foreach ([1, 2, 3] as $_) {
        $flag->invoke($finalizer, $db, $r4Id, "Underpaid: Monnify payment REF-{$stamp} received NGN 10.00 of 20.00.");
    }
    $flag->invoke($finalizer, $db, $r4Id, 'Payment received after the order was cancelled.');
    $reason = (string) orderRow($r4['order_number'])['attention_reason'];
    check('a repeated alert is kept once, and a later different alert still shows', substr_count($reason, "REF-{$stamp}") === 1 && str_contains($reason, 'after the order was cancelled'), $reason);

    // -----------------------------------------------------------------------
    echo "== Refunds: a duplicate payment, the newest money first\n";
    $d1 = $placeOrder($line);
    $d1Row = orderRow($d1['order_number']);
    $d1Id = (int) $d1Row['id'];
    $d1Total = (float) $d1Row['grand_total'];
    $d1Good = $koraMock->initializeCheckout($d1['order_number'])['provider_reference'];
    $d1Dup = $koraMock->initializeCheckout($d1['order_number'])['provider_reference'];
    $finalizer->finalizeSuccess($d1Good, ['amount' => $d1Total, 'currency' => 'NGN', 'label' => 'Kora']);
    $dupOutcome = $finalizer->finalizeSuccess($d1Dup, ['amount' => $d1Total, 'currency' => 'NGN', 'label' => 'Kora']);
    check('the second payment is a duplicate', ($dupOutcome['outcome'] ?? '') === PaymentFinalizer::OUTCOME_DUPLICATE_PAYMENT);
    $payStatus = static fn (string $ref): string => (string) $db->fetchOne("SELECT `status` FROM `payments` WHERE `provider_reference` = ?", 's', [$ref])['status'];
    $d1Eligible = http('GET', "{$api}/admin/refunds/eligibility?order_number=" . urlencode($d1['order_number']), null, [], $jar)['json']['data'] ?? [];
    check('"Check order" counts both payments and offers the duplicate first', abs((float) ($d1Eligible['paid_amount'] ?? 0) - 2 * $d1Total) < 0.01
        && abs((float) ($d1Eligible['refundable_amount'] ?? 0) - $d1Total) < 0.01 && abs((float) ($d1Eligible['refundable_total'] ?? 0) - 2 * $d1Total) < 0.01
        && ($d1Eligible['payment_reference'] ?? '') === $d1Dup, json_encode($d1Eligible));
    expectThrows('a refund larger than the newest payment is refused, saying why', fn () => $fulfillment->recordRefund($d1Id, $d1Total * 2, 'Too much', 'completed', false, null), 'more than one payment');
    $d1Stock = stockOf($variantA);
    $d1Refund = $fulfillment->recordRefund($d1Id, $d1Total, 'Paid twice', 'completed', true, null);
    $d1After = orderRow($d1['order_number']);
    check('the refund goes against the duplicate, not the payment that paid the order', $payStatus($d1Dup) === 'refunded' && $payStatus($d1Good) === 'successful');
    check('the good order stays paid', $d1After['order_status'] === 'paid' && $d1After['payment_status'] === 'successful', json_encode([$d1After['order_status'], $d1After['payment_status']]));
    check('the flag stays until no payment is left (or the admin resolves it)', (int) $d1After['requires_attention'] === 1);
    $d1GoodId = (string) $db->fetchOne("SELECT `id` FROM `payments` WHERE `provider_reference` = ?", 's', [$d1Good])['id'];
    check('the customer sees the payment that stands, not the refunded duplicate', str_starts_with((new App\Services\LiveStatus($db))->snapshot($d1Id)['id'], $d1GoodId . '-'));
    check('stock is not released while a good payment remains', stockOf($variantA) === $d1Stock, stockOf($variantA) . " vs {$d1Stock}");
    check('the wish to restock is kept for later', (int) $d1Refund['restocked'] === 2);
    $d1Comments = static fn (): string => implode(' | ', array_column($db->fetchAll("SELECT `comment` FROM `order_status_history` WHERE `order_id` = ?", 'i', [$d1Id]), 'comment'));
    check('and the history does not say the items were restocked', !str_contains($d1Comments(), 'restocked'), $d1Comments());

    $fulfillment->recordRefund($d1Id, $d1Total, 'Changed their mind', 'completed', false, null);
    $d1Final = orderRow($d1['order_number']);
    check('refunding the last payment refunds the order and clears the flag', $d1Final['order_status'] === 'refunded' && $d1Final['payment_status'] === 'refunded' && (int) $d1Final['requires_attention'] === 0, json_encode([$d1Final['order_status'], $d1Final['payment_status'], $d1Final['requires_attention']]));
    check('now the items go back, once', stockOf($variantA) === $d1Stock + 2, stockOf($variantA) . " vs {$d1Stock}+2");
    check('and the history says so', str_contains($d1Comments(), 'and items restocked'), $d1Comments());
    expectThrows('nothing more can be refunded', fn () => $fulfillment->recordRefund($d1Id, 1, 'Again', 'completed', false, null), 'no successful payment');

    // A pending refund uses up the newest payment, so the next refund moves on to the older one.
    $d2 = $placeOrder($line);
    $d2Row = orderRow($d2['order_number']);
    $d2Id = (int) $d2Row['id'];
    $d2Total = (float) $d2Row['grand_total'];
    $d2Good = $koraMock->initializeCheckout($d2['order_number'])['provider_reference'];
    $d2Dup = $koraMock->initializeCheckout($d2['order_number'])['provider_reference'];
    $finalizer->finalizeSuccess($d2Good, ['amount' => $d2Total, 'currency' => 'NGN', 'label' => 'Kora']);
    $finalizer->finalizeSuccess($d2Dup, ['amount' => $d2Total, 'currency' => 'NGN', 'label' => 'Kora']);
    $refundPayment = static fn (array $r): string => (string) $db->fetchOne("SELECT p.`provider_reference` AS r FROM `payments` p JOIN `refunds` f ON f.`payment_id` = p.`id` WHERE f.`id` = ?", 'i', [(int) $r['id']])['r'];
    check('first refund: the duplicate', $refundPayment($fulfillment->recordRefund($d2Id, $d2Total, 'Paid twice', 'pending', false, null)) === $d2Dup);
    check('second refund: the older payment', $refundPayment($fulfillment->recordRefund($d2Id, 1, 'Goodwill', 'completed', false, null)) === $d2Good);
    check('a partial refund of the paying payment reads as partially refunded', orderRow($d2['order_number'])['payment_status'] === 'partially_refunded');

    // Status history must not claim a restock that only happens later.
    $p1 = $placeOrder($line);
    $p1Id = (int) orderRow($p1['order_number'])['id'];
    (new PaymentFinalizer($db))->recordManualPayment($p1Id, 'PARTIAL-' . $stamp, null, 'partial refund test');
    $p1Refund = $fulfillment->recordRefund($p1Id, 1, 'Part of it', 'completed', true, null);
    $p1Note = (string) $db->fetchOne("SELECT `comment` FROM `order_status_history` WHERE `order_id` = ? ORDER BY `id` DESC LIMIT 1", 'i', [$p1Id])['comment'];
    check('a partial refund with restock ticked does not say "items restocked"', (int) $p1Refund['restocked'] === 2 && !str_contains($p1Note, 'restocked'), $p1Note);

    // Cancelling a partly refunded order: the rest of the money is still owed.
    $fulfillment->updateOrderStatus($p1Id, 'cancelled', 'Cannot fulfil', null);
    $p1Row = orderRow($p1['order_number']);
    check('cancelling a partly refunded order flags the money still owed', (int) $p1Row['requires_attention'] === 1 && str_contains((string) $p1Row['attention_reason'], 'Cancelled after payment'), json_encode($p1Row['attention_reason']));

    // -----------------------------------------------------------------------
    echo "== Underpaid money can be refunded, up to what came in\n";
    $u1 = $placeOrder($line);
    $u1Id = (int) orderRow($u1['order_number'])['id'];
    $u1Ref = $koraMock->initializeCheckout($u1['order_number'])['provider_reference'];
    $u1Stock = stockOf($variantA);
    $u1Under = $finalizer->finalizeSuccess($u1Ref, ['amount' => 500, 'currency' => 'NGN', 'label' => 'Kora']);
    check('the payment is underpaid and the order flagged', ($u1Under['outcome'] ?? '') === PaymentFinalizer::OUTCOME_UNDERPAID && (int) orderRow($u1['order_number'])['requires_attention'] === 1);
    expectThrows('a refund above what actually came in is refused', fn () => $fulfillment->recordRefund($u1Id, 600, 'Too much', 'completed', false, null), 'maximum now 500');
    $fulfillment->recordRefund($u1Id, 200, 'First part', 'completed', false, null);
    check('part of it refunded: the order is still unpaid and flagged', orderRow($u1['order_number'])['order_status'] === 'pending_payment'
        && orderRow($u1['order_number'])['payment_status'] === 'pending' && (int) orderRow($u1['order_number'])['requires_attention'] === 1);
    $fulfillment->recordRefund($u1Id, 300, 'The rest', 'completed', true, null);
    $u1Row = orderRow($u1['order_number']);
    check('all of it refunded: the flag clears, the order still waits for payment', $u1Row['order_status'] === 'pending_payment' && $u1Row['payment_status'] === 'pending' && (int) $u1Row['requires_attention'] === 0, json_encode([$u1Row['order_status'], $u1Row['payment_status']]));
    check('its stock stays held until the order expires', stockOf($variantA) === $u1Stock);
    check('the attempt closes as failed, never as refunded: that would read as paid', $payStatus($u1Ref) === 'failed');
    $u1Live = (new App\Services\LiveStatus($db))->snapshot($u1Id);
    check('the customer is not told it was paid', $u1Live['status'] === 'failed' && $u1Live['final'] === false, json_encode($u1Live));
    expectThrows('with all of it returned, nothing more can be refunded', fn () => $fulfillment->recordRefund($u1Id, 1, 'Again', 'completed', false, null), 'no successful payment');
    $db->execute("UPDATE `orders` SET `expires_at` = NOW() - INTERVAL 1 MINUTE WHERE `id` = ?", 'i', [$u1Id]);
    $fulfillment->expireOrder($u1Id);
    check('when it expires the stock goes back and the refund record stays', stockOf($variantA) === $u1Stock + 2 && (int) $db->fetchOne("SELECT COUNT(*) AS c FROM `refunds` WHERE `order_id` = ?", 'i', [$u1Id])['c'] === 2);

    // Underpaid, then paid in full another way: the stray money is refunded before the good payment.
    $u2 = $placeOrder($line);
    $u2Row = orderRow($u2['order_number']);
    $u2Id = (int) $u2Row['id'];
    $u2Stray = $koraMock->initializeCheckout($u2['order_number'])['provider_reference'];
    $finalizer->finalizeSuccess($u2Stray, ['amount' => 700, 'currency' => 'NGN', 'label' => 'Kora']);
    $u2Good = $koraMock->initializeCheckout($u2['order_number'])['provider_reference'];
    $finalizer->finalizeSuccess($u2Good, ['amount' => $u2Row['grand_total'], 'currency' => 'NGN', 'label' => 'Kora']);
    check('the order is paid, the stray attempt closed but its money remembered', orderRow($u2['order_number'])['order_status'] === 'paid' && $payStatus($u2Stray) === 'cancelled');
    $fulfillment->recordRefund($u2Id, 700, 'Stray money', 'completed', false, null);
    $u2After = orderRow($u2['order_number']);
    check('the stray money goes first; the order stays paid', $payStatus($u2Stray) === 'cancelled' && $payStatus($u2Good) === 'successful' && $u2After['order_status'] === 'paid' && $u2After['payment_status'] === 'successful', json_encode([$payStatus($u2Stray), $payStatus($u2Good), $u2After['order_status'], $u2After['payment_status']]));
    check('and is settled: the next refund goes to the good payment', array_values(array_filter($fulfillment->moneyOnOrder($db, $u2Id), static fn (array $p): bool => !$p['settled']))[0]['provider_reference'] === $u2Good);

    // -----------------------------------------------------------------------
    echo "== \"I've sent the transfer\", then the order expires\n";
    $c1 = $placeOrder($line);
    $c1Row = orderRow($c1['order_number']);
    $c1Id = (int) $c1Row['id'];
    (new PaymentAttempts($db))->open($c1Row, 'whatsapp', 'WA-CLAIM-' . $stamp, PaymentAttempts::manualWindowMinutes());
    $db->execute("UPDATE `orders` SET `customer_marked_paid_at` = NOW(), `expires_at` = NOW() - INTERVAL 1 MINUTE WHERE `id` = ?", 'i', [$c1Id]);
    $fulfillment->expireOrder($c1Id);
    $c1After = orderRow($c1['order_number']);
    check('a claimed order that expires is flagged, not just cancelled', $c1After['order_status'] === 'cancelled' && (int) $c1After['requires_attention'] === 1 && str_contains((string) $c1After['attention_reason'], 'said they sent the transfer'), json_encode($c1After['attention_reason']));
    expectThrows('refunding before any money is recorded still says so', fn () => $fulfillment->recordRefund($c1Id, 100, 'No money yet', 'completed', false, null), 'no successful payment');
    $c1Stock = stockOf($variantA);
    $c1Confirm = http('POST', "{$api}/admin/orders/{$c1['order_number']}/confirm-payment", json_encode(['reference' => 'LATE-' . $stamp]), $h, $jar);
    check('the admin can still confirm the transfer', $c1Confirm['status'] === 200 && str_contains((string) ($c1Confirm['json']['message'] ?? ''), 'late payment'), $c1Confirm['text']);
    $c1Paid = orderRow($c1['order_number']);
    check('the order stays cancelled, flagged, with the money recorded', $c1Paid['order_status'] === 'cancelled' && $c1Paid['payment_status'] === 'successful' && (int) $c1Paid['requires_attention'] === 1);
    check('the stock was not taken again', stockOf($variantA) === $c1Stock);
    check('the flag offers only what the admin can really do', !str_contains((string) $c1Paid['attention_reason'], 'reinstate') && str_contains((string) $c1Paid['attention_reason'], 'cannot be reopened'), (string) $c1Paid['attention_reason']);
    $c1Report = http('GET', "{$api}/admin/analytics/report?range=today", null, [], $jar);
    check('the money shows in reports as owed', in_array($c1['order_number'], array_column($c1Report['json']['data']['owed_refunds'] ?? [], 'order_number'), true));
    expectThrows('it cannot be confirmed twice', fn () => $finalizer->recordManualPayment($c1Id, 'LATE2-' . $stamp, null), 'Only orders awaiting');
    expectThrows('a cancelled order nobody claimed cannot be confirmed', fn () => $finalizer->recordManualPayment((int) orderRow($o1['order_number'])['id'], 'NOCLAIM-' . $stamp, null), 'Only orders awaiting');
    $fulfillment->recordRefund($c1Id, (float) $c1Paid['grand_total'], 'Order had expired', 'completed', false, null);
    $c1Done = orderRow($c1['order_number']);
    check('refunding it clears the flag; the order stays cancelled', $c1Done['order_status'] === 'cancelled' && $c1Done['payment_status'] === 'refunded' && (int) $c1Done['requires_attention'] === 0);

    // -----------------------------------------------------------------------
    echo "== The scheduled expiry when the gateway can't be asked\n";
    $envDir = sys_get_temp_dir() . '/shopco-expiry-' . getmypid();
    @mkdir($envDir, 0700, true);
    $envFile = "{$envDir}/env";
    file_put_contents($envFile, implode("\n", array_merge(
        array_filter(file((string) getenv('SHOPCO_ENV_FILE'), FILE_IGNORE_NEW_LINES) ?: [], static fn (string $l): bool => !in_array(strstr($l, '=', true), ['KORA_API_BASE_URL', 'MONNIFY_API_BASE_URL'], true)),
        ['KORA_API_BASE_URL=http://127.0.0.1:1', 'MONNIFY_API_BASE_URL=http://127.0.0.1:1']
    )) . "\n");
    $sweep = static function () use ($envFile): string {
        $proc = proc_open([PHP_BINARY, BASE_PATH . '/scripts/expire_pending_orders.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, array_merge(getenv(), ['SHOPCO_ENV_FILE' => $envFile]));
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($proc);
        return $out;
    };
    $e1 = $placeOrder($line);
    $koraMock->initializeCheckout($e1['order_number']);
    $db->execute("UPDATE `orders` SET `expires_at` = NOW() - INTERVAL 1 MINUTE WHERE `order_number` = ?", 's', [$e1['order_number']]);
    $sweepOut = $sweep();
    check('a failed gateway check leaves the order for a later run', orderRow($e1['order_number'])['order_status'] === 'pending_payment' && str_contains($sweepOut, "{$e1['order_number']}: left for the next run"), $sweepOut);
    $db->execute("UPDATE `orders` SET `expires_at` = NOW() - INTERVAL ? MINUTE WHERE `order_number` = ?", 'is', [FulfillmentService::GATEWAY_GRACE_MINUTES + 1, $e1['order_number']]);
    $sweepOut = $sweep();
    check('past the grace period it expires anyway', orderRow($e1['order_number'])['order_status'] === 'cancelled', $sweepOut);
    @unlink($envFile);
    @rmdir($envDir);

    // -----------------------------------------------------------------------
    echo "== A removed variant can be added back\n";
    $backBody = static function (array $extra) use ($db, $productId): array {
        $rows = $db->fetchAll("SELECT `id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity` FROM `product_variants` WHERE `product_id` = ? AND `is_active` = 1", 'i', [$productId]);
        return ['variants' => array_merge(array_map(static fn (array $v): array => ['id' => (int) $v['id'], 'sku' => $v['sku'], 'size' => $v['size'], 'color_name' => $v['color_name'], 'color_hex' => $v['color_hex']], $rows), $extra)];
    };
    $archived = $db->fetchOne("SELECT `is_active`, `stock_quantity` FROM `product_variants` WHERE `id` = ?", 'i', [$variantB]);
    check('the variant is archived and has other stock', (int) $archived['is_active'] === 0 && (int) $archived['stock_quantity'] !== 7, json_encode($archived));
    $back = $put($backBody([['sku' => '', 'size' => 'Large', 'color_name' => 'White', 'color_hex' => '#ffffff', 'stock_quantity' => 7]]));
    $revived = $db->fetchOne("SELECT `is_active`, `stock_quantity` FROM `product_variants` WHERE `id` = ?", 'i', [$variantB]);
    check('added back with no id: revived with the new stock', $back['status'] === 200 && (int) $revived['is_active'] === 1 && (int) $revived['stock_quantity'] === 7, $back['text']);
    $backLog = $db->fetchOne("SELECT `previous_stock`, `new_stock`, `reason` FROM `inventory_logs` WHERE `variant_id` = ? ORDER BY `id` DESC LIMIT 1", 'i', [$variantB]);
    check('and the change is logged', (int) $backLog['new_stock'] === 7 && (int) $backLog['previous_stock'] === (int) $archived['stock_quantity'] && $backLog['reason'] === 'Variant added back', json_encode($backLog));
    $db->execute("UPDATE `product_variants` SET `is_active` = 0 WHERE `id` = ?", 'i', [$variantB]);
    $back = $put($backBody([['id' => $variantB, 'sku' => '', 'size' => 'Large', 'color_name' => 'White', 'color_hex' => '#ffffff', 'stock_quantity' => 9]]));
    check('added back by its id: the same', $back['status'] === 200 && stockOf($variantB) === 9, $back['text']);
    $currentB = stockOf($variantB);
    $stale = $put($backBody([['id' => $variantB, 'sku' => '', 'size' => 'Large', 'color_name' => 'White', 'color_hex' => '#ffffff', 'stock_quantity' => 50, 'original_stock_quantity' => $currentB - 1]]));
    check('an active variant keeps its stale-form protection', $stale['status'] === 422 && stockOf($variantB) === $currentB, $stale['text']);
    @unlink($jar);
} catch (Throwable $e) {
    $fail++;
    echo "FAIL  unexpected exception: " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    // -----------------------------------------------------------------------
    echo "== Cleanup\n";
    foreach ($created['orders'] as $num) {
        $db->execute("DELETE FROM `orders` WHERE `order_number` = ?", 's', [$num]);
    }
    $db->execute("DELETE FROM `order_items` WHERE `product_id` = ?", 'i', [$productId ?? 0]);
    $db->execute("DELETE FROM `inventory_logs` WHERE `product_id` = ?", 'i', [$productId ?? 0]);
    $db->execute("DELETE FROM `products` WHERE `id` = ?", 'i', [$productId ?? 0]);
    $db->execute("DELETE FROM `categories` WHERE `id` = ?", 'i', [$categoryId ?? 0]);
    $db->execute("DELETE FROM `promotions` WHERE `code` IN (?, ?)", 'ss', [$promoCode ?? '', "P2HTTP{$stamp}"]);
    $db->execute("DELETE FROM `banners` WHERE `title` = ?", 's', ["P2 {$stamp}"]);
    foreach ($created['users'] as $email) {
        $db->execute("DELETE FROM `users` WHERE `email` = ?", 's', [$email]);
    }
    foreach ($maxIds as $table => $max) {
        $db->execute("DELETE FROM `{$table}` WHERE `id` > ?", 'i', [$max]);
    }
    $db->execute("DELETE FROM `rate_limits`");
    echo "Removed test orders, product, category, promotions, banner, admin and the log rows they created.\n";
}

echo "\nRESULT: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
