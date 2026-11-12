<?php

declare(strict_types=1);

/**
 * Storefront API flows: catalogue, cart check, delivery quotes, guest checkout (idempotent,
 * stock reserved once), payment attempts, magic-link account linking, saved cart/wishlist,
 * reviews, homepage stats, flash-deal expiry and the newsletter.
 *
 *   php backend/tests/run.php StorefrontFlow
 */

require __DIR__ . '/support/TestKit.php';

$t = new TestKit('StorefrontFlow');
$admin = $t->adminSession('store');

$v = $t->variant(3);
if ($v === null) {
    $t->ok('a published variant with stock exists (seed data)', false);
    exit($t->finish());
}
$pid = (int) $v['product_id'];
$vid = (int) $v['variant_id'];
$slug = (string) $v['slug'];
$stock = (int) $v['stock_quantity'];
$price = (float) $v['price'];

$ordersCreated = [];
$emails = [];
$t->cleanup(function () use ($t, &$ordersCreated, &$emails): void {
    foreach ($ordersCreated as $num) {
        $t->sql("DELETE FROM `email_outbox` WHERE `dedup_key` LIKE ?", 's', ["payment_claimed:{$num}:%"]);
        $t->sql("DELETE FROM `orders` WHERE `order_number` = ?", 's', [$num]);
    }
    foreach ($emails as $email) {
        $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$email]);
        $t->sql("DELETE FROM `reviews` WHERE `author_email` = ?", 's', [$email]);
        $t->sql("DELETE FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$email]);
        $t->sql("DELETE FROM `users` WHERE `email` = ?", 's', [$email]);
    }
});

$address = static fn (string $name, string $state, string $city) => [
    'full_name' => $name, 'phone' => '08000000003', 'address_line1' => '3 Test Close', 'city' => $city, 'state' => $state, 'country' => 'Nigeria',
];

// -----------------------------------------------------------------------------
$t->section('Catalogue');
$facets = $t->http('GET', '/catalog/facets');
$t->check('facets 200', 200, $facets['status']);
$t->ok('facets list real sizes', (array) TestKit::get($facets, 'data.sizes') !== []);
$t->ok('facets list categories', (array) TestKit::get($facets, 'data.categories') !== []);
// A category's filters offer only what that category has; the category list stays complete.
$catSlug = (string) $t->scalar(
    "SELECT c.slug FROM categories c JOIN products p ON p.category_id = c.id AND p.status = 'published'
     JOIN product_variants v ON v.product_id = p.id AND v.is_active = 1 GROUP BY c.id ORDER BY COUNT(*) ASC LIMIT 1"
);
$catFacets = $t->http('GET', '/catalog/facets?category=' . urlencode($catSlug));
$expectSizes = array_column($t->sql(
    "SELECT DISTINCT v.size FROM product_variants v JOIN products p ON p.id = v.product_id JOIN categories c ON c.id = p.category_id
     WHERE c.slug = ? AND p.status = 'published' AND p.product_type = 'physical' AND v.is_active = 1",
    's',
    [$catSlug]
), 'size');
$gotSizes = (array) TestKit::get($catFacets, 'data.sizes');
sort($expectSizes);
$sortedGot = $gotSizes;
sort($sortedGot);
$t->check('category filters list only that category\'s sizes', $expectSizes, $sortedGot);
$t->check('category filters keep the full category list', count((array) TestKit::get($facets, 'data.categories')), count((array) TestKit::get($catFacets, 'data.categories')));
$maxInCategory = (float) $t->scalar("SELECT MAX(p.base_price) FROM products p JOIN categories c ON c.id = p.category_id WHERE c.slug = ? AND p.status = 'published' AND p.product_type = 'physical'", 's', [$catSlug]);
$t->check('category filters price range is the category\'s', $maxInCategory, (float) TestKit::get($catFacets, 'data.price.max'));
$none = $t->http('GET', '/catalog/facets?category=no-such-category');
$t->ok('unknown category has no filter options', (array) TestKit::get($none, 'data.sizes') === [] && (array) TestKit::get($none, 'data.colors') === []);
$pdp = $t->http('GET', "/products/{$slug}");
$t->check('product detail 200', 200, $pdp['status']);
$t->ok('product detail has variants', (array) TestKit::get($pdp, 'data.variants') !== []);
$t->check('product detail has no reviews before any are approved', [], TestKit::get($pdp, 'data.reviews') ?? []);
$ratingCountBefore = (int) TestKit::get($pdp, 'data.rating_count');
$approved = (int) $t->scalar("SELECT COUNT(*) FROM `reviews` WHERE `product_id` = ? AND `status` = 'approved'", 'i', [$pid]);
$t->check('product rating count matches its approved reviews (no invented ratings)', $approved, $ratingCountBefore);
$t->check('best-selling sort works', 200, $t->http('GET', '/products?sort=best_selling&per_page=3')['status']);
$t->check('flash deals filter works', 200, $t->http('GET', '/products?flash_deals=1')['status']);
$t->check('unknown product 404', 404, $t->http('GET', '/products/no-such-product-' . getmypid())['status']);

$publicDetail = $t->http('GET', "/products/{$slug}");
$t->ok('a public product does not show its sales figures', is_array($publicDetail['json']['data'] ?? null) && !array_key_exists('units_sold', $publicDetail['json']['data']));
$t->ok('public variants do not show the low-stock alert level', array_filter((array) TestKit::get($publicDetail, 'data.variants'), static fn (array $variant): bool => array_key_exists('low_stock_threshold', $variant)) === []);
$adminDetail = (new \App\Repositories\ProductRepository())->findDetail($slug, true);
$t->ok('admin still gets both', array_key_exists('units_sold', $adminDetail) && array_key_exists('low_stock_threshold', $adminDetail['variants'][0]));

// A list where text is expected is ignored or refused, never a server error.
foreach (['colors', 'sizes', 'color', 'size', 'category', 'search', 'q', 'dress_style', 'sort', 'min_price', 'max_price', 'page', 'per_page'] as $param) {
    foreach (["{$param}[]=x", "{$param}[][]=x"] as $query) {
        $status = $t->http('GET', "/products?{$query}")['status'];
        $t->ok("/products?{$query} is not a server error", $status < 500, (string) $status);
    }
}

// -----------------------------------------------------------------------------
$t->section('Cart check and delivery quotes');
$cc = $t->http('POST', '/checkout/cart-check', ['json' => ['variant_ids' => [$vid]]]);
$t->check('cart-check returns the live price', $price, (float) TestKit::get($cc, 'data.lines.0.unit_price'));
$t->check('cart-check returns live stock', $stock, (int) TestKit::get($cc, 'data.lines.0.stock_quantity'));

// A product that is not on sale keeps its details to itself.
$productName = (string) $t->scalar("SELECT `name` FROM `products` WHERE `id` = ?", 'i', [$pid]);
$restoreProduct = fn () => $t->sql("UPDATE `products` SET `status` = 'published' WHERE `id` = ?", 'i', [$pid]);
$t->cleanup($restoreProduct);
foreach (['draft', 'archived'] as $hiddenStatus) {
    $t->sql("UPDATE `products` SET `status` = ? WHERE `id` = ?", 'si', [$hiddenStatus, $pid]);
    $hiddenCheck = $t->http('POST', '/checkout/cart-check', ['json' => ['variant_ids' => [$vid]]]);
    $t->check("cart-check on a {$hiddenStatus} product says only that it is unavailable", ['variant_id' => $vid, 'available' => false], TestKit::get($hiddenCheck, 'data.lines.0'));
    $hiddenQuote = $t->http('POST', '/checkout/quote', ['json' => ['items' => [['product_id' => $pid, 'variant_id' => $vid, 'quantity' => 1]]]]);
    $t->check("a quote for a {$hiddenStatus} product -> 422", 422, $hiddenQuote['status']);
    $t->ok('and it names neither the product nor its status', !str_contains($hiddenQuote['body'], $productName) && !str_contains($hiddenQuote['body'], $hiddenStatus), $hiddenQuote['body']);
}
$restoreProduct();
$unknownQuote = $t->http('POST', '/checkout/quote', ['json' => ['items' => [['product_id' => 99999999, 'quantity' => 1]]]]);
$t->check('a product ID that does not exist gets the same answer', TestKit::get($hiddenQuote, 'error.message'), TestKit::get($unknownQuote, 'error.message'));
$quote = fn (string $state) => $t->http('POST', '/checkout/quote', ['json' => [
    'items' => [['product_id' => $pid, 'variant_id' => $vid, 'quantity' => 1]],
    'shipping_address' => ['state' => $state, 'country' => 'Nigeria'],
]]);
$abujaZone = (string) $t->scalar("SELECT `name` FROM `shipping_zones` WHERE `is_active` = 1 AND (JSON_CONTAINS(`states_covered`, '\"FCT\"') OR JSON_CONTAINS(`states_covered`, '\"Abuja\"')) LIMIT 1");
$t->check('FCT resolves to the Abuja zone, not the nationwide fallback', $abujaZone, TestKit::get($quote('FCT'), 'data.shipping_zone.name'));
$t->check('"Federal Capital Territory" is the same place', $abujaZone, TestKit::get($quote('Federal Capital Territory'), 'data.shipping_zone.name'));
$bad = $quote('Lagoss');
$t->check('misspelt state is refused', false, TestKit::get($bad, 'success'));
$t->check('misspelt state -> 422', 422, $bad['status']);

// -----------------------------------------------------------------------------
$t->section('Guest checkout: idempotent, stock reserved once, payment attempt');
$email = $t->uniq('t-store-guest') . '@example.test';
$emails[] = $email;
$key = 'store' . bin2hex(random_bytes(12));
$orderBody = ['email' => $email, 'items' => [['product_id' => $pid, 'variant_id' => $vid, 'quantity' => 1]], 'shipping_address' => $address('Store Flow', 'FCT', 'Garki')];
$first = $t->http('POST', '/checkout/orders', ['headers' => ["Idempotency-Key: {$key}"], 'json' => $orderBody]);
$t->check('order created 201', 201, $first['status']);
$onum = (string) TestKit::get($first, 'data.order_number');
$ordersCreated[] = $onum;
$second = $t->http('POST', '/checkout/orders', ['headers' => ["Idempotency-Key: {$key}"], 'json' => $orderBody]);
$t->check('double submit returns the same order', $onum, TestKit::get($second, 'data.order_number'));
$token = (string) TestKit::get($second, 'data.guest_access_token');
$t->ok('replay issues a usable guest token', $token !== '');
$t->check('stock reserved once', $stock - 1, (int) $t->scalar("SELECT `stock_quantity` FROM `product_variants` WHERE `id` = ?", 'i', [$vid]));
$t->check('one order row for the key', 1, (int) $t->scalar("SELECT COUNT(*) FROM `orders` WHERE `idempotency_key` = ?", 's', [$key]));
$t->check('no variant -> order refused', 422, $t->http('POST', '/checkout/orders', ['json' => ['email' => $email, 'items' => [['product_id' => $pid, 'quantity' => 1]], 'shipping_address' => $address('Store Flow', 'FCT', 'Garki')]])['status']);
$t->check('verify before paying: not_started', 'not_started', TestKit::get($t->http('GET', "/checkout/orders/{$onum}/verify-payment", ['headers' => ["X-Guest-Access-Token: {$token}"]]), 'data.status'));
$t->check('guest token opens the order', 200, $t->http('GET', "/checkout/orders/{$onum}", ['headers' => ["X-Guest-Access-Token: {$token}"]])['status']);
$t->check('full checkout email opens the order', 200, $t->http('GET', "/checkout/orders/{$onum}", ['headers' => ["X-Order-Email: {$email}"]])['status']);
$t->check('the receipt page can read the payment status with the checkout email', 200, $t->http('GET', "/checkout/orders/{$onum}/verify-payment", ['headers' => ["X-Order-Email: {$email}"]])['status']);
$t->check('but not with a wrong email', 404, $t->http('GET', "/checkout/orders/{$onum}/verify-payment", ['headers' => ['X-Order-Email: wrong@example.test']])['status']);
// Email checks share the order lookup's failure limit (20 per 15 minutes per address).
$guesser = ['X-Forwarded-For: 203.0.113.250'];
for ($i = 0; $i < 20; $i++) {
    $t->http('GET', "/checkout/orders/{$onum}/verify-payment", ['headers' => array_merge($guesser, ["X-Order-Email: guess{$i}@example.test"])]);
}
$t->check('after 20 wrong emails the address is paused, even for the right email', 429, $t->http('GET', "/checkout/orders/{$onum}/verify-payment", ['headers' => array_merge($guesser, ["X-Order-Email: {$email}"])])['status']);
$t->check('and the pause covers the order lookup too', 429, $t->http('GET', "/checkout/orders/{$onum}", ['headers' => array_merge($guesser, ["X-Order-Email: {$email}"])])['status']);
$wa = $t->http('POST', "/checkout/orders/{$onum}/initialize-payment", ['headers' => ["X-Guest-Access-Token: {$token}"], 'json' => ['gateway' => 'whatsapp']]);
$t->check('WhatsApp payment attempt opens', 'whatsapp', TestKit::get($wa, 'data.provider'));
$heldMinutes = (int) $t->scalar("SELECT TIMESTAMPDIFF(MINUTE, NOW(), `expires_at`) FROM `orders` WHERE `order_number` = ?", 's', [$onum]);
$manualWindow = \App\Services\PaymentAttempts::manualWindowMinutes();
$t->ok('WhatsApp attempt reserves the order for the transfer window (6 hours unless the server says otherwise)', $heldMinutes >= $manualWindow - 2 && $heldMinutes <= $manualWindow, "minutes={$heldMinutes}, window={$manualWindow}");
$t->check('on another device, the checkout email starts a payment (the order emails link to tracking)', 'whatsapp', TestKit::get($t->http('POST', "/checkout/orders/{$onum}/initialize-payment", ['headers' => ["X-Order-Email: " . strtoupper($email)], 'json' => ['gateway' => 'whatsapp']]), 'data.provider'));
$t->check('a wrong email cannot', 404, $t->http('POST', "/checkout/orders/{$onum}/initialize-payment", ['headers' => ['X-Order-Email: someone@example.test'], 'json' => ['gateway' => 'whatsapp']])['status']);
$numEmail = $t->uniq('t-store-numbers') . '@example.test';
$emails[] = $numEmail;
$numbers = $t->http('POST', '/checkout/orders', ['headers' => ['Idempotency-Key: num' . bin2hex(random_bytes(12))], 'json' => ['email' => $numEmail, 'items' => [['product_id' => $pid, 'variant_id' => $vid, 'quantity' => 1]], 'shipping_address' => ['postal_code' => 100001] + $address('Number Fields', 'FCT', 'Garki')]]);
$t->check('a number in a text field (postal code 100001) is accepted, not a server error', 201, $numbers['status']);
$ordersCreated[] = (string) TestKit::get($numbers, 'data.order_number');
// Give its unit back at once, so the stock counts checked further down are unchanged.
$t->http('POST', '/checkout/orders/' . TestKit::get($numbers, 'data.order_number') . '/release', ['headers' => ['X-Guest-Access-Token: ' . TestKit::get($numbers, 'data.guest_access_token')], 'json' => []]);
$t->check('a list where text belongs gets a clear 422, not a server error', 422, $t->http('POST', '/checkout/orders', ['json' => ['email' => $numEmail, 'items' => [['product_id' => $pid, 'variant_id' => $vid, 'quantity' => 1]], 'shipping_address' => ['full_name' => ['x']] + $address('', 'FCT', 'Garki')]])['status']);
$pinOrder = static fn (array $location) => $t->http('POST', '/checkout/orders', ['headers' => ['Idempotency-Key: pin' . bin2hex(random_bytes(12))], 'json' => ['email' => $numEmail, 'items' => [['product_id' => $pid, 'variant_id' => $vid, 'quantity' => 1]], 'shipping_address' => ['location' => $location] + $address('Pin Test', 'Lagos', 'Ikeja')]]);
$pinSnapshot = static function (array $res) use ($t): ?array {
    $num = (string) TestKit::get($res, 'data.order_number');
    $t->http('POST', "/checkout/orders/{$num}/release", ['headers' => ['X-Guest-Access-Token: ' . TestKit::get($res, 'data.guest_access_token')], 'json' => []]);
    $snap = json_decode((string) $t->scalar("SELECT `shipping_address_snapshot` FROM `orders` WHERE `order_number` = ?", 's', [$num]), true);
    return $snap['location'] ?? null;
};
$t->check('a delivery pin shared at checkout is kept with the address', ['latitude' => 6.5244, 'longitude' => 3.3792, 'accuracy_m' => 12], $pinSnapshot($pinOrder(['latitude' => 6.5244, 'longitude' => 3.3792, 'accuracy_m' => 12.4])));
$t->check('a nonsense pin is dropped (the order still goes through)', null, $pinSnapshot($pinOrder(['latitude' => 'abc', 'longitude' => 999])));

$t->section('"Mark as paid" for a WhatsApp transfer alerts the store, never pays the order');
$t->check('mark as paid without access -> 404', 404, $t->http('POST', "/checkout/orders/{$onum}/payment-sent", ['json' => []])['status']);
$t->check('mark as paid with a wrong email -> 404', 404, $t->http('POST', "/checkout/orders/{$onum}/payment-sent", ['headers' => ['X-Order-Email: wrong@example.test'], 'json' => []])['status']);
$claim = $t->http('POST', "/checkout/orders/{$onum}/payment-sent", ['headers' => ["X-Guest-Access-Token: {$token}"], 'json' => []]);
$t->check('mark as paid 200', 200, $claim['status']);
$t->check('claim recorded', true, TestKit::get($claim, 'data.recorded'));
$t->ok('claim time stored on the order', $t->scalar("SELECT `customer_marked_paid_at` FROM `orders` WHERE `order_number` = ?", 's', [$onum]) !== null);
$t->check('order still awaiting payment', 'pending_payment', $t->scalar("SELECT `order_status` FROM `orders` WHERE `order_number` = ?", 's', [$onum]));
$afterClaim = $t->http('GET', "/checkout/orders/{$onum}/verify-payment", ['headers' => ["X-Guest-Access-Token: {$token}"]]);
$t->check('payment still unconfirmed', 'pending', TestKit::get($afterClaim, 'data.status'));
$t->check('the payment page is told the customer reported paying (it shows "Awaiting confirmation")', true, TestKit::get($afterClaim, 'data.customer_marked_paid'));
// A card attempt that failed after the transfer was reported must not hide the reported transfer.
$failedId = (int) $t->db->insert(
    "INSERT INTO `payments` (`order_id`, `provider`, `provider_reference`, `amount`, `currency`, `status`) SELECT `id`, 'kora', ?, `grand_total`, 'NGN', 'failed' FROM `orders` WHERE `order_number` = ?",
    'ss',
    ['KPY-FAILED-' . bin2hex(random_bytes(4)), $onum]
);
$afterFailed = $t->http('GET', "/checkout/orders/{$onum}/verify-payment", ['headers' => ["X-Guest-Access-Token: {$token}"]]);
$t->check('a later failed card attempt does not hide the reported WhatsApp transfer', ['whatsapp', 'pending'], [TestKit::get($afterFailed, 'data.provider'), TestKit::get($afterFailed, 'data.status')]);
$t->sql("DELETE FROM `payments` WHERE `id` = ?", 'i', [$failedId]);
$adminEmail = strtolower((string) $t->scalar("SELECT `email` FROM `users` WHERE `id` = ?", 'i', [(int) $admin['id']]));
$t->check('store admin emailed', 1, (int) $t->scalar("SELECT COUNT(*) FROM `email_outbox` WHERE `template` = 'payment_claimed' AND `recipient_email` = ? AND `dedup_key` LIKE ?", 'ss', [$adminEmail, "payment_claimed:{$onum}:%"]));
$t->check('history notes the claim', 1, (int) $t->scalar("SELECT COUNT(*) FROM `order_status_history` h JOIN `orders` o ON o.id = h.order_id WHERE o.order_number = ? AND h.comment LIKE 'Customer marked%'", 's', [$onum]));
$t->sql("UPDATE `orders` SET `expires_at` = DATE_ADD(NOW(), INTERVAL 2 HOUR) WHERE `order_number` = ?", 's', [$onum]);
$expiresBefore = (string) $t->scalar("SELECT `expires_at` FROM `orders` WHERE `order_number` = ?", 's', [$onum]);
$t->check('tapping again: 200', 200, $t->http('POST', "/checkout/orders/{$onum}/payment-sent", ['headers' => ["X-Guest-Access-Token: {$token}"], 'json' => []])['status']);
$t->check('tapping again does not hold the stock longer', $expiresBefore, (string) $t->scalar("SELECT `expires_at` FROM `orders` WHERE `order_number` = ?", 's', [$onum]));
$t->check('tapping again sends no second email', 1, (int) $t->scalar("SELECT COUNT(*) FROM `email_outbox` WHERE `template` = 'payment_claimed' AND `recipient_email` = ? AND `dedup_key` LIKE ?", 'ss', [$adminEmail, "payment_claimed:{$onum}:%"]));
$claimedList = $t->http('GET', '/admin/orders?status=payment_claimed&per_page=100', ['jar' => $admin['jar']]);
$t->ok('admin "Customer says paid" list shows the order', in_array($onum, array_column((array) TestKit::get($claimedList, 'data.items'), 'order_number'), true));
$t->ok('admin order shows when it was marked', TestKit::get($t->http('GET', "/admin/orders/{$onum}", ['jar' => $admin['jar']]), 'data.customer_marked_paid_at') !== null);

// -----------------------------------------------------------------------------
$t->section('Payment windows are published for the storefront text');
$pm = $t->http('GET', '/checkout/payment-methods');
$t->check('online payment window in minutes', \App\Services\PaymentAttempts::gatewayWindowMinutes(), TestKit::get($pm, 'data.payment_window_minutes'));
$t->check('WhatsApp transfer window in hours', intdiv(\App\Services\PaymentAttempts::manualWindowMinutes(), 60), TestKit::get($pm, 'data.manual_payment_window_hours'));

// -----------------------------------------------------------------------------
$t->section('"Change delivery details" releases an order no payment was started for');
$t->check('an order with a payment attempt is not released', false, TestKit::get(
    $t->http('POST', "/checkout/orders/{$onum}/release", ['headers' => ["X-Guest-Access-Token: {$token}"], 'json' => []]), 'data.released'
));
$t->check('it stays awaiting payment', 'pending_payment', $t->scalar("SELECT `order_status` FROM `orders` WHERE `order_number` = ?", 's', [$onum]));
$stockBeforeLeft = (int) $t->scalar("SELECT `stock_quantity` FROM `product_variants` WHERE `id` = ?", 'i', [$vid]);
$leftEmail = $t->uniq('t-store-left') . '@example.test';
$emails[] = $leftEmail;
$left = $t->http('POST', '/checkout/orders', ['headers' => ['Idempotency-Key: left' . bin2hex(random_bytes(12))], 'json' => ['email' => $leftEmail] + $orderBody]);
$leftNum = (string) TestKit::get($left, 'data.order_number');
$ordersCreated[] = $leftNum;
$leftToken = (string) TestKit::get($left, 'data.guest_access_token');
$t->check('the order reserved one unit', $stockBeforeLeft - 1, (int) $t->scalar("SELECT `stock_quantity` FROM `product_variants` WHERE `id` = ?", 'i', [$vid]));
$t->check('release without access -> 404', 404, $t->http('POST', "/checkout/orders/{$leftNum}/release", ['json' => []])['status']);
$t->check('release with the access key', true, TestKit::get(
    $t->http('POST', "/checkout/orders/{$leftNum}/release", ['headers' => ["X-Guest-Access-Token: {$leftToken}"], 'json' => []]), 'data.released'
));
$t->check('released order is cancelled', 'cancelled', $t->scalar("SELECT `order_status` FROM `orders` WHERE `order_number` = ?", 's', [$leftNum]));
$t->check('its unit is back on sale at once', $stockBeforeLeft, (int) $t->scalar("SELECT `stock_quantity` FROM `product_variants` WHERE `id` = ?", 'i', [$vid]));
$t->check('no cancellation email for a released order', 0, (int) $t->scalar("SELECT COUNT(*) FROM `email_outbox` WHERE `dedup_key` = ?", 's', ["order_cancelled:{$leftNum}"]));
$t->check('releasing again changes nothing', false, TestKit::get(
    $t->http('POST', "/checkout/orders/{$leftNum}/release", ['headers' => ["X-Guest-Access-Token: {$leftToken}"], 'json' => []]), 'data.released'
));
$t->check('a released order cannot start a payment', 422, $t->http('POST', "/checkout/orders/{$leftNum}/initialize-payment", ['headers' => ["X-Guest-Access-Token: {$leftToken}"], 'json' => ['gateway' => 'whatsapp']])['status']);

// -----------------------------------------------------------------------------
$t->section('Delivery details: the same rules as the checkout form');
$t->sql('DELETE FROM `rate_limits`');
$placeWith = static fn (array $change, array $extra = []): array => $t->http('POST', '/checkout/orders', ['json' => $extra + [
    'email' => $email,
    'items' => [['product_id' => $pid, 'variant_id' => $vid, 'quantity' => 1]],
    'shipping_address' => $change + $address('Store Flow', 'FCT', 'Garki'),
]]);
$ordersBefore = (int) $t->scalar('SELECT COUNT(*) FROM `orders`');
foreach ([
    'full_name' => ['.', 'A', '12 34', 'A.'],
    'phone' => ['1', '0803123456', '080312345678', '803 123 4567', '+23480312345', '+44 20 7946 0958', 'abc'],
    'address_line1' => ['.', 'ab', '  a ', '...', '-- --'],
    'city' => ['.', 'ab', '...'],
] as $field => $badValues) {
    foreach ($badValues as $bad) {
        $res = $placeWith([$field => $bad]);
        $t->check("{$field} '{$bad}' -> 422", 422, $res['status']);
        $t->ok('naming the field the way the form reads it', is_string(TestKit::get($res, 'error.details') ? ($res['json']['error']['details']["shipping_address.{$field}"] ?? null) : null), $res['body']);
    }
    $t->sql('DELETE FROM `rate_limits`');
}
$t->check('a missing field names itself too', 'Contact phone number is required.', $placeWith(['phone' => ''])['json']['error']['details']['shipping_address.phone'] ?? null);
$t->check('no order was made', $ordersBefore, (int) $t->scalar('SELECT COUNT(*) FROM `orders`'));
$t->check('over-long notes -> 422', [422, true], (static function () use ($placeWith): array {
    $res = $placeWith([], ['notes' => str_repeat('n', 2001)]);
    return [$res['status'], TestKit::get($res, 'error.details.notes') !== null];
})());
$t->sql('DELETE FROM `rate_limits`');
foreach ([
    ['full_name' => 'Ọ Ụ', 'phone' => '0803 123-4567', 'address_line1' => '1 A', 'city' => 'Ife'],
    ['full_name' => 'Ada', 'phone' => '+234 803 123 4567', 'address_line1' => 'Plot 3', 'city' => 'Aba'],
    ['full_name' => 'Ada Obi', 'phone' => '(0803) 123 4567', 'address_line1' => 'Plot 3', 'city' => 'Aba'],
] as $good) {
    $res = $placeWith($good, ['notes' => str_repeat('n', 2000)]);
    $t->check("good details ({$good['phone']}) are accepted", 201, $res['status']);
    $ordersCreated[] = (string) TestKit::get($res, 'data.order_number');
    $t->check('and saved in one format however typed', '+2348031234567', json_decode((string) $t->scalar('SELECT `shipping_address_snapshot` FROM `orders` WHERE `order_number` = ?', 's', [TestKit::get($res, 'data.order_number')]), true)['phone'] ?? null);
    $t->http('POST', '/checkout/orders/' . TestKit::get($res, 'data.order_number') . '/release', ['headers' => ['X-Guest-Access-Token: ' . TestKit::get($res, 'data.guest_access_token')], 'json' => []]);
}
$t->check('the form\'s quote needs only the state', 200, $quote('Lagos')['status']);
$t->sql('DELETE FROM `rate_limits`');

// -----------------------------------------------------------------------------
$t->section('A database failure is never shown to the customer');
$leakChecks = static function (array $res, string $table) use ($t): void {
    $t->check("no database details in the answer ({$table} missing)", 500, $res['status']);
    $t->ok('nothing about tables or the database', !preg_match("/{$table}|table|sql|" . preg_quote((string) \App\Config\Env::get('DB_DATABASE', ''), '/') . '/i', $res['body']), $res['body']);
};
$hidden = static function (string $table, callable $request) use ($t): array {
    $t->sql("RENAME TABLE `{$table}` TO `{$table}_hidden`");
    try {
        return $request();
    } finally {
        $t->sql("RENAME TABLE `{$table}_hidden` TO `{$table}`");
    }
};
$leakChecks($hidden('shipping_zones', static fn (): array => $quote('Lagos')), 'shipping_zones');
$unpaid = $placeWith([]);
$unpaidNum = (string) TestKit::get($unpaid, 'data.order_number');
$unpaidAccess = ['X-Guest-Access-Token: ' . TestKit::get($unpaid, 'data.guest_access_token')];
$ordersCreated[] = $unpaidNum;
$leakChecks($hidden('payments', static fn (): array => $t->http('POST', "/checkout/orders/{$unpaidNum}/initialize-payment", ['headers' => $unpaidAccess, 'json' => ['gateway' => 'whatsapp']])), 'payments');
$t->check('the order is still there to pay', 'pending_payment', $t->scalar("SELECT `order_status` FROM `orders` WHERE `order_number` = ?", 's', [$unpaidNum]));
$t->check('released', true, TestKit::get($t->http('POST', "/checkout/orders/{$unpaidNum}/release", ['headers' => $unpaidAccess, 'json' => []]), 'data.released'));

// -----------------------------------------------------------------------------
$t->section('Magic link links guest orders; saved cart and wishlist');
$t->check('magic link requested', 200, $t->http('POST', '/auth/customer/magic-link', ['json' => ['email' => $email]])['status']);
$link = $t->magicLinkToken($email);
$t->ok('magic link delivered to the (test) mail log', $link !== null);
$verify = $t->http('POST', '/auth/customer/verify', ['jar' => 'buyer', 'json' => ['token' => (string) $link]]);
$t->check('magic link verifies', 200, $verify['status']);
$csrf = (string) TestKit::get($verify, 'data.csrf_token');
$t->check('magic link cannot be reused', 401, $t->http('POST', '/auth/customer/verify', ['json' => ['token' => (string) $link]])['status']);
$myOrders = $t->http('GET', '/customer/orders', ['jar' => 'buyer']);
$t->ok('guest order now appears under My orders', in_array($onum, array_column((array) TestKit::get($myOrders, 'data'), 'order_number'), true));
$myOrder = array_values(array_filter((array) TestKit::get($myOrders, 'data'), static fn (array $o): bool => $o['order_number'] === $onum))[0] ?? [];
$t->ok('My orders knows the customer said they sent the transfer', !empty($myOrder['customer_marked_paid_at']));
$saved = $t->http('PUT', '/customer/cart', ['jar' => 'buyer', 'csrf' => $csrf, 'json' => ['items' => [['variant_id' => $vid, 'quantity' => 2], ['variant_id' => 999999, 'quantity' => 1]]]]);
$t->check('save cart 200', 200, $saved['status']);
$t->check('saved cart drops unknown variants', 1, count((array) TestKit::get($saved, 'data.items')));
$t->check('saved cart round-trips', 2, (int) TestKit::get($t->http('GET', '/customer/cart', ['jar' => 'buyer']), 'data.items.0.quantity'));
$t->check('cart quantity capped at 20 per line', 20, (int) TestKit::get($t->http('PUT', '/customer/cart', ['jar' => 'buyer', 'csrf' => $csrf, 'json' => ['items' => [['variant_id' => $vid, 'quantity' => 500]]]]), 'data.items.0.quantity'));
$t->check('save wishlist 200', 200, $t->http('PUT', '/customer/wishlist', ['jar' => 'buyer', 'csrf' => $csrf, 'json' => ['product_ids' => [$pid]]])['status']);
$t->check('wishlist returns product data', $slug, TestKit::get($t->http('GET', '/customer/wishlist', ['jar' => 'buyer']), 'data.products.0.slug'));
$t->check('cart save without CSRF -> 403', 403, $t->http('PUT', '/customer/cart', ['jar' => 'buyer', 'json' => ['items' => []]])['status']);

$t->section('Cancelling releases the stock');
$cancel = $t->http('PATCH', "/admin/orders/{$onum}/status", ['jar' => $admin['jar'], 'csrf' => $admin['csrf'], 'json' => ['status' => 'cancelled', 'comment' => 'Storefront test cleanup']]);
$t->check('admin cancels the unpaid order', 200, $cancel['status']);
$t->check('cancelling restores stock', $stock, (int) $t->scalar("SELECT `stock_quantity` FROM `product_variants` WHERE `id` = ?", 'i', [$vid]));
$claimedAfter = $t->http('GET', '/admin/orders?status=payment_claimed&per_page=100', ['jar' => $admin['jar']]);
$t->ok('a cancelled claim leaves the "Customer says paid" list', !in_array($onum, array_column((array) TestKit::get($claimedAfter, 'data.items'), 'order_number'), true));
$t->check('customer then sees the order cancelled', 'cancelled', TestKit::get($t->http('GET', "/checkout/orders/{$onum}/verify-payment", ['headers' => ["X-Guest-Access-Token: {$token}"]]), 'data.order_status'));
$t->check('mark as paid after cancelling records nothing', false, TestKit::get($t->http('POST', "/checkout/orders/{$onum}/payment-sent", ['headers' => ["X-Guest-Access-Token: {$token}"], 'json' => []]), 'data.recorded'));

// -----------------------------------------------------------------------------
$t->section('Reviews: moderated, verified purchase decided by the server');
$buyer = $t->uniq('t-store-reviewer') . '@example.test';
$stranger = $t->uniq('t-store-stranger') . '@example.test';
array_push($emails, $buyer, $stranger);
$paidOrder = $t->http('POST', '/checkout/orders', ['headers' => ['Idempotency-Key: rv' . bin2hex(random_bytes(10))], 'json' => [
    'email' => $buyer, 'items' => [['product_id' => $pid, 'variant_id' => $vid, 'quantity' => 1]], 'shipping_address' => $address('Review Buyer', 'Enugu', 'Enugu'),
]]);
$paidNum = (string) TestKit::get($paidOrder, 'data.order_number');
$ordersCreated[] = $paidNum;
$t->check('buyer order paid (manual confirmation)', 200, $t->http('POST', "/admin/orders/{$paidNum}/confirm-payment", ['jar' => $admin['jar'], 'csrf' => $admin['csrf'], 'json' => ['reference' => 'RV-' . bin2hex(random_bytes(4))]])['status']);

$invalid = $t->http('POST', "/products/{$slug}/reviews", ['json' => ['author_name' => '', 'email' => 'x', 'rating' => 9, 'comment' => 'short']]);
$t->check('invalid review -> 422', 422, $invalid['status']);
$t->ok('field errors returned', isset($invalid['json']['error']['details']['rating'], $invalid['json']['error']['details']['comment']));
$strangerReview = $t->http('POST', "/products/{$slug}/reviews", ['json' => ['author_name' => 'Stranger', 'email' => $stranger, 'rating' => 4, 'comment' => 'Looks nice in the photos, honestly.']]);
$t->check('review accepted 201', 201, $strangerReview['status']);
$t->check('review is pending', 'pending', TestKit::get($strangerReview, 'data.status'));
$t->check('no paid order -> not a verified purchase', false, TestKit::get($strangerReview, 'data.verified_purchase'));
$t->check('second review from the same email -> 409', 409, $t->http('POST', "/products/{$slug}/reviews", ['json' => ['author_name' => 'Stranger', 'email' => $stranger, 'rating' => 5, 'comment' => 'Trying to review it twice here.']])['status']);
$typedBuyer = $t->http('POST', "/products/{$slug}/reviews", ['json' => ['author_name' => 'Someone Else', 'email' => strtoupper($buyer), 'rating' => 1, 'comment' => 'Typing a buyer email proves nothing.']]);
$t->check("signed out, typing a buyer's email -> accepted but not a verified purchase", [201, false], [$typedBuyer['status'], TestKit::get($typedBuyer, 'data.verified_purchase')]);
$t->http('POST', '/auth/customer/magic-link', ['json' => ['email' => $buyer]]);
$buyerSession = $t->http('POST', '/auth/customer/verify', ['jar' => 'rvbuyer', 'json' => ['token' => (string) $t->magicLinkToken($buyer)]]);
$buyerReview = $t->http('POST', "/products/{$slug}/reviews", ['jar' => 'rvbuyer', 'csrf' => (string) TestKit::get($buyerSession, 'data.csrf_token'), 'json' => ['author_name' => 'Review Buyer', 'rating' => 5, 'comment' => 'Bought it, great quality and fit.']]);
$t->check('the signed-in buyer is not blocked by that review', 201, $buyerReview['status']);
$t->check('and their paid order for this product -> verified purchase', true, TestKit::get($buyerReview, 'data.verified_purchase'));
$t->check('client cannot force verified (stored flag)', 0, (int) $t->scalar("SELECT `is_verified_purchase` FROM `reviews` WHERE `author_email` = ?", 's', [$stranger]));
$pdpAfter = $t->http('GET', "/products/{$slug}");
$t->check('pending reviews are not shown on the product', 0, count(array_filter((array) TestKit::get($pdpAfter, 'data.reviews'), static fn ($r) => in_array($r['author'] ?? '', ['Stranger', 'Review Buyer'], true))));
$t->check('pending reviews do not change the rating count', $ratingCountBefore, (int) TestKit::get($pdpAfter, 'data.rating_count'));

// -----------------------------------------------------------------------------
$t->section('Homepage numbers are real');
$home = $t->http('GET', '/storefront/homepage');
$t->check('homepage 200', 200, $home['status']);
$t->check('published products = database count', (int) $t->scalar("SELECT COUNT(*) FROM `products` WHERE `status` = 'published' AND `product_type` = 'physical'"), (int) TestKit::get($home, 'data.stats.published_products'));
$t->check('categories = active categories', (int) $t->scalar("SELECT COUNT(*) FROM `categories` WHERE `is_active` = 1"), (int) TestKit::get($home, 'data.stats.categories'));
$hasFallback = (int) $t->scalar("SELECT COUNT(*) FROM `shipping_zones` WHERE `is_active` = 1 AND JSON_CONTAINS(`states_covered`, '\"*\"')") > 0;
if ($hasFallback) {
    $t->check('a nationwide zone means all 37 states are served', 37, (int) TestKit::get($home, 'data.stats.states_served'));
}

// -----------------------------------------------------------------------------
$t->section('Flash deals end when their time is up');
$originalFlash = $t->sql("SELECT `id`, `is_flash_deal`, `flash_deal_ends_at` FROM `products` WHERE `is_flash_deal` = 1 OR `id` = ?", 'i', [$pid]);
$t->cleanup(function () use ($t, $originalFlash, $pid): void {
    $t->sql("UPDATE `products` SET `is_flash_deal` = 0, `flash_deal_ends_at` = NULL WHERE `id` = ?", 'i', [$pid]);
    foreach ($originalFlash as $row) {
        $t->sql("UPDATE `products` SET `is_flash_deal` = ?, `flash_deal_ends_at` = ? WHERE `id` = ?", 'isi', [(int) $row['is_flash_deal'], $row['flash_deal_ends_at'], (int) $row['id']]);
    }
});
$t->sql("UPDATE `products` SET `is_flash_deal` = 0, `flash_deal_ends_at` = NULL WHERE `is_flash_deal` = 1");
$past = date('Y-m-d H:i:s', time() - 3600);
$t->sql("UPDATE `products` SET `is_flash_deal` = 1, `flash_deal_ends_at` = ? WHERE `id` = ?", 'si', [$past, $pid]);
$slugsIn = static fn (array $res, string $path): array => array_column((array) TestKit::get($res, $path), 'slug');
$t->ok('expired deal not in /products?flash_deals=1', !in_array($slug, $slugsIn($t->http('GET', '/products?flash_deals=1&per_page=100'), 'data'), true));
$homeExpired = $t->http('GET', '/storefront/homepage');
$t->ok('expired deal not on the homepage', !in_array($slug, $slugsIn($homeExpired, 'data.flash_deals'), true));
$t->check('no countdown when no live deal has an end', null, TestKit::get($homeExpired, 'data.flash_deals_end_at'));
$future = date('Y-m-d H:i:s', time() + 2 * 86400);
$t->sql("UPDATE `products` SET `flash_deal_ends_at` = ? WHERE `id` = ?", 'si', [$future, $pid]);
$t->ok('live deal listed in /products?flash_deals=1', in_array($slug, $slugsIn($t->http('GET', '/products?flash_deals=1&per_page=100'), 'data'), true));
$homeLive = $t->http('GET', '/storefront/homepage');
$t->ok('live deal on the homepage', in_array($slug, $slugsIn($homeLive, 'data.flash_deals'), true));
$t->check('homepage countdown uses the real end time', $future, TestKit::get($homeLive, 'data.flash_deals_end_at'));

// -----------------------------------------------------------------------------
$t->section('Newsletter');
$sub = $t->uniq('t-store-news') . '@example.test';
$emails[] = $sub;
$t->check('subscribe 200', 200, $t->http('POST', '/newsletter/subscribe', ['json' => ['email' => $sub]])['status']);
$t->check('invalid email refused', 422, $t->http('POST', '/newsletter/subscribe', ['json' => ['email' => 'not-an-email']])['status']);
$unsubToken = (string) $t->scalar("SELECT `unsubscribe_token` FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$sub]);
$t->check('subscriber gets an unsubscribe token', 32, strlen($unsubToken));
$t->check('bad unsubscribe token refused', 404, $t->http('POST', '/newsletter/unsubscribe', ['json' => ['token' => str_repeat('0', 32)]])['status']);
$t->check('unsubscribe 200', 200, $t->http('POST', '/newsletter/unsubscribe', ['json' => ['token' => $unsubToken]])['status']);
$t->check('subscriber marked unsubscribed', 'unsubscribed', $t->scalar("SELECT `status` FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$sub]));
$t->check('subscribing again re-activates', 200, $t->http('POST', '/newsletter/subscribe', ['json' => ['email' => $sub]])['status']);
$t->check('re-activated subscriber is active', 'active', $t->scalar("SELECT `status` FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$sub]));
$t->check('unsubscribe date cleared on re-subscribe', null, $t->scalar("SELECT `unsubscribed_at` FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$sub]));

// -----------------------------------------------------------------------------
$t->section('Tracking codes with spaces and dots can be looked up; slashes are refused when saved');
$t->sql('DELETE FROM `rate_limits`');
$tracked = $placeWith([]);
$trackedNum = (string) TestKit::get($tracked, 'data.order_number');
$ordersCreated[] = $trackedNum;
(new \App\Services\PaymentFinalizer($t->db))->recordManualPayment((int) TestKit::get($tracked, 'data.order_id'), 'TRACK-' . bin2hex(random_bytes(4)), null);
$ship = static fn (string $code): array => $t->http('POST', "/admin/orders/{$trackedNum}/shipment", ['jar' => $admin['jar'], 'csrf' => $admin['csrf'], 'json' => ['carrier' => 'GIG', 'tracking_number' => $code]]);
$trackedAccess = ['headers' => ["X-Order-Email: {$email}"]];
$lookup = static fn (string $code): array => $t->http('GET', '/checkout/orders/' . rawurlencode($code), $trackedAccess);
$withSpace = 'TRK 12-' . bin2hex(random_bytes(3));
$t->check('a code with a space is saved', 200, $ship($withSpace)['status']);
$t->check('and finds its order', $trackedNum, TestKit::get($lookup($withSpace), 'data.order_number'));
$t->check('a code with a slash is refused with a clear reason', [422, true], (static function () use ($ship): array {
    $res = $ship('AB/12');
    return [$res['status'], str_contains((string) TestKit::get($res, 'error.message'), 'slash')];
})());
$t->check('as is a backslash', 422, $ship('AB\\12')['status']);
$t->check('and the saved code is unchanged', $withSpace, $t->scalar("SELECT `tracking_number` FROM `shipments` s JOIN `orders` o ON o.`id` = s.`order_id` WHERE o.`order_number` = ?", 's', [$trackedNum]));
$t->check('without the checkout email a tracking code opens nothing', 404, $t->http('GET', '/checkout/orders/' . rawurlencode($withSpace))['status']);
foreach (['..%2F..%2Fadmin', '%FF%FE', 'TRK%00'] as $oddCode) {
    $t->check("an odd code ({$oddCode}) is simply not found", 404, $t->http('GET', "/checkout/orders/{$oddCode}", $trackedAccess)['status']);
}
$t->check('the other order routes stay narrow', 404, $t->http('POST', '/checkout/orders/TRK%2012/release', ['json' => []])['status']);

// -----------------------------------------------------------------------------
$t->section('A shop with nothing set up: no method is offered that could only fail');
$lanePort = (int) (getenv('TEST_FLOW_API_PORT') ?: 8120);
$laneDir = sys_get_temp_dir() . '/shopco-flow-' . getmypid();
@mkdir($laneDir, 0700, true);
$laneEnv = "{$laneDir}/env";
$unset = ['MONNIFY_CONTRACT_CODE', 'STORE_WHATSAPP_PHONE', 'KORA_SECRET_KEY', 'KORA_API_BASE_URL'];
file_put_contents($laneEnv, implode("\n", array_merge(
    array_values(array_filter(file((string) getenv('SHOPCO_ENV_FILE'), FILE_IGNORE_NEW_LINES) ?: [], static fn (string $l): bool => !in_array(trim((string) strstr($l, '=', true)), $unset, true))),
    ['MONNIFY_CONTRACT_CODE=', 'STORE_WHATSAPP_PHONE=', 'KORA_SECRET_KEY=']
)) . "\n");
$startLane = static function (string $envFile) use ($lanePort, $laneDir): mixed {
    $proc = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$lanePort}", '-t', BASE_PATH . '/public', BASE_PATH . '/dev-router.php'],
        [1 => ['file', "{$laneDir}/api.log", 'a'], 2 => ['file', "{$laneDir}/api.log", 'a']],
        $pipes,
        BASE_PATH,
        array_merge(getenv(), ['SHOPCO_ENV_FILE' => $envFile])
    );
    for ($i = 0; $i < 50 && @file_get_contents("http://127.0.0.1:{$lanePort}/api/v1/health") === false; $i++) {
        usleep(200000);
    }
    return $proc;
};
$stopLane = static function (mixed $proc): void {
    proc_terminate($proc);
    proc_close($proc);
    usleep(300000);
};
$oldNumber = $t->scalar("SELECT `value` FROM `store_settings` WHERE `key_name` = 'store_whatsapp_phone'");
$t->sql("DELETE FROM `store_settings` WHERE `key_name` = 'store_whatsapp_phone'");
$t->cleanup(function () use ($t, $oldNumber, $laneDir): void {
    if ($oldNumber !== null) {
        $t->sql("INSERT INTO `store_settings` (`key_name`, `value`) VALUES ('store_whatsapp_phone', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", 's', [(string) $oldNumber]);
    }
    array_map('unlink', glob("{$laneDir}/*") ?: []);
    @rmdir($laneDir);
});
$lane = $startLane($laneEnv);
$laneApi = "http://127.0.0.1:{$lanePort}/api/v1";
$bare = $t->http('GET', "{$laneApi}/checkout/payment-methods");
$t->check('with no WhatsApp number, WhatsApp is reported off', false, TestKit::get($bare, 'data.whatsapp_enabled'));
$t->check('and no method without its keys or number is offered', [], TestKit::get($bare, 'data.methods'));
$t->sql('DELETE FROM `rate_limits`');
$bareOrder = $placeWith([]);
$bareNum = (string) TestKit::get($bareOrder, 'data.order_number');
$bareAccess = ['X-Guest-Access-Token: ' . TestKit::get($bareOrder, 'data.guest_access_token')];
$ordersCreated[] = $bareNum;
$barePay = static fn (string $gateway): array => $t->http('POST', "{$laneApi}/checkout/orders/{$bareNum}/initialize-payment", ['headers' => $bareAccess, 'json' => ['gateway' => $gateway]]);
foreach (['monnify', 'kora', 'whatsapp'] as $gateway) {
    $res = $barePay($gateway);
    $t->check("{$gateway} without what it needs is refused", [422, 'PAYMENT_METHOD_DISABLED'], [$res['status'], TestKit::get($res, 'error.code')]);
    $t->ok('and the customer is told nothing about the setup', !preg_match('/MONNIFY|KORA|\.env|contract|configured/i', $res['body']), $res['body']);
}
$dotted = 'TRK.5 ' . bin2hex(random_bytes(3));
$t->check('(a code with a dot is saved)', 200, $ship($dotted)['status']);
$t->check('a code with a dot finds its order', $trackedNum, TestKit::get($t->http('GET', "{$laneApi}/checkout/orders/" . rawurlencode($dotted), $trackedAccess), 'data.order_number'));
$stopLane($lane);

// A gateway that fails: the reason goes in the log, not to the customer.
file_put_contents("{$laneDir}/env2", implode("\n", array_merge(
    array_values(array_filter(file((string) getenv('SHOPCO_ENV_FILE'), FILE_IGNORE_NEW_LINES) ?: [], static fn (string $l): bool => !in_array(trim((string) strstr($l, '=', true)), $unset, true))),
    ['MONNIFY_CONTRACT_CODE=test', 'MONNIFY_API_KEY=test', 'MONNIFY_SECRET_KEY=test', 'MONNIFY_API_BASE_URL=http://127.0.0.1:9', 'STORE_WHATSAPP_PHONE=']
)) . "\n");
$lane = $startLane("{$laneDir}/env2");
$failing = $barePay('monnify');
$stopLane($lane);
$t->check('a gateway that cannot be reached -> 502', [502, 'GATEWAY_ERROR'], [$failing['status'], TestKit::get($failing, 'error.code')]);
$t->ok('without saying why', !preg_match('/authenticate|HTTP|Monnify/i', (string) TestKit::get($failing, 'error.message')), $failing['body']);

exit($t->finish());
