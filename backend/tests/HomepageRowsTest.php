<?php

declare(strict_types=1);

/**
 * The home page's product rows: the Featured row is what the admin ticks, a product appears only
 * once on the page, and Top Selling only lists what has really sold.
 *
 *   php backend/tests/run.php HomepageRows
 */

require __DIR__ . '/support/TestKit.php';

$t = new TestKit('HomepageRows');

// Start every product from a known state, and put the seed's values back afterwards.
$original = $t->db->fetchAll('SELECT `id`, `is_featured`, `is_flash_deal`, `flash_deal_ends_at`, `units_sold`, `status` FROM `products`');
$t->cleanup(function () use ($t, $original): void {
    foreach ($original as $row) {
        $t->sql(
            'UPDATE `products` SET `is_featured` = ?, `is_flash_deal` = ?, `flash_deal_ends_at` = ?, `units_sold` = ?, `status` = ? WHERE `id` = ?',
            'iisisi',
            [(int) $row['is_featured'], (int) $row['is_flash_deal'], $row['flash_deal_ends_at'], (int) $row['units_sold'], $row['status'], (int) $row['id']]
        );
    }
});
$t->sql("UPDATE `products` SET `is_featured` = 0, `is_flash_deal` = 0, `flash_deal_ends_at` = NULL, `units_sold` = 0, `status` = 'published'");

$ids = array_map('intval', array_column($t->db->fetchAll("SELECT `id` FROM `products` WHERE `product_type` = 'physical' ORDER BY `created_at` DESC, `id` DESC"), 'id'));
$t->ok('the sample catalogue has enough products for this', count($ids) >= 16, (string) count($ids));
$home = static function () use ($t): array {
    $res = $t->http('GET', '/storefront/homepage');
    return (array) TestKit::get($res, 'data');
};
$rowIds = static fn (array $data, string $row): array => array_map('intval', array_column((array) ($data[$row] ?? []), 'id'));
$feature = static fn (array $list) => $t->sql('UPDATE `products` SET `is_featured` = 1 WHERE `id` IN (' . implode(',', array_map('intval', $list)) . ')');
$rows = ['flash_deals', 'featured_products', 'new_arrivals', 'top_selling'];
$noRepeats = static function (array $data) use ($rows, $rowIds): bool {
    $all = [];
    foreach ($rows as $row) {
        $all = array_merge($all, $rowIds($data, $row));
    }
    return count($all) === count(array_unique($all));
};

// -----------------------------------------------------------------------------
$t->section('A new shop: no made-up rows');
$data = $home();
$t->check('nothing ticked: no Featured row', [], $rowIds($data, 'featured_products'));
$t->check('nothing sold: no Top Selling row', [], $rowIds($data, 'top_selling'));
$t->check('New Arrivals still shows the newest 4', array_slice($ids, 0, 4), $rowIds($data, 'new_arrivals'));

// -----------------------------------------------------------------------------
$t->section('The admin decides what is featured');
$admin = $t->adminSession('homepage');
$picked = [$ids[10], $ids[12]];
$feature($picked);
$data = $home();
$t->check('ticked products appear in the Featured row', [$ids[10], $ids[12]], $rowIds($data, 'featured_products'));
$t->ok('whether or not they are new', !array_intersect($picked, array_slice($ids, 0, 4)));

$adminList = (array) TestKit::get($t->http('GET', '/admin/products?per_page=100', ['jar' => $admin['jar']]), 'data');
$ticks = array_column($adminList, 'is_featured', 'id');
$t->check('the admin sees the tick in the product list', [true, false], [(bool) ($ticks[$ids[10]] ?? false), (bool) ($ticks[$ids[11]] ?? true)]);
$t->sql('UPDATE `products` SET `is_featured` = 0 WHERE `id` = ?', 'i', [$ids[12]]);
$t->check('unticking removes it', [$ids[10]], $rowIds($home(), 'featured_products'));

$t->sql("UPDATE `products` SET `status` = 'draft' WHERE `id` = ?", 'i', [$ids[10]]);
$t->check('a draft never shows, even when ticked', [], $rowIds($home(), 'featured_products'));
$t->sql("UPDATE `products` SET `status` = 'published' WHERE `id` = ?", 'i', [$ids[10]]);

$feature(array_slice($ids, 0, 12));
$data = $home();
$t->check('at most 4 show, newest first', array_slice($ids, 0, 4), $rowIds($data, 'featured_products'));
$listed = $t->http('GET', '/products?is_featured=1&per_page=50');
$t->check('"View all" lists every ticked product', 12, count((array) (TestKit::get($listed, 'data.items') ?? TestKit::get($listed, 'data'))));
$t->sql('UPDATE `products` SET `is_featured` = 0');

// -----------------------------------------------------------------------------
$t->section('A product appears only once on the page');
$t->sql('UPDATE `products` SET `is_flash_deal` = 1 WHERE `id` = ?', 'i', [$ids[0]]);
$feature([$ids[0], $ids[1]]);
$t->sql('UPDATE `products` SET `units_sold` = 50 WHERE `id` IN (?, ?, ?, ?)', 'iiii', [$ids[0], $ids[1], $ids[2], $ids[9]]);
$data = $home();
$t->check('flash deals come first', [$ids[0]], $rowIds($data, 'flash_deals'));
$t->check('Featured skips what flash deals show', [$ids[1]], $rowIds($data, 'featured_products'));
$t->check('New Arrivals skips what is above it', [$ids[2], $ids[3], $ids[4], $ids[5]], $rowIds($data, 'new_arrivals'));
$t->check('Top Selling shows the seller not shown yet', [$ids[9]], $rowIds($data, 'top_selling'));
$t->ok('no product is in two rows', $noRepeats($data));

$t->sql("UPDATE `products` SET `is_flash_deal` = 1, `flash_deal_ends_at` = '2020-01-01 00:00:00' WHERE `id` = ?", 'i', [$ids[0]]);
$data = $home();
$t->check('a finished deal goes back to the other rows', true, in_array($ids[0], $rowIds($data, 'featured_products'), true));

// -----------------------------------------------------------------------------
$t->section('Every row stops at 4 products');
$t->sql('UPDATE `products` SET `is_featured` = 1, `is_flash_deal` = 1, `flash_deal_ends_at` = NULL, `units_sold` = 5');
$data = $home();
$t->check('flash deals', 4, count($rowIds($data, 'flash_deals')));
$t->check('featured, when more are left', 4, count($rowIds($data, 'featured_products')));
$t->check('new arrivals', 4, count($rowIds($data, 'new_arrivals')));
$t->ok('top selling, however many have sold', count($rowIds($data, 'top_selling')) <= 4 && count($rowIds($data, 'top_selling')) > 0, (string) count($rowIds($data, 'top_selling')));
$t->ok('and still no repeats', $noRepeats($data));

// -----------------------------------------------------------------------------
$t->section('Top Selling is real');
$t->sql('UPDATE `products` SET `is_featured` = 0, `is_flash_deal` = 0, `flash_deal_ends_at` = NULL, `units_sold` = 0');
$t->sql('UPDATE `products` SET `units_sold` = ? WHERE `id` = ?', 'ii', [5, $ids[13]]);
$t->sql('UPDATE `products` SET `units_sold` = ? WHERE `id` = ?', 'ii', [9, $ids[14]]);
$t->sql('UPDATE `products` SET `units_sold` = ? WHERE `id` = ?', 'ii', [7, $ids[15]]);
$t->check('ranked by units sold', [$ids[14], $ids[15], $ids[13]], $rowIds($home(), 'top_selling'));
$t->check('and products with no sales never fill it', 3, count($rowIds($home(), 'top_selling')));

// -----------------------------------------------------------------------------
$t->section('The admin chooses which coupon the homepage advertises');
$originalTicks = array_column($t->db->fetchAll('SELECT `id` FROM `promotions` WHERE `show_on_homepage` = 1'), 'id');
$t->cleanup(function () use ($t, $originalTicks): void {
    foreach ($originalTicks as $id) {
        $t->sql('UPDATE `promotions` SET `show_on_homepage` = 1 WHERE `id` = ?', 'i', [(int) $id]);
    }
});
$t->sql('UPDATE `promotions` SET `show_on_homepage` = 0');
$older = strtoupper(bin2hex(random_bytes(4)));
$newer = strtoupper(bin2hex(random_bytes(4)));
$t->cleanup(fn () => $t->sql('DELETE FROM `promotions` WHERE `code` IN (?, ?)', 'ss', [$older, $newer]));
$shownCodes = static fn (): array => array_column((array) ($home()['promotions'] ?? []), 'code');
$adminSend = static function (string $method, string $path, array $json = []) use ($t, $admin): array {
    return $t->http($method, $path, ['jar' => $admin['jar'], 'csrf' => $admin['csrf'], 'json' => $json]);
};
$promoBody = static fn (string $code, array $over = []): array => $over + ['code' => $code, 'description' => 'Test', 'discount_percent' => 5];
$olderId = (int) TestKit::get($adminSend('POST', '/admin/promotions', $promoBody($older)), 'data.id');
$newerId = (int) TestKit::get($adminSend('POST', '/admin/promotions', $promoBody($newer)), 'data.id');
$t->ok('both codes were created', $olderId > 0 && $newerId > 0);
$t->check('a new code is private: nothing is advertised', [], $shownCodes());

$t->check('ticking saves', 200, $adminSend('PUT', "/admin/promotions/{$olderId}", ['show_on_homepage' => true])['status']);
$t->check('the ticked code shows straight away, not the newer private one', [$older], $shownCodes());

$adminRow = static function (int $id) use ($t, $admin): array {
    $items = (array) TestKit::get($t->http('GET', '/admin/promotions?per_page=100', ['jar' => $admin['jar']]), 'data.items');
    return (array) (array_column($items, null, 'id')[$id] ?? []);
};
$row = $adminRow($olderId);
$t->check('the admin list says it is showing', [true, true], [$row['show_on_homepage'] ?? null, $row['on_homepage_now'] ?? null]);
$t->check('and that the other is not', [false, false], [$adminRow($newerId)['show_on_homepage'] ?? null, $adminRow($newerId)['on_homepage_now'] ?? null]);

$adminSend('PUT', "/admin/promotions/{$newerId}", ['show_on_homepage' => true]);
$t->check('with two ticked, only the newest is sent', [$newer], $shownCodes());
$row = $adminRow($olderId);
$t->check('the older one stays ticked, not showing', [true, false], [$row['show_on_homepage'] ?? null, $row['on_homepage_now'] ?? null]);

$adminSend('PUT', "/admin/promotions/{$newerId}", ['is_active' => false]);
$t->check('a paused code steps aside for the next ticked one', [$older], $shownCodes());
$t->check('pausing keeps the tick', 1, (int) $t->scalar('SELECT `show_on_homepage` FROM `promotions` WHERE `id` = ?', 'i', [$newerId]));
$adminSend('PUT', "/admin/promotions/{$newerId}", ['is_active' => true]);

$adminSend('PUT', "/admin/promotions/{$newerId}", ['description' => 'Edited, tick not sent']);
$t->check('an edit that leaves the tick out keeps it', [$newer], $shownCodes());

$adminSend('PUT', "/admin/promotions/{$newerId}", ['show_on_homepage' => false]);
$adminSend('PUT', "/admin/promotions/{$olderId}", ['expires_at' => '2020-01-01']);
$t->check('an expired code is not advertised, even when ticked', [], $shownCodes());
$adminSend('PUT', "/admin/promotions/{$olderId}", ['expires_at' => null, 'starts_at' => '2099-01-01']);
$t->check('nor is one that has not started', [], $shownCodes());
$adminSend('PUT', "/admin/promotions/{$olderId}", ['starts_at' => null, 'max_uses' => 3]);
$t->check('it shows once it is live', [$older], $shownCodes());
$t->sql('UPDATE `promotions` SET `used_count` = 3 WHERE `id` = ?', 'i', [$olderId]);
$t->check('nor one that is used up', [], $shownCodes());
$t->sql('UPDATE `promotions` SET `used_count` = 0 WHERE `id` = ?', 'i', [$olderId]);

$adminSend('PUT', "/admin/promotions/{$olderId}", ['show_on_homepage' => false]);
$t->check('unticking takes it off the page', [], $shownCodes());
$check = $t->http('POST', '/checkout/validate-promo', ['json' => ['code' => $newer, 'subtotal' => 10000]]);
$t->check('a private code still works at checkout', 200, $check['status']);

// -----------------------------------------------------------------------------
$t->section('Open storefront pages learn about admin changes');
$version = static function () use ($t): array {
    $res = $t->http('GET', '/storefront/version');
    return [$res['status'], (string) TestKit::get($res, 'data.version'), strtolower((string) ($res['headers']['cache-control'] ?? ''))];
};
[$status, $before, $cacheControl] = $version();
$t->check('anyone can ask for the storefront version', 200, $status);
$t->ok('and it is never cached', str_contains($cacheControl, 'no-store'), $cacheControl);
$t->check('reading it changes nothing', $before, $version()[1]);
$adminSend('PUT', "/admin/promotions/{$olderId}", ['show_on_homepage' => true]);
$after = $version()[1];
$t->ok('an admin change moves it', $after !== $before && $after !== '', "{$before} -> {$after}");
$refused = $adminSend('PUT', "/admin/promotions/{$olderId}", ['discount_percent' => 500]);
$t->ok('a refused admin change is refused', $refused['status'] >= 400, (string) $refused['status']);
$t->check('and leaves it alone', $after, $version()[1]);
$adminSend('PUT', "/admin/promotions/{$olderId}", ['show_on_homepage' => false]);

// -----------------------------------------------------------------------------
$t->section('Shop contact settings show on the very next request');
$keys = ['store_contact_phone', 'store_contact_email', 'store_whatsapp_phone'];
$saved = array_column($t->db->fetchAll("SELECT `key_name`, `value` FROM `store_settings` WHERE `key_name` IN ('store_contact_phone', 'store_contact_email', 'store_whatsapp_phone')"), 'value', 'key_name');
$t->cleanup(function () use ($t, $keys, $saved): void {
    foreach ($keys as $key) {
        if (array_key_exists($key, $saved)) {
            $t->sql('UPDATE `store_settings` SET `value` = ? WHERE `key_name` = ?', 'ss', [(string) $saved[$key], $key]);
        } else {
            $t->sql('DELETE FROM `store_settings` WHERE `key_name` = ?', 's', [$key]);
        }
    }
});
$tag = random_int(1000000, 9999999);
$beforeVersion = $version()[1];
$settingsSaved = $adminSend('PUT', '/admin/settings', [
    'store_contact_phone' => "0803{$tag}",
    'store_contact_email' => "help{$tag}@example.test",
    'store_whatsapp_phone' => "0805{$tag}",
]);
$t->check('the admin saves new phone, email and WhatsApp', 200, $settingsSaved['status']);
$origin = (array) TestKit::get($t->http('GET', '/checkout/shipping-zones'), 'data.origin');
$t->check('the next shop request has the new phone and email', ["0803{$tag}", "help{$tag}@example.test"], [$origin['contact_phone'] ?? null, $origin['contact_email'] ?? null]);
$t->ok('and the new WhatsApp number', str_ends_with((string) ($origin['whatsapp_phone'] ?? ''), (string) $tag), (string) ($origin['whatsapp_phone'] ?? ''));
$support = (string) TestKit::get($t->http('GET', '/checkout/payment-methods'), 'data.support_phone');
$t->ok('checkout and WhatsApp payment use the new number at once', str_ends_with(preg_replace('/\D/', '', $support) ?? '', (string) $tag), $support);
$t->ok('and open pages are told something changed', $version()[1] !== $beforeVersion);

exit($t->finish());
