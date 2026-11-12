<?php

declare(strict_types=1);

/**
 * Catalogue speed features: the "units sold" counter behind Best selling, full-text search,
 * and the HTTP caching / compression of public catalogue responses.
 *
 *   php backend/tests/run.php CatalogScaling
 */

require __DIR__ . '/support/TestKit.php';

use App\Services\ProductSales;

$t = new TestKit('CatalogScaling');
$admin = $t->adminSession('scaling');
$A = static fn (string $method, string $path, array $opts = []): array
    => $t->http($method, $path, $opts + ['jar' => $admin['jar'], 'csrf' => $admin['csrf']]);

// -----------------------------------------------------------------------------
$t->section('Units sold');
$variant = $t->variant(3);
if ($variant === null) {
    $t->ok('a published variant with stock exists', false);
} else {
    $pid = (int) $variant['product_id'];
    // Earlier suites delete their paid test orders directly in SQL; start from a true count.
    ProductSales::refreshAll($t->db);
    $unitsSold = static fn (): int => (int) $t->scalar("SELECT `units_sold` FROM `products` WHERE `id` = ?", 'i', [$pid]);
    $before = $unitsSold();

    $email = $t->uniq('sold') . '@example.test';
    $res = $t->http('POST', '/checkout/orders', [
        'headers' => ['Idempotency-Key: ' . bin2hex(random_bytes(16))],
        'json' => [
            'email' => $email,
            'items' => [['product_id' => $pid, 'variant_id' => (int) $variant['variant_id'], 'quantity' => 2]],
            'shipping_address' => ['full_name' => 'Sold Counter', 'phone' => '08000000007', 'address_line1' => '7 Test Way', 'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria'],
        ],
    ]);
    $onum = (string) TestKit::get($res, 'data.order_number');
    $oid = (int) $t->scalar("SELECT `id` FROM `orders` WHERE `order_number` = ?", 's', [$onum]);
    $t->cleanup(function () use ($t, $oid, $email): void {
        foreach (['refunds', 'shipments', 'payments', 'order_status_history', 'order_items'] as $table) {
            $t->sql("DELETE FROM `{$table}` WHERE `order_id` = ?", 'i', [$oid]);
        }
        $t->sql("DELETE FROM `orders` WHERE `id` = ?", 'i', [$oid]);
        $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$email]);
    });
    $t->check('order placed', 201, $res['status']);
    $t->check('an unpaid order does not count as sold', $before, $unitsSold());

    $t->check('payment recorded', 200, $A('POST', "/admin/orders/{$onum}/confirm-payment", ['json' => ['reference' => 'BANK-' . bin2hex(random_bytes(4))]])['status']);
    $t->check('paid units are counted', $before + 2, $unitsSold());

    $t->check('order cancelled', 200, $A('PATCH', "/admin/orders/{$onum}/status", ['json' => ['status' => 'cancelled', 'comment' => 'Customer changed their mind']])['status']);
    $t->check('a cancelled order no longer counts', $before, $unitsSold());

    // Best selling lists the product with the most sold units first.
    $t->sql("UPDATE `products` SET `units_sold` = (SELECT m FROM (SELECT MAX(`units_sold`) + 5 AS m FROM `products`) x) WHERE `id` = ?", 'i', [$pid]);
    $list = $t->http('GET', '/products?sort=best_selling&per_page=1');
    $t->check('best selling lists the top seller first', $pid, (int) TestKit::get($list, 'data.0.id'));
    $t->sql("UPDATE `products` SET `units_sold` = ? WHERE `id` = ?", 'ii', [$before, $pid]);
}

// -----------------------------------------------------------------------------
$t->section('Search');
$word = 'zebrawood' . bin2hex(random_bytes(2));
$slug = $t->uniq('search-test');
$t->sql(
    "INSERT INTO `products` (`uuid`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `currency`, `sku`, `dress_style`)
     VALUES (UUID(), ?, ?, 'Soft fabric for warm days.', 'physical', 'published', 9000, 'NGN', ?, 'Casual')",
    'sss',
    ["Linen {$word}s Shirt", $slug, strtoupper(bin2hex(random_bytes(5)))]
);
$sid = (int) $t->scalar("SELECT `id` FROM `products` WHERE `slug` = ?", 's', [$slug]);
$t->cleanup(fn () => $t->sql("DELETE FROM `products` WHERE `id` = ?", 'i', [$sid]));
$found = static function (string $q) use ($t, $sid): bool {
    $ids = array_map('intval', array_column((array) (TestKit::get($t->http('GET', '/products?per_page=50&search=' . urlencode($q)), 'data') ?? []), 'id'));
    return in_array($sid, $ids, true);
};
$t->ok('finds a word in the name', $found($word . 's'));
$t->ok('finds the beginning of a word', $found($word));
$t->ok('finds words in the description', $found('fabric warm'));
$t->ok('stop words do not block a search', $found("the {$word}"));
$t->ok('needs every word to match', !$found("{$word} velvet"));
$t->ok('short searches still work', $found('Li'));

// -----------------------------------------------------------------------------
$t->section('Caching headers');
$list = $t->http('GET', '/products?per_page=12');
$t->ok('product lists may be cached by browsers', str_starts_with((string) ($list['headers']['cache-control'] ?? ''), 'public'));
$etag = (string) ($list['headers']['etag'] ?? '');
$t->ok('product lists carry an ETag', $etag !== '');
$t->check('unchanged list answers 304', 304, $t->http('GET', '/products?per_page=12', ['headers' => ["If-None-Match: {$etag}"]])['status']);
$t->check('account data is never cached', 'no-store', $t->http('GET', '/auth/me')['headers']['cache-control'] ?? '');
$t->check('admin data is never cached', 'no-store', $A('GET', '/admin/orders')['headers']['cache-control'] ?? '');
$gz = $t->http('GET', '/storefront/homepage', ['headers' => ['Accept-Encoding: gzip']]);
$t->check('large responses are gzip-compressed', 'gzip', $gz['headers']['content-encoding'] ?? '');
$t->ok('compressed body unpacks to the same JSON', is_array(json_decode((string) @gzdecode($gz['body']), true)));

exit($t->finish());
