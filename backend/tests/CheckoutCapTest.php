<?php

declare(strict_types=1);

/**
 * The cap on unpaid orders: one customer (account, email, phone or device) can hold only so many
 * orders that are still awaiting payment, so stock cannot be kept from real buyers by orders
 * nobody pays for. Paying, cancelling or the payment window closing frees a place.
 *
 *   php backend/tests/run.php CheckoutCap
 */

require __DIR__ . '/support/TestKit.php';

use App\Services\CheckoutService;
use App\Services\PaymentAttempts;

$t = new TestKit('CheckoutCap');
$admin = $t->adminSession('cap');
$A = static fn (string $method, string $path, array $opts = []): array
    => $t->http($method, $path, $opts + ['jar' => $admin['jar'], 'csrf' => $admin['csrf']]);

// The suites run with the cap off (run.php): put that back when this one is done.
$before = (string) $t->scalar("SELECT `value` FROM `store_settings` WHERE `key_name` = ?", 's', [CheckoutService::UNPAID_CAP_SETTING]);
$t->cleanup(fn () => $t->sql("UPDATE `store_settings` SET `value` = ? WHERE `key_name` = ?", 'ss', [$before, CheckoutService::UNPAID_CAP_SETTING]));
$setCap = static fn (string $value): array => $A('PUT', '/admin/settings', ['json' => [CheckoutService::UNPAID_CAP_SETTING => $value]]);

$variant = $t->variant(12);
$placed = [];
$t->cleanup(function () use ($t, &$placed): void {
    foreach ($placed as $oid) {
        $t->sql(
            "UPDATE `product_variants` v JOIN `order_items` oi ON oi.variant_id = v.id
             SET v.stock_quantity = v.stock_quantity + oi.quantity
             WHERE oi.order_id = ? AND (SELECT `order_status` FROM `orders` WHERE `id` = ?) = 'pending_payment'",
            'ii',
            [$oid, $oid]
        );
        foreach (['payments', 'order_status_history', 'order_items'] as $table) {
            $t->sql("DELETE FROM `{$table}` WHERE `order_id` = ?", 'i', [$oid]);
        }
        $t->sql("DELETE FROM `orders` WHERE `id` = ?", 'i', [$oid]);
    }
});

/** Place an order as a guest; returns the HTTP answer. */
$order = static function (string $email, string $phone, ?string $device = null) use ($t, $variant, &$placed): array {
    $headers = ['Idempotency-Key: ' . bin2hex(random_bytes(16))];
    if ($device !== null) {
        $headers[] = 'X-Device-Id: ' . $device;
    }
    $res = $t->http('POST', '/checkout/orders', [
        'headers' => $headers,
        'json' => [
            'email' => $email,
            'items' => [['product_id' => (int) $variant['product_id'], 'variant_id' => (int) $variant['variant_id'], 'quantity' => 1]],
            'shipping_address' => ['full_name' => 'Cap Buyer', 'phone' => $phone, 'address_line1' => '4 Cap Street', 'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria'],
        ],
    ]);
    if ($res['status'] === 201) {
        $placed[] = (int) $t->scalar("SELECT `id` FROM `orders` WHERE `order_number` = ?", 's', [(string) TestKit::get($res, 'data.order_number')]);
    }
    return $res;
};
// The separate "10 orders in 10 minutes per connection" limit would stop this suite: start each
// section with a clean count.
$freshLimit = static fn () => $t->sql("DELETE FROM `rate_limits` WHERE `id` IN (?, ?)", 'ss', [hash('sha256', 'checkout_order|127.0.0.1'), hash('sha256', 'checkout_order|::1')]);
$phone = static fn (): string => '0803' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
$mail = static fn (string $label): string => $t->uniq("t-cap-{$label}") . '@example.test';

// -----------------------------------------------------------------------------
$t->section('The setting');
$t->check('the windows are 30 minutes and 6 hours unless the server says otherwise', [30, 360], [PaymentAttempts::gatewayWindowMinutes(), PaymentAttempts::manualWindowMinutes()]);
foreach (['-1', '21', '2.5', 'two'] as $bad) {
    $t->check("cap {$bad}: refused", 422, $setCap($bad)['status']);
}
$t->check('cap 2 is saved', 200, $setCap('2')['status']);
$shown = null;
foreach ((array) TestKit::get($A('GET', '/admin/settings'), 'data.editable') as $item) {
    if ($item['key'] === CheckoutService::UNPAID_CAP_SETTING) {
        $shown = $item['value'];
    }
}
$t->check('Settings shows it', '2', $shown);

// -----------------------------------------------------------------------------
$t->section('By email');
$freshLimit();
$email = $mail('email');
$t->check('first unpaid order', 201, $order($email, $phone())['status']);
$t->check('second unpaid order', 201, $order($email, $phone())['status']);
$third = $order($email, $phone());
$t->check('a third is refused, with its own code', [409, 'TOO_MANY_UNPAID_ORDERS'], [$third['status'], TestKit::get($third, 'error.code')]);
$t->ok('the message says how many are waiting and what to do', str_contains((string) TestKit::get($third, 'error.message'), '2 orders waiting for payment') && str_contains((string) TestKit::get($third, 'error.message'), 'Track order'));
$t->check('the same email in capitals is the same customer', 409, $order(strtoupper($email), $phone())['status']);
$t->check('another customer is not affected', 201, $order($mail('other'), $phone())['status']);

// -----------------------------------------------------------------------------
$t->section('By phone and by device');
$freshLimit();
$sharedPhone = $phone();
$t->check('first order on a phone number', 201, $order($mail('p1'), $sharedPhone)['status']);
$t->check('second, another email, the number typed with +234', 201, $order($mail('p2'), '+234' . substr($sharedPhone, 1))['status']);
$t->check('a third email on that number is refused', 409, $order($mail('p3'), $sharedPhone)['status']);

$device = bin2hex(random_bytes(16));
$t->check('first order from a device', 201, $order($mail('d1'), $phone(), $device)['status']);
$t->check('second from the same device', 201, $order($mail('d2'), $phone(), $device)['status']);
$t->check('a third from that device is refused, whatever email and phone it gives', 409, $order($mail('d3'), $phone(), $device)['status']);
$t->check('a malformed device id is ignored, not trusted', 201, $order($mail('d4'), $phone(), 'x')['status']);
$t->ok('only a hash of the device id is stored', (int) $t->scalar("SELECT COUNT(*) FROM `orders` WHERE `device_hash` = ?", 's', [hash('sha256', $device)]) === 2
    && (int) $t->scalar("SELECT COUNT(*) FROM `orders` WHERE `device_hash` = ?", 's', [$device]) === 0);

// -----------------------------------------------------------------------------
$t->section('A place is freed when an order stops waiting');
$freshLimit();
$freed = $mail('freed');
$first = $order($freed, $phone());
$order($freed, $phone());
$t->check('at the cap', 409, $order($freed, $phone())['status']);
$firstNumber = (string) TestKit::get($first, 'data.order_number');
$token = (string) TestKit::get($first, 'data.guest_access_token');
$released = $t->http('POST', '/checkout/orders/' . rawurlencode($firstNumber) . '/release', ['headers' => ['X-Guest-Access-Token: ' . $token], 'json' => ['guest_access_token' => $token]]);
$t->check('the customer gives one up', [200, true], [$released['status'], TestKit::get($released, 'data.released')]);
$t->check('and can order again', 201, $order($freed, $phone())['status']);

$expired = $mail('expired');
$order($expired, $phone());
$order($expired, $phone());
$t->sql("UPDATE `orders` SET `expires_at` = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE `guest_email` = ?", 's', [$expired]);
$t->check('orders past their payment window no longer count', 201, $order($expired, $phone())['status']);

// -----------------------------------------------------------------------------
$t->section('The owner can change or lift it');
$freshLimit();
$t->check('cap 1', 200, $setCap('1')['status']);
$one = $mail('one');
$t->check('one unpaid order', 201, $order($one, $phone())['status']);
$single = $order($one, $phone());
$t->ok('a second is refused, in the singular', $single['status'] === 409 && str_contains((string) TestKit::get($single, 'error.message'), '1 order waiting for payment'));
$t->check('cap 0 lifts it', 200, $setCap('0')['status']);
$t->check('and the same customer can order', 201, $order($one, $phone())['status']);

exit($t->finish());
