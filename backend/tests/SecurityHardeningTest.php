<?php

declare(strict_types=1);

/**
 * The protections added after the 22 Nov 2026 penetration test:
 *  - rate limits count an IPv6 visitor's whole /64, so changing addresses can't reset them;
 *  - flooding an account with wrong passwords can't lock its owner out of a browser they used;
 *  - one item split over many lines is still capped, and one connection can hold only so many
 *    unpaid orders whatever email, phone or device it gives;
 *  - "I've sent the transfer" can't revive a closed order or hold stock far past the normal limit;
 *  - a payment reference alone shows no personal details, and no reply carries internal fields;
 *  - a not-yet-started promo code answers like an unknown one;
 *  - a bank reference counts once whatever its spacing, dashes or capitals;
 *  - a payment reported failed or reversed after it was confirmed flags the order;
 *  - sign-in can't be posted from another website, and form posts only reach the upload;
 *  - picture addresses and banner links must be the shop's own files/pages or https://;
 *  - a missing APP_ENV means production.
 *
 *   php backend/tests/run.php SecurityHardening
 */

require __DIR__ . '/support/TestKit.php';

use App\Config\Env;
use App\Services\CheckoutService;
use App\Services\PaymentFinalizer;
use App\Support\Input;
use App\Support\RateLimiter;

$t = new TestKit('SecurityHardening');
$admin = $t->adminSession('harden');
$A = static fn (string $method, string $path, array $opts = []): array
    => $t->http($method, $path, $opts + ['jar' => $admin['jar'], 'csrf' => $admin['csrf']]);
$from = static fn (string $ip): string => 'X-Forwarded-For: ' . $ip;

$variant = $t->variant(25);
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

/** Places a guest order; returns [HTTP answer, order row or null]. */
$order = static function (array $items, string $ip = '127.0.0.1', ?string $email = null) use ($t, $from, &$placed): array {
    $res = $t->http('POST', '/checkout/orders', [
        'headers' => ['Idempotency-Key: ' . bin2hex(random_bytes(16)), $from($ip)],
        'json' => [
            'email' => $email ?? $t->uniq('buyer') . '@example.test',
            'items' => $items,
            'shipping_address' => ['full_name' => 'Hardening Buyer', 'phone' => '080' . random_int(10000000, 99999999), 'address_line1' => '9 Safe Street', 'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria'],
        ],
    ]);
    $row = null;
    if ($res['status'] === 201) {
        $row = $t->sql("SELECT * FROM `orders` WHERE `order_number` = ?", 's', [(string) TestKit::get($res, 'data.order_number')])[0] ?? null;
        if ($row !== null) {
            $placed[] = (int) $row['id'];
        }
    }
    return [$res, $row];
};
$line = static fn (int $qty): array => ['product_id' => (int) $variant['product_id'], 'variant_id' => (int) $variant['variant_id'], 'quantity' => $qty];

// -----------------------------------------------------------------------------
$t->section('Rate limits count an IPv6 visitor by network');
$t->check('an IPv6 address counts as its /64', '2001:db8:1:2::/64', RateLimiter::networkOf('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
$t->check('another address in the same /64 counts the same', '2001:db8:1:2::/64', RateLimiter::networkOf('2001:db8:1:2::9'));
$t->check('an IPv4 address stays itself', '203.0.113.5', RateLimiter::networkOf('203.0.113.5'));
$t->check('an IPv4-mapped address stays itself', '::ffff:203.0.113.5', RateLimiter::networkOf('::ffff:203.0.113.5'));
$t->check('an IPv4 address written the IPv6 way is read as IPv4', '203.0.113.5', \App\Support\RequestContext::plainIp('::ffff:203.0.113.5'));
$t->check('a real IPv6 address is left alone', '2001:db8::1', \App\Support\RequestContext::plainIp('2001:db8::1'));
$t->check('::ffff:127.0.0.1 is the trusted local proxy too', true, \App\Support\RequestContext::isTrustedProxy('::ffff:127.0.0.1'));
$guessEmail = $t->uniq('nobody') . '@example.test';
$codes = [];
for ($i = 1; $i <= 7; $i++) {
    $codes[] = $t->http('POST', '/auth/login', ['headers' => [$from("2001:db8:77:1::{$i}")], 'json' => ['email' => $guessEmail, 'password' => "wrong-{$i}"]])['status'];
}
$t->ok('changing the end of an IPv6 address does not reset the sign-in limit', in_array(429, $codes, true), implode(',', $codes));

// -----------------------------------------------------------------------------
$t->section('Wrong passwords from many places cannot lock the owner out of their own browser');
$owner = $t->adminSession('owner-lock');
$known = $t->http('POST', '/auth/login', ['jar' => 'known-device', 'headers' => [$from('203.0.113.200')], 'json' => ['email' => $owner['email'], 'password' => $owner['password']]]);
$t->check('the owner signs in once on their browser', 200, $known['status']);
$t->ok('that browser is remembered', (int) $t->scalar("SELECT COUNT(*) FROM `login_devices` WHERE `user_id` = ?", 'i', [$owner['id']]) >= 1);
for ($i = 1; $i <= 21; $i++) {
    $t->http('POST', '/auth/login', ['headers' => [$from('198.51.100.' . $i)], 'json' => ['email' => $owner['email'], 'password' => "wrong-{$i}"]]);
}
$stranger = $t->http('POST', '/auth/login', ['jar' => 'unknown-device', 'headers' => [$from('203.0.113.201')], 'json' => ['email' => $owner['email'], 'password' => $owner['password']]]);
$t->check('a new browser is held back by the account limit', 429, $stranger['status']);
$again = $t->http('POST', '/auth/login', ['jar' => 'known-device', 'headers' => [$from('203.0.113.202')], 'json' => ['email' => $owner['email'], 'password' => $owner['password']]]);
$t->check('the owner\'s own browser still signs in', 200, $again['status']);

// -----------------------------------------------------------------------------
$t->section('One item split over many lines is still capped');
[$split] = $order([$line(15), $line(10)]);
$t->check('25 of one item over two lines: refused', 422, $split['status']);
$t->ok('with a plain reason', str_contains((string) TestKit::get($split, 'error.message'), 'at most 20'), (string) TestKit::get($split, 'error.message'));

// -----------------------------------------------------------------------------
$t->section('One connection can hold only so many unpaid orders');
$capBefore = (string) $t->scalar("SELECT `value` FROM `store_settings` WHERE `key_name` = ?", 's', [CheckoutService::UNPAID_CAP_SETTING]);
$t->cleanup(fn () => $t->sql("UPDATE `store_settings` SET `value` = ? WHERE `key_name` = ?", 'ss', [$capBefore, CheckoutService::UNPAID_CAP_SETTING]));
$t->sql("UPDATE `store_settings` SET `value` = '2' WHERE `key_name` = ?", 's', [CheckoutService::UNPAID_CAP_SETTING]);
$ok = 0;
for ($i = 0; $i < 10; $i++) {
    [$res] = $order([$line(1)], '203.0.113.77');
    $ok += $res['status'] === 201 ? 1 : 0;
}
$t->check('ten orders from one connection (shared mobile networks), each with a new email and phone: placed', 10, $ok);
// The cap matters over the hours unpaid orders are held, long after the 10-per-10-minutes order
// limit has reset: clear that counter so the cap itself answers.
$resetOrderLimit = static fn () => $t->sql("DELETE FROM `rate_limits` WHERE `id` = ?", 's', [hash('sha256', 'checkout_order|203.0.113.77')]);
$resetOrderLimit();
[$eleventh] = $order([$line(1)], '203.0.113.77');
$t->check('the eleventh is refused', 409, $eleventh['status']);
$t->ok('and says why', str_contains((string) TestKit::get($eleventh, 'error.message'), 'this connection'), (string) TestKit::get($eleventh, 'error.message'));
[$mapped] = $order([$line(1)], '::ffff:203.0.113.77');
$t->check('the same address written the IPv6 way counts as the same connection', 409, $mapped['status']);
[$other] = $order([$line(1)], '203.0.113.78');
$t->check('another connection can still order', 201, $other['status']);
$t->sql("UPDATE `store_settings` SET `value` = '0' WHERE `key_name` = ?", 's', [CheckoutService::UNPAID_CAP_SETTING]);

// -----------------------------------------------------------------------------
$t->section('"I\'ve sent the transfer" cannot revive or stretch a hold');
[$res, $o] = $order([$line(1)]);
$token = (string) TestKit::get($res, 'data.guest_access_token');
$init = $t->http('POST', "/checkout/orders/{$o['order_number']}/initialize-payment", ['headers' => ['X-Guest-Access-Token: ' . $token], 'json' => ['gateway' => 'whatsapp', 'guest_access_token' => $token]]);
$t->check('a WhatsApp payment is started', 200, $init['status']);
$t->sql("UPDATE `orders` SET `expires_at` = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE `id` = ?", 'i', [(int) $o['id']]);
$late = $t->http('POST', "/checkout/orders/{$o['order_number']}/payment-sent", ['headers' => ['X-Guest-Access-Token: ' . $token], 'json' => ['guest_access_token' => $token]]);
$t->check('after its window closed: the claim is still recorded (so a late transfer can be found)', true, TestKit::get($late, 'data.recorded'));
$t->ok('the customer is told the window closed', str_contains((string) TestKit::get($late, 'message'), 'window has closed'), (string) TestKit::get($late, 'message'));
$lateRow = $t->sql("SELECT `customer_marked_paid_at`, `expires_at` < NOW() AS still_closed FROM `orders` WHERE `id` = ?", 'i', [(int) $o['id']])[0];
$t->ok('the claim time is kept', $lateRow['customer_marked_paid_at'] !== null);
$t->check('but the order is not held any longer', 1, (int) $lateRow['still_closed']);

[$res2, $o2] = $order([$line(1)]);
$token2 = (string) TestKit::get($res2, 'data.guest_access_token');
$t->http('POST', "/checkout/orders/{$o2['order_number']}/initialize-payment", ['headers' => ['X-Guest-Access-Token: ' . $token2], 'json' => ['gateway' => 'whatsapp', 'guest_access_token' => $token2]]);
// Placed 7 hours ago, still inside its window: the claim may hold it only to 2 hours past the longest normal hold.
$t->sql("UPDATE `orders` SET `created_at` = DATE_SUB(NOW(), INTERVAL 7 HOUR), `expires_at` = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE `id` = ?", 'i', [(int) $o2['id']]);
$claim = $t->http('POST', "/checkout/orders/{$o2['order_number']}/payment-sent", ['headers' => ['X-Guest-Access-Token: ' . $token2], 'json' => ['guest_access_token' => $token2]]);
$t->check('a claim inside the window is recorded', true, TestKit::get($claim, 'data.recorded'));
$minutesLeft = (int) $t->scalar("SELECT TIMESTAMPDIFF(MINUTE, NOW(), `expires_at`) FROM `orders` WHERE `id` = ?", 'i', [(int) $o2['id']]);
// Longest normal hold (6 h + 30 min) + 2 h, from 7 hours ago: about 90 minutes left, not 6 hours.
$t->ok('but holds the stock only to two hours past the longest normal hold, not six more hours', $minutesLeft >= 80 && $minutesLeft <= 95, "minutes left: {$minutesLeft}");

// -----------------------------------------------------------------------------
$t->section('What an order reply shows');
$own = $t->http('GET', "/checkout/orders/{$o2['order_number']}", ['headers' => ['X-Guest-Access-Token: ' . $token2]]);
$t->check('with the key: the order', 200, $own['status']);
$t->ok('no internal fields', !array_key_exists('device_hash', (array) TestKit::get($own, 'data')) && !array_key_exists('client_network', (array) TestKit::get($own, 'data')));
$t->ok('the customer\'s details', TestKit::get($own, 'data.guest_email') !== null && TestKit::get($own, 'data.shipping_address_snapshot.address_line1') !== null);
$reference = (string) $t->scalar("SELECT `provider_reference` FROM `payments` WHERE `order_id` = ? ORDER BY `id` DESC LIMIT 1", 'i', [(int) $o2['id']]);
$byRef = $t->http('GET', "/checkout/orders/{$o2['order_number']}?reference=" . rawurlencode($reference));
$t->check('with only the payment reference: the order', 200, $byRef['status']);
$t->ok('without email, phone or street', TestKit::get($byRef, 'data.guest_email') === null && TestKit::get($byRef, 'data.contact_phone') === null && TestKit::get($byRef, 'data.shipping_address_snapshot.address_line1') === null);
$t->check('only the town and state', 'Ikeja', TestKit::get($byRef, 'data.shipping_address_snapshot.city'));

// -----------------------------------------------------------------------------
$t->section('A code that has not started answers like an unknown one');
$future = 'SOON' . strtoupper(bin2hex(random_bytes(3)));
$t->sql(
    "INSERT INTO `promotions` (`code`, `description`, `discount_type`, `discount_percent`, `max_uses`, `used_count`, `is_active`, `starts_at`)
     VALUES (?, 'later', 'percent', 10, 0, 0, 1, DATE_ADD(NOW(), INTERVAL 3 DAY))",
    's',
    [$future]
);
$t->cleanup(fn () => $t->sql("DELETE FROM `promotions` WHERE `code` = ?", 's', [$future]));
$soon = $t->http('POST', '/checkout/validate-promo', ['headers' => [$from('203.0.113.90')], 'json' => ['code' => $future, 'subtotal' => 50000]]);
$none = $t->http('POST', '/checkout/validate-promo', ['headers' => [$from('203.0.113.90')], 'json' => ['code' => 'NOSUCH' . $future, 'subtotal' => 50000]]);
$t->check('refused', 422, $soon['status']);
$t->check('with the same words as an unknown code', str_replace('NOSUCH' . $future, $future, (string) TestKit::get($none, 'error.message')), (string) TestKit::get($soon, 'error.message'));
$quoteCodes = [];
for ($i = 0; $i < 32; $i++) {
    $quoteCodes[] = $t->http('POST', '/checkout/quote', ['headers' => [$from('203.0.113.91')], 'json' => ['items' => [$line(1)], 'coupon_code' => 'GUESS' . $i]])['status'];
}
$t->ok('guessing codes through the price check hits the promo limit', in_array(429, $quoteCodes, true), implode(',', array_unique($quoteCodes)));
$real = 'REAL' . strtoupper(bin2hex(random_bytes(3)));
$t->sql("INSERT INTO `promotions` (`code`, `description`, `discount_type`, `discount_percent`, `max_uses`, `used_count`, `is_active`) VALUES (?, 'real', 'percent', 10, 0, 0, 1)", 's', [$real]);
$t->cleanup(fn () => $t->sql("DELETE FROM `promotions` WHERE `code` = ?", 's', [$real]));
$realCodes = [];
for ($i = 0; $i < 35; $i++) {
    $realCodes[] = $t->http('POST', $i % 2 ? '/checkout/validate-promo' : '/checkout/quote', ['headers' => [$from('203.0.113.92')], 'json' => ['code' => $real, 'coupon_code' => $real, 'subtotal' => 50000, 'items' => [$line(1)]]])['status'];
}
$t->ok('a real code checked again and again (shoppers sharing an address) is never limited', !in_array(429, $realCodes, true), implode(',', array_unique($realCodes)));

// -----------------------------------------------------------------------------
$t->section('A bank reference counts once, whatever its spacing or capitals');
[$r3, $o3] = $order([$line(1)]);
[$r4, $o4] = $order([$line(1)]);
$ref = 'TRF ' . strtoupper(bin2hex(random_bytes(3)));
$first = $A('POST', "/admin/orders/{$o3['order_number']}/confirm-payment", ['json' => ['reference' => $ref]]);
$t->check('the first order is confirmed', 200, $first['status']);
$second = $A('POST', "/admin/orders/{$o4['order_number']}/confirm-payment", ['json' => ['reference' => strtolower(str_replace(' ', '-', $ref))]]);
$t->check('the same reference written differently is refused', 422, $second['status']);

// -----------------------------------------------------------------------------
$t->section('A payment reported failed or reversed after it was confirmed flags the order');
$paidRef = (string) $t->scalar("SELECT `provider_reference` FROM `payments` WHERE `order_id` = ? AND `status` = 'successful' LIMIT 1", 'i', [(int) $o3['id']]);
(new PaymentFinalizer())->finalizeFailure($paidRef, 'REVERSED', [], 'Monnify');
$flag = $t->sql("SELECT `requires_attention`, `attention_reason`, `payment_status` FROM `orders` WHERE `id` = ?", 'i', [(int) $o3['id']])[0];
$t->check('flagged for attention', 1, (int) $flag['requires_attention']);
$t->ok('with the reason', str_contains((string) $flag['attention_reason'], 'reversed'), (string) $flag['attention_reason']);
$t->check('the payment itself is unchanged', 'successful', $flag['payment_status']);
[$r5, $o5] = $order([$line(1)]);
$A('POST', "/admin/orders/{$o5['order_number']}/confirm-payment", ['json' => ['reference' => 'TRF ' . strtoupper(bin2hex(random_bytes(4)))]]);
$paidRef5 = (string) $t->scalar("SELECT `provider_reference` FROM `payments` WHERE `order_id` = ? AND `status` = 'successful' LIMIT 1", 'i', [(int) $o5['id']]);
(new PaymentFinalizer())->finalizeFailure($paidRef5, 'Transaction failed or was declined.', [], 'Kora');
$t->check('a late repeat of an earlier failed attempt does not flag a paid order', 0, (int) $t->scalar("SELECT `requires_attention` FROM `orders` WHERE `id` = ?", 'i', [(int) $o5['id']]));

// -----------------------------------------------------------------------------
$t->section('Sign-in can only be posted from the shop itself');
$cross = $t->http('POST', '/auth/login', ['headers' => ['Sec-Fetch-Site: cross-site', $from('203.0.113.95')], 'json' => ['email' => $owner['email'], 'password' => $owner['password']]]);
$t->check('a sign-in posted from another website: refused', 403, $cross['status']);
$form = $t->http('POST', '/auth/login', ['headers' => ['Content-Type: multipart/form-data; boundary=x', $from('203.0.113.96')], 'body' => "--x\r\nContent-Disposition: form-data; name=\"email\"\r\n\r\na@b.c\r\n--x--\r\n"]);
$t->check('a form post anywhere but the upload: refused', 415, $form['status']);

// -----------------------------------------------------------------------------
$t->section('Picture addresses and links');
foreach (['/images/hero/a.webp', '/uploads/prod_1.webp', '/uploads/catalog/product-x.webp', 'https://cdn.example.com/a.jpg'] as $ok) {
    $t->check("picture {$ok}: accepted", true, Input::isPictureAddress($ok));
}
foreach (['/images/%2e%2e/x', '/images/../x', 'http://x.test/a.jpg', 'javascript:alert(1)', 'data:image/png;base64,AA'] as $bad) {
    $t->check("picture {$bad}: refused", false, Input::isPictureAddress($bad));
}
foreach (['/category?cat=x', '/', 'https://ok.example/x'] as $ok) {
    $t->check("link {$ok}: accepted", true, Input::isShopLink($ok));
}
foreach (['//evil.example', '/' . chr(92) . 'evil.example', '/' . chr(9) . '/evil', 'http://x.example', 'javascript:x'] as $bad) {
    $t->check('link ' . json_encode($bad) . ': refused', false, Input::isShopLink($bad));
}
$banner = $A('POST', '/admin/storefront/banners', ['json' => ['title' => 'T', 'image_url' => '/uploads/prod_1.webp', 'cta_url' => '/' . chr(92) . 'evil.example']]);
$t->check('a banner linking off-site through a backslash: refused', 422, $banner['status']);

// -----------------------------------------------------------------------------
$t->section('A missing APP_ENV means production');
$vars = new ReflectionProperty(Env::class, 'vars');
$saved = $vars->getValue();
$t->cleanup(fn () => $vars->setValue(null, $saved));
foreach (['' => true, 'prod' => true, 'production' => true, 'development' => false, 'testing' => false] as $value => $production) {
    $vars->setValue(null, array_merge($saved, ['APP_ENV' => (string) $value]));
    $t->check('APP_ENV=' . json_encode((string) $value), $production, Env::isProduction());
}
$vars->setValue(null, $saved);

exit($t->finish());
