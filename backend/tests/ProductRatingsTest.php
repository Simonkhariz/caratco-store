<?php

declare(strict_types=1);

/**
 * Starting ratings: a product's own starting rating (optional) or the store default, counted like
 * 5 reviews and blended with approved reviews. The review count is always real reviews only.
 *
 *   php backend/tests/run.php ProductRatings
 */

require __DIR__ . '/support/TestKit.php';

use App\Support\ProductRatings;

$t = new TestKit('ProductRatings');
$admin = $t->adminSession('ratings');
$A = static fn (string $method, string $path, array $opts = []): array
    => $t->http($method, $path, $opts + ['jar' => $admin['jar'], 'csrf' => $admin['csrf']]);

// Put the store default back exactly as it was (row or no row), then recompute every product.
$hadSetting = $t->scalar("SELECT COUNT(*) FROM `store_settings` WHERE `key_name` = ?", 's', [ProductRatings::SETTING]) > 0;
$oldSetting = $hadSetting ? (string) $t->scalar("SELECT `value` FROM `store_settings` WHERE `key_name` = ?", 's', [ProductRatings::SETTING]) : null;
$t->cleanup(function () use ($t, $hadSetting, $oldSetting): void {
    if ($hadSetting) {
        $t->sql("UPDATE `store_settings` SET `value` = ? WHERE `key_name` = ?", 'ss', [$oldSetting, ProductRatings::SETTING]);
    } else {
        $t->sql("DELETE FROM `store_settings` WHERE `key_name` = ?", 's', [ProductRatings::SETTING]);
    }
    ProductRatings::refresh(\App\Database\Database::getInstance());
});
$setDefault = static fn (string $value): array => $A('PUT', '/admin/settings', ['json' => [ProductRatings::SETTING => $value]]);
$t->check('store default set to 4.5 for the test', 200, $setDefault('4.5')['status']);

$stamp = bin2hex(random_bytes(3));
$slug = "rating-test-{$stamp}";
$sku = "RAT-{$stamp}";
$made = $A('POST', '/admin/products', ['json' => ['name' => "Rating Test {$stamp}", 'slug' => $slug, 'sku' => $sku, 'base_price' => 1000, 'status' => 'published']]);
$pid = (int) TestKit::get($made, 'data.id');
$t->cleanup(function () use ($t, $pid): void {
    $t->sql("DELETE FROM `reviews` WHERE `product_id` = ?", 'i', [$pid]);
    $t->sql("DELETE FROM `inventory_logs` WHERE `product_id` = ?", 'i', [$pid]);
    $t->sql("DELETE FROM `products` WHERE `id` = ?", 'i', [$pid]);
});
$adminRow = static fn (): array => (array) TestKit::get($A('GET', '/admin/products?search=' . urlencode($sku) . '&per_page=1'), 'data.0');
$shop = static fn (): array => (array) TestKit::get($t->http('GET', "/products/{$slug}"), 'data');

// -----------------------------------------------------------------------------
$t->section('A new product starts from the store default');
$t->check('created', 201, $made['status']);
$row = $adminRow();
$t->check('no own starting rating, shows the default 4.5, no reviews', [null, 4.5, 0], [array_key_exists('rating_start', $row) ? $row['rating_start'] : 'missing', $row['rating_avg'] ?? null, $row['rating_count'] ?? null]);
$pub = $shop();
$t->check('the shop shows 4.5 with a review count of 0', [4.5, 0], [$pub['rating_avg'] ?? null, $pub['rating_count'] ?? null]);
$t->ok('the shop is not told how the rating is made up', !array_key_exists('rating_start', $pub) && !array_key_exists('review_avg', $pub));

// -----------------------------------------------------------------------------
$t->section('The admin sets a starting rating');
$res = $A('PUT', "/admin/products/{$pid}", ['json' => ['rating_start' => 4.8]]);
$t->check('saved', 200, $res['status']);
$t->check('the product shows its own 4.8', [4.8, 4.8], [TestKit::get($res, 'data.rating_start'), TestKit::get($res, 'data.rating_avg')]);
$t->check('the shop shows 4.8', 4.8, $shop()['rating_avg'] ?? null);
foreach (['6' => 6, '0.5' => 0.5, '4.55' => 4.55, 'a word' => 'great', 'a list' => [5], 'true' => true] as $label => $bad) {
    $refused = $A('PUT', "/admin/products/{$pid}", ['json' => ['rating_start' => $bad]]);
    $t->check("starting rating {$label}: refused for that field", [422, true], [$refused['status'], TestKit::get($refused, 'error.details.rating_start') !== null]);
}
$t->check('a refused value changes nothing', 4.8, $adminRow()['rating_start'] ?? null);
$t->check('a save without the field keeps it', 4.8, TestKit::get($A('PUT', "/admin/products/{$pid}", ['json' => ['base_price' => 1200]]), 'data.rating_start'));

// -----------------------------------------------------------------------------
$t->section('Real reviews take over as they come in');
$reviewId = (int) $t->db->insert(
    "INSERT INTO `reviews` (`product_id`, `author_name`, `author_email`, `rating`, `comment`, `status`) VALUES (?, 'Rater', ?, 1, 'Not what I expected at all.', 'pending')",
    'is',
    [$pid, $t->uniq('t-rater') . '@example.test']
);
$t->check('a pending review changes nothing', [4.8, 0], [$adminRow()['rating_avg'] ?? null, $adminRow()['rating_count'] ?? null]);
$t->check('approving it', 200, $A('PATCH', "/admin/storefront/reviews/{$reviewId}/status", ['json' => ['status' => 'approved']])['status']);
$row = $adminRow();
$t->check('one 1-star review against 4.8 (counted as 5): (4.8x5 + 1) / 6 = 4.17', [4.17, 1, 1.0], [$row['rating_avg'] ?? null, $row['rating_count'] ?? null, $row['review_avg'] ?? null]);
$t->check('the shop shows the same, with the real count', [4.17, 1], [$shop()['rating_avg'] ?? null, $shop()['rating_count'] ?? null]);

$res = $A('PUT', "/admin/products/{$pid}", ['json' => ['rating_start' => '']]);
$t->check('clearing the starting rating goes back to the store default: (4.5x5 + 1) / 6 = 3.92', [null, 3.92], [TestKit::get($res, 'data.rating_start'), TestKit::get($res, 'data.rating_avg')]);

// -----------------------------------------------------------------------------
$t->section('The store default');
$t->check('a new default is saved', 200, $setDefault('4')['status']);
$t->check('and every product without its own is recomputed: (4x5 + 1) / 6 = 3.5', 3.5, $adminRow()['rating_avg'] ?? null);
$t->check('Settings shows it', '4', (function () use ($A): ?string {
    foreach ((array) TestKit::get($A('GET', '/admin/settings'), 'data.editable') as $item) {
        if ($item['key'] === ProductRatings::SETTING) {
            return $item['value'];
        }
    }
    return null;
})());
foreach (['7', '0', '4.25', 'five'] as $bad) {
    $t->check("default {$bad}: refused", 422, $setDefault($bad)['status']);
}
$t->check('empty: stars only from reviews', 200, $setDefault('')['status']);
$t->check('the product now shows its reviews alone: 1.0', 1.0, $adminRow()['rating_avg'] ?? null);
$t->check('approving nothing more, rejecting the review: no reviews and no default = no stars', 200, $A('PATCH', "/admin/storefront/reviews/{$reviewId}/status", ['json' => ['status' => 'rejected']])['status']);
$t->check('rating 0, count 0 (the shop hides the stars)', [0.0, 0], [$shop()['rating_avg'] ?? null, $shop()['rating_count'] ?? null]);
$A('PUT', "/admin/products/{$pid}", ['json' => ['rating_start' => 3]]);
$t->check('a product\'s own starting rating still applies with no store default', 3.0, $shop()['rating_avg'] ?? null);

// -----------------------------------------------------------------------------
$t->section('Parsing');
$t->check('parse: 4.5, "4", "", null', [4.5, 4.0, null, null], [ProductRatings::parse(4.5), ProductRatings::parse('4'), ProductRatings::parse(''), ProductRatings::parse(null)]);
$t->check('parse: 5.1, 0.9, 4.55, "x", true', [false, false, false, false, false], [ProductRatings::parse(5.1), ProductRatings::parse(0.9), ProductRatings::parse(4.55), ProductRatings::parse('x'), ProductRatings::parse(true)]);

exit($t->finish());
