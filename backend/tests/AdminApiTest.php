<?php

declare(strict_types=1);

/**
 * Admin API: dashboard, paginated lists, categories, delivery zones, promotions, banners,
 * settings, system status, newsletter, customers, admin account, and a full order lifecycle
 * (manual payment, shipping, delivery, partial refund, order emails).
 *
 *   php backend/tests/run.php AdminApi
 */

require __DIR__ . '/support/TestKit.php';

$t = new TestKit('AdminApi');
$admin = $t->adminSession('adminapi');
$A = static fn (string $method, string $path, array $opts = []): array
    => $t->http($method, $path, $opts + ['jar' => $admin['jar'], 'csrf' => $admin['csrf']]);
$adminId = $admin['id'];

$t->check('admin login works', true, $admin['csrf'] !== '');

// -----------------------------------------------------------------------------
$t->section('Dashboard');
$t->check('overview 7-day period', 7, TestKit::get($A('GET', '/admin/dashboard/overview?period=7'), 'data.period.days'));
$t->check('overview trend has one point per day', 30, count((array) TestKit::get($A('GET', '/admin/dashboard/overview?period=30'), 'data.trend')));
$t->ok('overview has deltas block', array_key_exists('gross_sales', (array) TestKit::get($A('GET', '/admin/dashboard/overview'), 'data.deltas')));
$t->check('invalid period falls back to 30', 30, TestKit::get($A('GET', '/admin/dashboard/overview?period=5'), 'data.period.days'));

// -----------------------------------------------------------------------------
$t->section('Pagination');
foreach (['inventory', 'inventory/logs', 'customers', 'payments', 'refunds', 'notifications/outbox', 'audit-logs', 'storefront/reviews', 'marketing/subscribers', 'orders'] as $path) {
    $t->check("GET {$path} paginates", 2, TestKit::get($A('GET', "/admin/{$path}?per_page=2"), 'data.pagination.per_page'));
}
$t->ok('audit-logs lists action filter values', is_array(TestKit::get($A('GET', '/admin/audit-logs'), 'data.filters.actions')));

// -----------------------------------------------------------------------------
$t->section('Categories');
$cats = $A('GET', '/admin/categories');
$t->ok('admin category list includes is_active', array_key_exists('is_active', (array) TestKit::get($cats, 'data.0')));
$slug = 'test-cat-' . bin2hex(random_bytes(3));
$res = $A('POST', '/admin/categories', ['json' => ['name' => 'Test Cat', 'slug' => $slug]]);
$t->check('create category', 201, $res['status']);
$cid = (int) TestKit::get($res, 'data.id');
$t->cleanup(fn () => $t->sql("DELETE FROM `categories` WHERE `id` = ?", 'i', [$cid]));
$t->check('duplicate slug refused', 422, $A('POST', '/admin/categories', ['json' => ['name' => 'Test Cat 2', 'slug' => $slug]])['status']);
$t->check('deactivate category', 200, $A('DELETE', "/admin/categories/{$cid}")['status']);
$t->check('hidden category still listed for admin', false, (function () use ($A, $cid) {
    foreach ((array) TestKit::get($A('GET', '/admin/categories'), 'data') as $c) {
        if ((int) $c['id'] === $cid) {
            return (bool) $c['is_active'];
        }
    }
    return null;
})());
$t->check('restore category', 'Category restored.', TestKit::get($A('PUT', "/admin/categories/{$cid}", ['json' => ['is_active' => true]]), 'message'));
$t->check('category actions audited with admin id', 3, (int) $t->scalar(
    "SELECT COUNT(*) FROM `admin_audit_logs` WHERE `entity_type` = 'category' AND `entity_id` = ? AND `admin_id` = ?",
    'si',
    [(string) $cid, $adminId]
));

foreach (['501', '2e3'] as $numericSlug) {
    $bad = $A('POST', '/admin/categories', ['json' => ['name' => 'Numbered', 'slug' => $numericSlug]]);
    $t->check("category slug {$numericSlug} refused: it would read as an ID", [422, true], [$bad['status'], str_contains((string) TestKit::get($bad, 'error.details.slug'), 'only a number')]);
}
$t->check('renaming a category slug to a number is refused', 422, $A('PUT', "/admin/categories/{$cid}", ['json' => ['slug' => '777']])['status']);
$autoCat = $A('POST', '/admin/categories', ['json' => ['name' => '777']]);
$t->check('no slug given, name "777": slug category-777', [201, 'category-777'], [$autoCat['status'], TestKit::get($autoCat, 'data.slug')]);
$t->cleanup(fn () => $t->sql("DELETE FROM `categories` WHERE `slug` = 'category-777'"));
foreach ([-5, 100001, 2147483648] as $order) {
    $t->check("category display order {$order} refused", 422, $A('PUT', "/admin/categories/{$cid}", ['json' => ['display_order' => $order]])['status']);
}
$t->check('category display order 100000 accepted', 200, $A('PUT', "/admin/categories/{$cid}", ['json' => ['display_order' => 100000]])['status']);
$t->check('category name over 100 characters refused', 422, $A('PUT', "/admin/categories/{$cid}", ['json' => ['name' => str_repeat('n', 101)]])['status']);
$t->check('category name sent as a list refused, not saved as "Array"', 422, $A('PUT', "/admin/categories/{$cid}", ['json' => ['name' => ['x']]])['status']);

// -----------------------------------------------------------------------------
$t->section('Delivery zones');
$t->check('zone list returns state list', 37, count((array) TestKit::get($A('GET', '/admin/shipping-zones'), 'data.states')));
$t->check('zone with misspelt state refused', 422, $A('POST', '/admin/shipping-zones', ['json' => ['name' => 'Bad', 'code' => 'TBAD', 'fee' => 1000, 'estimated_days' => '2 days', 'states_covered' => ['Lagoss']]])['status']);
$t->check('zone with negative fee refused', 422, $A('POST', '/admin/shipping-zones', ['json' => ['name' => 'Bad', 'code' => 'TBAD', 'fee' => -5, 'estimated_days' => '2 days', 'states_covered' => ['Kano']]])['status']);
$zcode = 'TZ' . strtoupper(bin2hex(random_bytes(3)));
$res = $A('POST', '/admin/shipping-zones', ['json' => ['name' => 'Test Zone', 'code' => $zcode, 'fee' => 2500, 'estimated_days' => '3 days', 'states_covered' => ['Abuja', 'kano'], 'is_active' => false]]);
$t->check('create zone (Abuja normalised to FCT)', ['FCT', 'Kano'], TestKit::get($res, 'data.states_covered'));
$zid = (int) TestKit::get($res, 'data.id');
$t->cleanup(fn () => $t->sql("DELETE FROM `shipping_zones` WHERE `code` = ?", 's', [$zcode]));
$t->check('duplicate zone code refused', 422, $A('POST', '/admin/shipping-zones', ['json' => ['name' => 'Dup', 'code' => $zcode, 'fee' => 1, 'estimated_days' => '1 day', 'states_covered' => ['Kano']]])['status']);
$t->check('update zone fee', 3000, TestKit::get($A('PUT', "/admin/shipping-zones/{$zid}", ['json' => ['fee' => 3000]]), 'data.fee'));
$t->check('delete zone', 200, $A('DELETE', "/admin/shipping-zones/{$zid}")['status']);
$t->check('zone actions audited', 3, (int) $t->scalar("SELECT COUNT(*) FROM `admin_audit_logs` WHERE `entity_type` = 'shipping_zone' AND `entity_id` = ?", 's', [(string) $zid]));

// Delivery fee by quantity: N1,000 covers 2 items, then N500 for each extra item.
$t->check('"items the fee covers" must be 1 or more', 422, $A('POST', '/admin/shipping-zones', ['json' => ['name' => 'Bad', 'code' => 'TBAD', 'fee' => 1000, 'items_included' => 0, 'estimated_days' => '2 days', 'states_covered' => ['Kano']]])['status']);
$qcode = 'TQ' . strtoupper(bin2hex(random_bytes(3)));
$qzone = $A('POST', '/admin/shipping-zones', ['json' => ['name' => 'Quantity Zone', 'code' => $qcode, 'fee' => 1000, 'items_included' => 2, 'extra_item_fee' => 500, 'estimated_days' => '3 days', 'states_covered' => ['Kano']]]);
$t->check('zone saved with its per-item rule', [2, 500.0], [TestKit::get($qzone, 'data.items_included'), (float) TestKit::get($qzone, 'data.extra_item_fee')]);
$qzid = (int) TestKit::get($qzone, 'data.id');
$t->cleanup(fn () => $t->sql("DELETE FROM `shipping_zones` WHERE `code` = ?", 's', [$qcode]));
$qv = $t->variant(5);
if ($qv !== null) {
    $quoteFor = static fn (int $qty): float => (float) TestKit::get($t->http('POST', '/checkout/quote', ['json' => [
        'items' => [['product_id' => (int) $qv['product_id'], 'variant_id' => (int) $qv['variant_id'], 'quantity' => $qty]],
        'shipping_address' => ['state' => 'Kano', 'country' => 'Nigeria'],
        'shipping_zone_id' => $qzid,
    ]]), 'data.shipping_fee');
    $t->check('1 or 2 items: the zone fee', [1000.0, 1000.0], [$quoteFor(1), $quoteFor(2)]);
    $t->check('3 items: + N500, 5 items: + N1,500', [1500.0, 2500.0], [$quoteFor(3), $quoteFor(5)]);
}

// Input the columns can't hold, and fees out of range.
$zoneBody = static fn (array $over): array => $over + ['name' => 'Limits', 'code' => 'TLIM' . strtoupper(bin2hex(random_bytes(3))), 'fee' => 100, 'estimated_days' => '2 days', 'states_covered' => ['Sokoto'], 'is_active' => false];
$t->check('zone name over 100 characters refused', 422, $A('POST', '/admin/shipping-zones', ['json' => $zoneBody(['name' => str_repeat('z', 101)])])['status']);
$t->check('estimate over 50 characters refused', 422, $A('POST', '/admin/shipping-zones', ['json' => $zoneBody(['estimated_days' => str_repeat('d', 51)])])['status']);
$bigFee = $A('POST', '/admin/shipping-zones', ['json' => $zoneBody(['fee' => 1000001])]);
$t->check('a too-big fee says the real limit', [422, true], [$bigFee['status'], str_contains((string) TestKit::get($bigFee, 'error.details.fee'), '1,000,000')]);

// One active zone per state: checkout only ever charges the first, so a second never works.
$hubs = (string) $t->scalar("SELECT `name` FROM `shipping_zones` WHERE `code` = 'ZONE-MAJOR-HUBS'");
$national = (string) $t->scalar("SELECT `name` FROM `shipping_zones` WHERE `code` = 'ZONE-NATIONAL'");
$second = $A('POST', '/admin/shipping-zones', ['json' => $zoneBody(['states_covered' => ['Lagos'], 'is_active' => true])]);
$t->check('a second active zone for Lagos is refused, naming the zone', [422, true], [$second['status'], str_contains((string) TestKit::get($second, 'error.message'), $hubs)]);
$t->ok('and the field is highlighted', str_contains((string) TestKit::get($second, 'error.details.states_covered'), 'Lagos'));
$secondAll = $A('POST', '/admin/shipping-zones', ['json' => $zoneBody(['states_covered' => ['*'], 'is_active' => true])]);
$t->check('a second "All other states" zone is refused, naming the zone', [422, true], [$secondAll['status'], str_contains((string) TestKit::get($secondAll, 'error.message'), $national)]);
$spare = $A('POST', '/admin/shipping-zones', ['json' => $zoneBody(['states_covered' => ['Lagos'], 'is_active' => false])]);
$t->check('an inactive zone may list a covered state', 201, $spare['status']);
$spareId = (int) TestKit::get($spare, 'data.id');
$t->cleanup(fn () => $t->sql("DELETE FROM `shipping_zones` WHERE `id` = ?", 'i', [$spareId]));
$t->check('switching it on is refused', 422, $A('PUT', "/admin/shipping-zones/{$spareId}", ['json' => ['is_active' => true]])['status']);
$t->check('moved to a free state it can be switched on', 200, $A('PUT', "/admin/shipping-zones/{$spareId}", ['json' => ['is_active' => true, 'states_covered' => ['Sokoto']]])['status']);
$t->check('an active zone gaining a covered state is refused', 422, $A('PUT', "/admin/shipping-zones/{$spareId}", ['json' => ['states_covered' => ['Sokoto', 'Rivers']]])['status']);
$t->sql("UPDATE `shipping_zones` SET `states_covered` = ? WHERE `id` = ?", 'si', [json_encode(['lagos', 'Sokoto']), $spareId]); // an old overlap
$t->check('zones that already overlap still load', 200, $A('GET', '/admin/shipping-zones')['status']);
$t->check('and can be switched off', false, TestKit::get($A('PUT', "/admin/shipping-zones/{$spareId}", ['json' => ['is_active' => false]]), 'data.is_active'));

// -----------------------------------------------------------------------------
$t->section('Promotions');
$pcode = 'TP' . strtoupper(bin2hex(random_bytes(3)));
$t->cleanup(fn () => $t->sql("DELETE FROM `promotions` WHERE `code` = ?", 's', [$pcode]));
$t->check('end before start refused', 422, $A('POST', '/admin/promotions', ['json' => ['code' => $pcode, 'description' => 't', 'discount_percent' => 10, 'starts_at' => '2099-12-10', 'expires_at' => '2099-12-01']])['status']);
$t->check('95% discount refused', 422, $A('POST', '/admin/promotions', ['json' => ['code' => $pcode, 'description' => 't', 'discount_percent' => 95]])['status']);
$res = $A('POST', '/admin/promotions', ['json' => ['code' => $pcode, 'description' => 't', 'discount_percent' => 10, 'max_uses' => 0, 'starts_at' => '2099-12-01', 'expires_at' => '2099-12-31']]);
$t->check('create promotion with dates', 201, $res['status']);
$promoId = (int) TestKit::get($res, 'data.id');
$t->check('expiry stored as end of day', '2099-12-31 23:59:59', $t->scalar("SELECT `expires_at` FROM `promotions` WHERE `id` = ?", 'i', [$promoId]));
$A('PUT', "/admin/promotions/{$promoId}", ['json' => ['expires_at' => '2100-01-15']]);
$t->check('update expiry is saved', '2100-01-15 23:59:59', $t->scalar("SELECT `expires_at` FROM `promotions` WHERE `id` = ?", 'i', [$promoId]));
$t->check('not-yet-started code refused at checkout', false, TestKit::get($t->http('POST', '/checkout/validate-promo', ['json' => ['code' => $pcode, 'subtotal' => 10000]]), 'success'));
$t->check('unused promotion is deleted', true, TestKit::get($A('DELETE', "/admin/promotions/{$promoId}"), 'data.deleted'));

// Coupon rules: a fixed amount off, a minimum order, a cap on a percentage.
$fixed = 'TF' . strtoupper(bin2hex(random_bytes(3)));
$capped = 'TC' . strtoupper(bin2hex(random_bytes(3)));
$t->cleanup(fn () => $t->sql("DELETE FROM `promotions` WHERE `code` IN (?, ?)", 'ss', [$fixed, $capped]));
$t->check('a fixed code needs an amount', 422, $A('POST', '/admin/promotions', ['json' => ['code' => $fixed, 'description' => 't', 'discount_type' => 'fixed']])['status']);
$t->check('a fixed amount above the minimum order is refused', 422, $A('POST', '/admin/promotions', ['json' => ['code' => $fixed, 'description' => 't', 'discount_type' => 'fixed', 'discount_amount' => 25000, 'min_order_amount' => 20000]])['status']);
$t->check('create "N5,000 off orders of N20,000+"', 201, $A('POST', '/admin/promotions', ['json' => ['code' => $fixed, 'description' => 'Five off', 'discount_type' => 'fixed', 'discount_amount' => 5000, 'min_order_amount' => 20000]])['status']);
$t->check('create "10% off, at most N1,000"', 201, $A('POST', '/admin/promotions', ['json' => ['code' => $capped, 'description' => 'Ten capped', 'discount_type' => 'percent', 'discount_percent' => 10, 'max_discount_amount' => 1000]])['status']);
$list = (array) TestKit::get($A('GET', '/admin/promotions?per_page=100'), 'data.items');
$labels = array_column($list, 'label', 'code');
$t->check('admin list describes each code', ['₦5,000 off on orders of ₦20,000+', '10% off (up to ₦1,000)'], [$labels[$fixed] ?? null, $labels[$capped] ?? null]);
$promoCheck = static fn (string $code, float $subtotal): array => $t->http('POST', '/checkout/validate-promo', ['json' => ['code' => $code, 'subtotal' => $subtotal]]);
$below = $promoCheck($fixed, 15000);
$t->check('below the minimum: refused, saying how much more to add', [422, true], [$below['status'], str_contains((string) TestKit::get($below, 'error.message'), 'Add ₦5,000.00 more')]);
$t->check('at the minimum: N5,000 off', 5000.0, (float) TestKit::get($promoCheck($fixed, 20000), 'data.discount_amount'));
$t->check('10% of N50,000 is capped at N1,000', 1000.0, (float) TestKit::get($promoCheck($capped, 50000), 'data.discount_amount'));
$t->check('10% of N5,000 is N500 (under the cap)', 500.0, (float) TestKit::get($promoCheck($capped, 5000), 'data.discount_amount'));
$t->check('a fixed code never takes off more than the items cost', 0, (int) $t->scalar("SELECT COUNT(*) FROM `promotions` WHERE `code` = ? AND `discount_amount` > `min_order_amount`", 's', [$fixed]));
$fixedId = (int) $t->scalar("SELECT `id` FROM `promotions` WHERE `code` = ?", 's', [$fixed]);
$t->check('a fixed code must keep a minimum order above its amount (an order is never free)', 422, $A('PUT', "/admin/promotions/{$fixedId}", ['json' => ['min_order_amount' => '']])['status']);
$t->check('an amount equal to the minimum is refused too', 422, $A('PUT', "/admin/promotions/{$fixedId}", ['json' => ['discount_amount' => 20000]])['status']);
// The homepage sends only the newest live ticked code: this one is both once it is ticked.
$A('PUT', "/admin/promotions/{$fixedId}", ['json' => ['show_on_homepage' => true]]);
$t->check('the homepage shows the wording', true, in_array('₦5,000 off on orders of ₦20,000+', array_column((array) TestKit::get($t->http('GET', '/storefront/homepage'), 'data.promotions'), 'label'), true));
$A('PUT', "/admin/promotions/{$fixedId}", ['json' => ['show_on_homepage' => false]]);
// Safety net for any code (e.g. one made before these rules): an order is never priced at N0.
$freeCode = 'TZ0' . strtoupper(bin2hex(random_bytes(3)));
$freeZone = 'TF0' . strtoupper(bin2hex(random_bytes(3)));
$t->sql("INSERT INTO `promotions` (`code`, `description`, `discount_type`, `discount_percent`, `discount_amount`) VALUES (?, 't', 'fixed', 0, 99999999)", 's', [$freeCode]);
$t->cleanup(fn () => $t->sql("DELETE FROM `promotions` WHERE `code` = ?", 's', [$freeCode]));
$fz = $A('POST', '/admin/shipping-zones', ['json' => ['name' => 'Free Zone', 'code' => $freeZone, 'fee' => 0, 'estimated_days' => '1 day', 'states_covered' => ['Borno']]]);
$t->cleanup(fn () => $t->sql("DELETE FROM `shipping_zones` WHERE `code` = ?", 's', [$freeZone]));
$fv = $t->variant(1);
if ($fv !== null) {
    $zeroQuote = $t->http('POST', '/checkout/quote', ['json' => [
        'items' => [['product_id' => (int) $fv['product_id'], 'variant_id' => (int) $fv['variant_id'], 'quantity' => 1]],
        'shipping_address' => ['state' => 'Borno', 'country' => 'Nigeria'],
        'shipping_zone_id' => (int) TestKit::get($fz, 'data.id'),
        'coupon_code' => $freeCode,
    ]]);
    $t->check('a quote that would cost N0 is refused with a clear reason', [422, true], [$zeroQuote['status'], str_contains((string) TestKit::get($zeroQuote, 'error.message'), 'would cost ₦0')]);
}

// Limits, and a used code keeps its name (orders refer to it by name).
$limit = 'TL' . strtoupper(bin2hex(random_bytes(3)));
$renamed = $limit . 'X';
$t->cleanup(fn () => $t->sql("DELETE FROM `promotions` WHERE `code` IN (?, ?)", 'ss', [$limit, $renamed]));
$promoBody = static fn (array $over): array => $over + ['code' => $limit, 'description' => 't', 'discount_percent' => 10];
$t->check('promotion description over 255 characters refused', 422, $A('POST', '/admin/promotions', ['json' => $promoBody(['description' => str_repeat('d', 256)])])['status']);
$promoRefused = static fn (array $over): array => (static fn (array $r): array => [$r['status'], array_keys((array) TestKit::get($r, 'error.details'))])($A('POST', '/admin/promotions', ['json' => $promoBody($over)]));
$t->check('promotion max uses 2147483648 refused', [422, ['max_uses']], $promoRefused(['max_uses' => 2147483648]));
$t->check('promotion max uses over the limit refused', [422, ['max_uses']], $promoRefused(['max_uses' => 1000001]));
$t->check('promotion minimum order too large refused', [422, ['min_order_amount']], $promoRefused(['min_order_amount' => 100000000]));
$limitRes = $A('POST', '/admin/promotions', ['json' => $promoBody(['max_uses' => 1000000])]);
$t->check('promotion max uses at the limit accepted', 201, $limitRes['status']);
$limitId = (int) TestKit::get($limitRes, 'data.id');
$t->sql("UPDATE `promotions` SET `used_count` = 2 WHERE `id` = ?", 'i', [$limitId]);
$rename = $A('PUT', "/admin/promotions/{$limitId}", ['json' => ['code' => $renamed]]);
$t->check('a used code cannot be renamed', [422, 'CODE_IN_USE'], [$rename['status'], TestKit::get($rename, 'error.code')]);
$t->check('saving a used code under its own name is fine', 200, $A('PUT', "/admin/promotions/{$limitId}", ['json' => ['code' => strtolower($limit), 'description' => 'edited']])['status']);
$t->sql("UPDATE `promotions` SET `used_count` = 0 WHERE `id` = ?", 'i', [$limitId]);
$t->check('an unused code can be renamed', 200, $A('PUT', "/admin/promotions/{$limitId}", ['json' => ['code' => $renamed]])['status']);

// -----------------------------------------------------------------------------
$t->section('Banners');
$t->check('banner with javascript: link refused', 422, $A('POST', '/admin/storefront/banners', ['json' => ['title' => 'x', 'image_url' => '/uploads/x.jpg', 'cta_url' => 'javascript:alert(1)']])['status']);
$t->check('banner with protocol-relative link refused', 422, $A('POST', '/admin/storefront/banners', ['json' => ['title' => 'x', 'image_url' => '/uploads/x.jpg', 'cta_url' => '//evil.example']])['status']);
$res = $A('POST', '/admin/storefront/banners', ['json' => ['title' => 'Test banner', 'image_url' => '/uploads/x.jpg', 'cta_url' => '/category']]);
$t->check('valid banner created', 201, $res['status']);
$bid = (int) TestKit::get($res, 'data.id');
$t->cleanup(fn () => $t->sql("DELETE FROM `banners` WHERE `id` = ?", 'i', [$bid]));
$t->check('deleting a banner that does not exist -> 404, not "deleted"', 404, $A('DELETE', '/admin/storefront/banners/999999999')['status']);
$bannerBody = static fn (array $over): array => $over + ['title' => 'Limits', 'image_url' => '/uploads/x.jpg'];
$t->check('banner title over 255 characters refused', 422, $A('POST', '/admin/storefront/banners', ['json' => $bannerBody(['title' => str_repeat('t', 256)])])['status']);
$t->check('banner display order -5 refused', 422, $A('POST', '/admin/storefront/banners', ['json' => $bannerBody(['display_order' => -5])])['status']);
$t->check('banner display order 100001 refused', 422, $A('PUT', "/admin/storefront/banners/{$bid}", ['json' => ['display_order' => 100001]])['status']);

// -----------------------------------------------------------------------------
$t->section('Settings');
$settings = $A('GET', '/admin/settings');
$t->ok('settings expose editable allowlist', in_array('store_whatsapp_phone', array_column((array) TestKit::get($settings, 'data.editable'), 'key'), true));
$t->ok('settings never expose secrets', !preg_match('/sk_(live|test)|secret/i', $settings['body']));
$t->check('unknown setting refused', 422, $A('PUT', '/admin/settings', ['json' => ['tax_rate' => '7.5']])['status']);
$t->check('bad email refused', 422, $A('PUT', '/admin/settings', ['json' => ['store_contact_email' => 'nope']])['status']);
$nameBefore = $t->scalar("SELECT `value` FROM `store_settings` WHERE `key_name` = 'store_name'");
$t->check('a setting sent as a list is refused', 422, $A('PUT', '/admin/settings', ['json' => ['store_name' => ['x']]])['status']);
$t->check('and "Array" is not saved', $nameBefore, $t->scalar("SELECT `value` FROM `store_settings` WHERE `key_name` = 'store_name'"));
$oldWa = $t->scalar("SELECT `value` FROM `store_settings` WHERE `key_name` = 'store_whatsapp_phone'");
$t->cleanup(function () use ($t, $oldWa): void {
    if ($oldWa === null) {
        $t->sql("DELETE FROM `store_settings` WHERE `key_name` = 'store_whatsapp_phone'");
    } else {
        $t->sql("UPDATE `store_settings` SET `value` = ? WHERE `key_name` = 'store_whatsapp_phone'", 's', [(string) $oldWa]);
    }
});
$t->check('save WhatsApp number in local format', 200, $A('PUT', '/admin/settings', ['json' => ['store_whatsapp_phone' => '0800 000 0404']])['status']);
$t->check('WhatsApp number is saved as +234 and used at checkout', '+2348000000404', TestKit::get($t->http('GET', '/checkout/payment-methods'), 'data.support_phone'));
$t->check('impossible WhatsApp number refused', 422, $A('PUT', '/admin/settings', ['json' => ['store_whatsapp_phone' => '0800 000']])['status']);

// Brand strip under the homepage banner.
$oldBrands = $t->scalar("SELECT `value` FROM `store_settings` WHERE `key_name` = 'storefront_brands'");
$t->cleanup(function () use ($t, $oldBrands): void {
    if ($oldBrands === null) {
        $t->sql("DELETE FROM `store_settings` WHERE `key_name` = 'storefront_brands'");
    } else {
        $t->sql("UPDATE `store_settings` SET `value` = ? WHERE `key_name` = 'storefront_brands'", 's', [(string) $oldBrands]);
    }
});
$saved = $A('PUT', '/admin/settings', ['json' => ['storefront_brands' => ' Nike,  New   Balance,, nike , Zara ']]);
$t->check('brand list saved tidy (spaces, blanks and repeats removed)', 'Nike, New Balance, Zara', TestKit::get($saved, 'data.storefront_brands'));
$t->check('homepage sends the brand list', ['Nike', 'New Balance', 'Zara'], TestKit::get($t->http('GET', '/storefront/homepage'), 'data.brands'));
$t->check('more than 20 brands refused', 422, $A('PUT', '/admin/settings', ['json' => ['storefront_brands' => implode(',', array_map(fn ($i) => "Brand {$i}", range(1, 21)))]])['status']);
$A('PUT', '/admin/settings', ['json' => ['storefront_brands' => '']]);
$t->check('an empty list hides the strip', [], TestKit::get($t->http('GET', '/storefront/homepage'), 'data.brands'));

$t->section('Payment method switches');
$t->cleanup(function () use ($t): void {
    $t->sql("DELETE FROM `store_settings` WHERE `key_name` IN ('payment_monnify_enabled', 'payment_kora_enabled', 'payment_whatsapp_enabled', 'payment_primary_gateway')");
});
$methodIds = fn (): array => array_column((array) TestKit::get($t->http('GET', '/checkout/payment-methods'), 'data.methods'), 'id');
$t->check('switch Kora off', 200, $A('PUT', '/admin/settings/payment-methods', ['json' => ['kora' => false]])['status']);
$t->ok('checkout no longer offers Kora', !in_array('kora', $methodIds(), true));
$t->ok('settings report Kora as switched off', TestKit::get($A('GET', '/admin/settings'), 'data.readonly.payment_methods.kora.enabled') === false);
$t->check('switching every method off is refused', 422, $A('PUT', '/admin/settings/payment-methods', ['json' => ['monnify' => false, 'kora' => false, 'whatsapp' => false]])['status']);
$t->check('switch Kora back on', 200, $A('PUT', '/admin/settings/payment-methods', ['json' => ['kora' => true]])['status']);
$t->check('checkout offers Kora again (when it has keys)', \App\Services\PaymentMethods::configured('kora'), in_array('kora', $methodIds(), true));
$payNow = static fn (): ?string => TestKit::get($t->http('GET', '/checkout/payment-methods'), 'data.pay_now_gateway');
// Which gateways have keys on this server decides the right answer ("Pay now" never points at a
// gateway that isn't set up: its button could only fail).
$readonly = (array) TestKit::get($A('GET', '/admin/settings'), 'data.readonly');
$ready = ['monnify' => ($readonly['monnify_mode'] ?? '') !== 'not_configured', 'kora' => ($readonly['kora_mode'] ?? '') !== 'not_configured'];
$expect = static function (string $primary) use ($ready): ?string {
    if ($ready[$primary]) {
        return $primary;
    }
    $other = $primary === 'monnify' ? 'kora' : 'monnify';
    return $ready[$other] ? $other : null;
};
$t->check('"Pay now" uses Monnify by default (when it is set up)', $expect('monnify'), $payNow());
$t->check('an unknown "Pay now" gateway is refused', 422, $A('PUT', '/admin/settings/payment-methods', ['json' => ['primary_gateway' => 'paypal']])['status']);
$t->check('the admin can choose Kora for "Pay now"', 'kora', TestKit::get($A('PUT', '/admin/settings/payment-methods', ['json' => ['primary_gateway' => 'kora']]), 'data.primary_gateway'));
$t->check('checkout now uses Kora (or the other gateway if Kora has no keys)', $expect('kora'), $payNow());
$A('PUT', '/admin/settings/payment-methods', ['json' => ['kora' => false]]);
$t->check('with Kora switched off, "Pay now" falls back to Monnify (if set up)', $ready['monnify'] ? 'monnify' : null, $payNow());
$A('PUT', '/admin/settings/payment-methods', ['json' => ['kora' => true, 'primary_gateway' => 'monnify']]);
$t->check('settings show the choice', 'monnify', TestKit::get($A('GET', '/admin/settings'), 'data.readonly.primary_gateway'));
$t->check('settings say which gateway "Pay now" really uses', $expect('monnify'), TestKit::get($A('GET', '/admin/settings'), 'data.readonly.pay_now_gateway'));

// -----------------------------------------------------------------------------
$t->section('System status');
$sys = $A('GET', '/admin/system-status');
$t->ok('system status reports gateways, outbox and orders', TestKit::get($sys, 'data.payments.kora') !== null
    && TestKit::get($sys, 'data.mail.outbox_pending') !== null
    && TestKit::get($sys, 'data.orders.needs_attention') !== null);

// -----------------------------------------------------------------------------
$t->section('Newsletter');
$nemail = $t->uniq('news') . '@example.test';
$t->cleanup(fn () => $t->sql("DELETE FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$nemail]));
$t->check('subscribe', 200, $t->http('POST', '/newsletter/subscribe', ['json' => ['email' => $nemail]])['status']);
$ntoken = (string) $t->scalar("SELECT `unsubscribe_token` FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$nemail]);
$t->check('subscriber gets an unsubscribe token', 32, strlen($ntoken));
$t->check('every subscriber has a token', 0, (int) $t->scalar("SELECT COUNT(*) FROM `newsletter_subscribers` WHERE `unsubscribe_token` IS NULL"));
$active = (int) TestKit::get($A('GET', '/admin/marketing/subscribers'), 'data.counts.active');
$t->check('broadcast refused when the confirmed count is stale', 409, $A('POST', '/admin/marketing/broadcast', ['json' => ['subject' => 'Hi', 'content' => 'Hello', 'confirm_recipients' => $active + 5]])['status']);
$newsSubject = $t->uniq('Newsletter');
$t->cleanup(fn () => $t->sql("DELETE FROM `email_outbox` WHERE `template` = 'marketing_broadcast' AND JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.subject')) = ?", 's', [$newsSubject]));
$first = $A('POST', '/admin/marketing/broadcast', ['json' => ['subject' => $newsSubject, 'content' => 'Hello', 'confirm_recipients' => $active]]);
$t->check('broadcast queued for every active subscriber', [200, $active, 0], [$first['status'], TestKit::get($first, 'data.queued_count'), TestKit::get($first, 'data.already_queued_today')]);
$again = $A('POST', '/admin/marketing/broadcast', ['json' => ['subject' => $newsSubject, 'content' => 'Hello', 'confirm_recipients' => $active]]);
$t->check('sending the same newsletter again the same day queues nobody twice', [200, 0, $active], [$again['status'], TestKit::get($again, 'data.queued_count'), TestKit::get($again, 'data.already_queued_today')]);
$t->check('one email per subscriber', $active, (int) $t->scalar("SELECT COUNT(*) FROM `email_outbox` WHERE `template` = 'marketing_broadcast' AND JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.subject')) = ?", 's', [$newsSubject]));
$t->check('unsubscribe with bad token refused', 404, $t->http('POST', '/newsletter/unsubscribe', ['json' => ['token' => '0123456789abcdef0123456789abcdef']])['status']);
$t->check('unsubscribe', 200, $t->http('POST', '/newsletter/unsubscribe', ['json' => ['token' => $ntoken]])['status']);
$t->check('subscriber marked unsubscribed', 'unsubscribed', $t->scalar("SELECT `status` FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$nemail]));

// -----------------------------------------------------------------------------
$t->section('Refund eligibility');
$t->check('eligibility for unknown order is 404', 404, $A('GET', '/admin/refunds/eligibility?order_number=ORD-NOPE')['status']);

// -----------------------------------------------------------------------------
$t->section('Customers');
$cemail = $t->uniq('cust') . '@example.test';
$cuid = (int) $t->db->insert(
    "INSERT INTO `users` (`uuid`, `email`, `full_name`, `role`, `status`, `email_verified_at`) VALUES (UUID(), ?, 'Test Customer', 'customer', 'active', NOW())",
    's',
    [$cemail]
);
$t->cleanup(fn () => $t->sql("DELETE FROM `users` WHERE `id` = ?", 'i', [$cuid]));
$t->sql(
    "INSERT INTO `sessions` (`id`, `user_id`, `last_activity`, `expires_at`) VALUES (SHA2(?, 256), ?, UNIX_TIMESTAMP(), DATE_ADD(NOW(), INTERVAL 1 DAY))",
    'si',
    [$t->uniq('sess'), $cuid]
);
$t->check('invalid customer status refused', 422, $A('PATCH', "/admin/customers/{$cuid}/status", ['json' => ['status' => 'unverified']])['status']);
$t->check('suspend customer', 200, $A('PATCH', "/admin/customers/{$cuid}/status", ['json' => ['status' => 'suspended']])['status']);
$t->check('suspension signs the customer out', 0, (int) $t->scalar("SELECT COUNT(*) FROM `sessions` WHERE `user_id` = ?", 'i', [$cuid]));
$t->check('customer detail loads', $cemail, TestKit::get($A('GET', "/admin/customers/{$cuid}"), 'data.customer.email'));
// Sign-up saves customers as active: "not verified" means the email was never confirmed.
$unverifiedEmail = $t->uniq('unverified') . '@example.test';
$unverifiedId = (int) $t->db->insert("INSERT INTO `users` (`uuid`, `email`, `full_name`, `role`, `status`) VALUES (UUID(), ?, 'New Customer', 'customer', 'active')", 's', [$unverifiedEmail]);
$t->cleanup(fn () => $t->sql("DELETE FROM `users` WHERE `id` = ?", 'i', [$unverifiedId]));
$unverifiedList = array_column((array) TestKit::get($A('GET', '/admin/customers?status=unverified&per_page=100'), 'data.items'), 'email');
$t->ok('"Email not verified" lists a customer who never confirmed', in_array($unverifiedEmail, $unverifiedList, true));
$t->ok('and not one who did', !in_array($cemail, $unverifiedList, true));

// -----------------------------------------------------------------------------
$t->section('Order lifecycle');
$variant = $t->variant(3);
if ($variant === null) {
    $t->ok('a published variant with stock exists', false);
} else {
    $email = $t->uniq('buyer') . '@example.test';
    $res = $t->http('POST', '/checkout/orders', [
        'headers' => ['Idempotency-Key: ' . bin2hex(random_bytes(16))],
        'json' => [
            'email' => $email,
            'items' => [['product_id' => (int) $variant['product_id'], 'variant_id' => (int) $variant['variant_id'], 'quantity' => 1]],
            'shipping_address' => ['full_name' => 'Admin Lifecycle', 'phone' => '08000000004', 'address_line1' => '4 Test Close', 'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria'],
        ],
    ]);
    $t->check('guest order created', 201, $res['status']);
    $onum = (string) TestKit::get($res, 'data.order_number');
    $total = (float) TestKit::get($res, 'data.grand_total');
    $oid = (int) $t->scalar("SELECT `id` FROM `orders` WHERE `order_number` = ?", 's', [$onum]);
    $t->cleanup(function () use ($t, $oid, $email): void {
        foreach (['refunds', 'shipments', 'payments', 'order_status_history', 'order_items'] as $table) {
            $t->sql("DELETE FROM `{$table}` WHERE `order_id` = ?", 'i', [$oid]);
        }
        $t->sql("DELETE FROM `orders` WHERE `id` = ?", 'i', [$oid]);
        $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$email]);
    });

    $outboxKey = static fn (string $key): int => (int) $t->scalar("SELECT COUNT(*) FROM `email_outbox` WHERE `dedup_key` = ?", 's', [$key]);
    $adminEmail = strtolower($admin['email']);
    $t->check('admins are emailed about the new order', 1, $outboxKey("admin_new_order:{$onum}:{$adminEmail}"));
    $detail = $A('GET', "/admin/orders/{$onum}");
    $t->check('admin sees the new order', 'pending_payment', TestKit::get($detail, 'data.order_status'));
    $t->check('unpaid order can only be cancelled', ['cancelled'], TestKit::get($detail, 'data.allowed_transitions'));
    $t->check('shipping an unpaid order is refused', 422, $A('POST', "/admin/orders/{$onum}/shipment", ['json' => ['carrier' => 'GIG', 'tracking_number' => 'T1', 'status' => 'in_transit']])['status']);
    $t->check('manual payment needs a reference', 422, $A('POST', "/admin/orders/{$onum}/confirm-payment", ['json' => ['reference' => 'ab']])['status']);
    $t->check('record manual payment', 200, $A('POST', "/admin/orders/{$onum}/confirm-payment", ['json' => ['reference' => 'BANK-' . bin2hex(random_bytes(4))]])['status']);
    $detail = $A('GET', "/admin/orders/{$onum}");
    $t->check('order is paid', 'paid', TestKit::get($detail, 'data.order_status'));
    $t->check('admins are emailed that the payment was received', 1, $outboxKey("admin_payment_received:{$onum}:{$adminEmail}"));
    $t->check('paid -> processing', 'processing', TestKit::get($A('PATCH', "/admin/orders/{$onum}/status", ['json' => ['status' => 'processing']]), 'data.order_status'));
    $t->check('the customer is emailed that the order is being prepared', 1, $outboxKey("order_status_update:{$onum}:processing"));
    $t->ok('paid order can be shipped', in_array('shipped', (array) TestKit::get($detail, 'data.allowed_transitions'), true));
    $t->check('PATCH status paid is refused', 422, $A('PATCH', "/admin/orders/{$onum}/status", ['json' => ['status' => 'paid']])['status']);

    $t->check('ship order', 200, $A('POST', "/admin/orders/{$onum}/shipment", ['json' => ['carrier' => 'GIG Logistics', 'tracking_number' => 'TRK' . random_int(1000, 9999), 'tracking_url' => 'https://example.test/track', 'status' => 'in_transit']])['status']);
    $t->check('order is shipped', 'shipped', TestKit::get($A('GET', "/admin/orders/{$onum}"), 'data.order_status'));
    $deliveredRes = $A('POST', "/admin/orders/{$onum}/delivered", ['json' => ['comment' => 'Signed for']]);
    $t->check('mark delivered', 200, $deliveredRes['status']);
    $t->ok('the answer keeps the activity log, including what was just done', count((array) TestKit::get($deliveredRes, 'data.audit_logs')) >= 1);
    $t->check('order is delivered', 'delivered', TestKit::get($A('GET', "/admin/orders/{$onum}"), 'data.order_status'));
    $t->check('the customer is emailed that the order was delivered', 1, $outboxKey("order_status_update:{$onum}:delivered"));
    $t->check('attention filter works', 200, $A('GET', '/admin/orders?status=attention')['status']);
    $t->ok('order list includes customer email', TestKit::get($A('GET', '/admin/orders?search=' . urlencode($onum)), 'data.items.0.customer_email') === $email);

    $elig = $A('GET', '/admin/refunds/eligibility?order_number=' . urlencode($onum));
    $t->check('refundable = amount paid', round($total, 2), round((float) TestKit::get($elig, 'data.refundable_amount'), 2));
    $partial = round($total / 2, 2);
    $res = $A('POST', '/admin/refunds', ['json' => ['order_number' => $onum, 'amount' => $partial, 'reason' => 'Test partial refund', 'status' => 'completed', 'restock' => false]]);
    $t->check('partial refund recorded', 201, $res['status']);
    $t->check('payment is partially refunded', 'partially_refunded', $t->scalar("SELECT `payment_status` FROM `orders` WHERE `id` = ?", 'i', [$oid]));
    $t->check('refund over the remaining amount refused', 422, $A('POST', '/admin/refunds', ['json' => ['order_number' => $onum, 'amount' => $total, 'reason' => 'Too much', 'status' => 'completed', 'restock' => false]])['status']);
    $t->check('remaining refundable shrinks', round($total - $partial, 2), round((float) TestKit::get($A('GET', '/admin/refunds/eligibility?order_number=' . urlencode($onum)), 'data.refundable_amount'), 2));

    // A refund request declined on an order that was never sent: back to processing, so it can ship.
    $email2 = $t->uniq('buyer2') . '@example.test';
    $res2 = $t->http('POST', '/checkout/orders', [
        'headers' => ['Idempotency-Key: ' . bin2hex(random_bytes(16))],
        'json' => [
            'email' => $email2,
            'items' => [['product_id' => (int) $variant['product_id'], 'variant_id' => (int) $variant['variant_id'], 'quantity' => 1]],
            'shipping_address' => ['full_name' => 'Declined Refund', 'phone' => '08000000004', 'address_line1' => '4 Test Close', 'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria'],
        ],
    ]);
    $onum2 = (string) TestKit::get($res2, 'data.order_number');
    $oid2 = (int) $t->scalar("SELECT `id` FROM `orders` WHERE `order_number` = ?", 's', [$onum2]);
    $t->cleanup(function () use ($t, $oid2, $email2): void {
        foreach (['refunds', 'shipments', 'payments', 'order_status_history', 'order_items'] as $table) {
            $t->sql("DELETE FROM `{$table}` WHERE `order_id` = ?", 'i', [$oid2]);
        }
        $t->sql("DELETE FROM `orders` WHERE `id` = ?", 'i', [$oid2]);
        $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$email2]);
    });
    $A('POST', "/admin/orders/{$onum2}/confirm-payment", ['json' => ['reference' => 'BANK-' . bin2hex(random_bytes(4))]]);
    $t->check('a shipping label can be created before dispatch', 200, $A('POST', "/admin/orders/{$onum2}/shipment", ['json' => ['carrier' => 'GIG Logistics', 'tracking_number' => 'LBL' . random_int(1000, 9999), 'status' => 'label_created']])['status']);
    $pending = $A('PATCH', "/admin/orders/{$onum2}/status", ['json' => ['status' => 'refund_pending', 'comment' => 'Customer asked for a refund']]);
    $t->check('an unsent paid order can be marked refund pending', 'refund_pending', TestKit::get($pending, 'data.order_status'));
    $t->check('declining it offers "processing" (a label alone is not "sent")', ['processing'], TestKit::get($pending, 'data.allowed_transitions'));
    $pendingRefund = $A('POST', '/admin/refunds', ['json' => ['order_number' => $onum2, 'amount' => 100, 'reason' => 'Requested by the customer', 'status' => 'pending', 'restock' => true]]);
    $t->check('a pending refund can be recorded while the request is open', 201, $pendingRefund['status']);
    $t->check('"delivered" is refused for an order that was never sent', 422, $A('PATCH', "/admin/orders/{$onum2}/status", ['json' => ['status' => 'delivered']])['status']);
    $t->check('declined: back to processing', 'processing', TestKit::get($A('PATCH', "/admin/orders/{$onum2}/status", ['json' => ['status' => 'processing', 'comment' => 'Refund declined']]), 'data.order_status'));
    $t->check('declining the request also closes the pending refund (it cannot be completed later)', 'rejected', $t->scalar("SELECT `status` FROM `refunds` WHERE `order_id` = ? ORDER BY `id` DESC LIMIT 1", 'i', [$oid2]));
    $t->check('a declined refund moving the order back sends no "being prepared" email', 0, $outboxKey("order_status_update:{$onum2}:processing"));
    $t->check('and it can now be shipped', 200, $A('POST', "/admin/orders/{$onum2}/shipment", ['json' => ['carrier' => 'GIG Logistics', 'tracking_number' => 'TRK' . random_int(1000, 9999), 'status' => 'in_transit']])['status']);

    $lowOrOut = $A('GET', '/admin/inventory?filter=low_or_out&per_page=100');
    $rows = (array) TestKit::get($lowOrOut, 'data.items');
    $t->ok('"low or sold out" lists sold-out and low variants of products on sale, like the overview counts', $lowOrOut['status'] === 200 && array_reduce($rows, static fn (bool $ok, array $r): bool => $ok && (int) $r['stock_quantity'] <= (int) $r['low_stock_threshold'] && $r['product_status'] !== 'archived', true));

    foreach (['order_received', 'payment_verified', 'shipment_tracking', 'refund_processed'] as $tpl) {
        $row = $t->db->fetchOne("SELECT `status`, `transport` FROM `email_outbox` WHERE `recipient_email` = ? AND `template` = ? ORDER BY `id` DESC LIMIT 1", 'ss', [$email, $tpl]);
        $t->check("{$tpl} email logged", 'sent/log', $row ? "{$row['status']}/{$row['transport']}" : null);
    }

    // -------------------------------------------------------------------------
    $t->section('Order page: flags, customer email, refund figures, input limits');
    $email3 = $t->uniq('buyer3') . '@example.test';
    $res3 = $t->http('POST', '/checkout/orders', [
        'headers' => ['Idempotency-Key: ' . bin2hex(random_bytes(16))],
        'json' => [
            'email' => $email3,
            'items' => [['product_id' => (int) $variant['product_id'], 'variant_id' => (int) $variant['variant_id'], 'quantity' => 1]],
            'shipping_address' => ['full_name' => 'Flagged Order', 'phone' => '08000000004', 'address_line1' => '4 Test Close', 'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria'],
        ],
    ]);
    $onum3 = (string) TestKit::get($res3, 'data.order_number');
    $total3 = (float) TestKit::get($res3, 'data.grand_total');
    $oid3 = (int) $t->scalar("SELECT `id` FROM `orders` WHERE `order_number` = ?", 's', [$onum3]);
    $t->cleanup(function () use ($t, $oid3, $email3): void {
        foreach (['refunds', 'shipments', 'payments', 'order_status_history', 'order_items'] as $table) {
            $t->sql("DELETE FROM `{$table}` WHERE `order_id` = ?", 'i', [$oid3]);
        }
        $t->sql("DELETE FROM `orders` WHERE `id` = ?", 'i', [$oid3]);
        $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$email3]);
    });

    $resolve = static fn (array $body, array $opts = []): array => $A('POST', "/admin/orders/{$onum3}/resolve-attention", $opts + ['json' => $body]);
    $notFlagged = $resolve(['note' => 'Nothing to do']);
    $t->check('resolving an order that is not flagged: 422', [422, 'NOT_FLAGGED'], [$notFlagged['status'], TestKit::get($notFlagged, 'error.code')]);
    $t->sql("UPDATE `orders` SET `requires_attention` = 1, `attention_reason` = 'Overpaid by NGN 5.00' WHERE `id` = ?", 'i', [$oid3]);
    $t->check('resolving needs a sign-in', 401, $t->http('POST', "/admin/orders/{$onum3}/resolve-attention", ['json' => ['note' => 'Dealt with']])['status']);
    $t->check('resolving needs the CSRF token', 403, $t->http('POST', "/admin/orders/{$onum3}/resolve-attention", ['json' => ['note' => 'Dealt with'], 'jar' => $admin['jar']])['status']);
    foreach (['too short' => 'ab', 'too long' => str_repeat('n', 501), 'not text' => ['x'], 'empty' => '   '] as $label => $badNote) {
        $t->check("resolving with a note that is {$label}: 422", 422, $resolve(['note' => $badNote])['status']);
    }
    $t->check('resolving with no note: 422', 422, $resolve([])['status']);
    $t->check('none of those cleared the flag', 1, (int) $t->scalar("SELECT `requires_attention` FROM `orders` WHERE `id` = ?", 'i', [$oid3]));
    $resolved = $resolve(['note' => 'Refunded the extra from the bank app']);
    $t->check('resolved', 200, $resolved['status']);
    $t->check('the answer is the full order', [$onum3, false, true, true], [
        TestKit::get($resolved, 'data.order_number'),
        TestKit::get($resolved, 'data.requires_attention'),
        is_array(TestKit::get($resolved, 'data.items')),
        is_array(TestKit::get($resolved, 'data.audit_logs')),
    ]);
    $flagRow = $t->db->fetchOne("SELECT `requires_attention`, `attention_reason` FROM `orders` WHERE `id` = ?", 'i', [$oid3]);
    $t->check('the flag is cleared', [0, null], [(int) $flagRow['requires_attention'], $flagRow['attention_reason']]);
    $t->ok('the history keeps the note and the old reason', str_contains((string) json_encode(TestKit::get($resolved, 'data.history')), 'Refunded the extra from the bank app')
        && str_contains((string) json_encode(TestKit::get($resolved, 'data.history')), 'Overpaid by NGN 5.00'));
    $t->check('the audit log records who resolved it', 1, (int) $t->scalar("SELECT COUNT(*) FROM `admin_audit_logs` WHERE `action` = 'order.attention_resolved' AND `entity_id` = ? AND `admin_id` = ?", 'si', [(string) $oid3, $adminId]));

    $t->check('the order page shows a guest\'s email', $email3, TestKit::get($A('GET', "/admin/orders/{$onum3}"), 'data.customer_email'));
    $t->sql("UPDATE `orders` SET `user_id` = ? WHERE `id` = ?", 'ii', [$cuid, $oid3]);
    $t->check('and a signed-in customer\'s', $cemail, TestKit::get($A('GET', "/admin/orders/{$onum3}"), 'data.customer_email'));

    $A('POST', "/admin/orders/{$onum3}/confirm-payment", ['json' => ['reference' => 'BANK-' . bin2hex(random_bytes(4))]]);
    $found = (array) TestKit::get($A('GET', '/admin/payments?search=' . urlencode($cemail)), 'data.items');
    $t->check('Payments search finds a signed-in customer by email', [$onum3], array_column($found, 'order_number'));

    $t->check('carrier over 100 characters: 422', 422, $A('POST', "/admin/orders/{$onum3}/shipment", ['json' => ['carrier' => str_repeat('c', 101)]])['status']);
    $t->check('tracking number over 100 characters: 422', 422, $A('POST', "/admin/orders/{$onum3}/shipment", ['json' => ['carrier' => 'GIG', 'tracking_number' => str_repeat('t', 101)]])['status']);
    $t->check('a refund reference over 100 characters: 422', 422, $A('POST', '/admin/refunds', ['json' => ['order_number' => $onum3, 'amount' => 1, 'reason' => 'Long ref', 'provider_refund_reference' => str_repeat('r', 101)]])['status']);
    $t->check('a refund amount sent as a list: 422', 422, $A('POST', '/admin/refunds', ['json' => ['order_number' => $onum3, 'amount' => [1], 'reason' => 'List']])['status']);

    $A('POST', '/admin/refunds', ['json' => ['order_number' => $onum3, 'amount' => $total3, 'reason' => 'All of it', 'status' => 'completed', 'restock' => true]]);
    $figures = (array) TestKit::get($A('GET', '/admin/refunds/eligibility?order_number=' . urlencode($onum3)), 'data');
    $t->check('"Check order" on a fully refunded order shows the true figures', [round($total3, 2), round($total3, 2), 0.0], [(float) $figures['paid_amount'], (float) $figures['refunded_amount'], (float) $figures['refundable_amount']]);
    $t->ok('and the payment it was', ($figures['payment_reference'] ?? null) !== null);
}

// -----------------------------------------------------------------------------
$t->section('Paging: an absurd page number is a clear 422');
foreach (['orders', 'payments', 'customers', 'products'] as $path) {
    $t->check("GET {$path}?page=99999999999999999999", 422, $A('GET', "/admin/{$path}?page=99999999999999999999")['status']);
}
$t->check('a page past the end is just empty', 200, $A('GET', '/admin/orders?page=999999')['status']);

// -----------------------------------------------------------------------------
$t->section('Products: slugs, names, prices and stock');
$pstamp = bin2hex(random_bytes(3));
$productBody = static fn (array $over = []): array => $over + ['name' => "Limit Test {$pstamp}", 'slug' => "limit-test-{$pstamp}", 'sku' => "LIM-{$pstamp}", 'base_price' => 1000, 'status' => 'draft'];
$madeProducts = [];
$makeProduct = static function (array $body) use ($A, $t, &$madeProducts): array {
    $res = $A('POST', '/admin/products', ['json' => $body]);
    if ($res['status'] === 201) {
        $madeProducts[] = (int) TestKit::get($res, 'data.id');
    }
    return $res;
};
$t->cleanup(function () use ($t, &$madeProducts): void {
    foreach ($madeProducts as $id) {
        $t->sql("DELETE FROM `inventory_logs` WHERE `product_id` = ?", 'i', [$id]);
        $t->sql("DELETE FROM `products` WHERE `id` = ?", 'i', [$id]);
    }
});

foreach (['501', '2e3'] as $numericSlug) {
    $bad = $makeProduct($productBody(['slug' => $numericSlug]));
    $t->check("product slug {$numericSlug} refused: it would read as an ID", [422, true], [$bad['status'], str_contains((string) TestKit::get($bad, 'error.details.slug'), 'only a number')]);
}
$auto = $makeProduct($productBody(['name' => '501', 'slug' => '', 'sku' => "LIM-A-{$pstamp}"]));
$t->check('no slug given, name "501": slug product-501', [201, 'product-501'], [$auto['status'], TestKit::get($auto, 'data.slug')]);
$auto2 = $makeProduct(['name' => '501', 'sku' => "LIM-B-{$pstamp}", 'base_price' => 1000, 'status' => 'draft']);
$t->check('a second one gets its own slug', [201, 'product-501-2'], [$auto2['status'], TestKit::get($auto2, 'data.slug')]);
$t->check('and it is stored under that slug', 'product-501', TestKit::get($A('GET', '/admin/products?search=product-501&per_page=1&sort=oldest'), 'data.0.slug'));
$named = $makeProduct($productBody(['name' => "Blue Tee {$pstamp}", 'slug' => '', 'sku' => "LIM-C-{$pstamp}"]));
$t->check('a slug is made from an ordinary name too', "blue-tee-{$pstamp}", TestKit::get($named, 'data.slug'));

// Each is refused for its own field, not for some other reason.
$refusedFor = static fn (array $over): array => (static function (array $res): array {
    return [$res['status'], array_keys((array) TestKit::get($res, 'error.details'))];
})($makeProduct($productBody($over)));
$t->check('price 999,999,999,999 refused', [422, ['base_price']], $refusedFor(['base_price' => 999999999999]));
$t->check('price just over the limit refused', [422, ['base_price']], $refusedFor(['base_price' => 100000000]));
$t->check('an impossible "was" price refused', [422, ['original_price']], $refusedFor(['original_price' => 100000000]));
$t->check('a category that does not exist: 422', [422, ['category_id']], $refusedFor(['category_id' => 99999999]));
$t->check('a category sent as text: 422', [422, ['category_id']], $refusedFor(['category_id' => 'shirts']));
$longVariant = ['size' => str_repeat('s', 51), 'color_name' => 'Black', 'color_hex' => '#000000', 'stock_quantity' => 1];
$t->check('a variant size over 50 characters: 422', [422, ['variants']], $refusedFor(['variants' => [$longVariant]]));
$t->check('a variant colour over 50 characters: 422', [422, ['variants']], $refusedFor(['variants' => [['color_name' => str_repeat('c', 51), 'size' => 'M'] + $longVariant]]));
$t->check('a variant with too much stock: 422', [422, ['variants']], $refusedFor(['variants' => [['size' => 'M', 'stock_quantity' => 1000001] + $longVariant]]));
$t->check('a variant with an impossible price: 422', [422, ['variants']], $refusedFor(['variants' => [['size' => 'M', 'price_override' => 100000000] + $longVariant]]));
$t->check('product name sent as a list: 422', [422, ['name']], $refusedFor(['name' => ['x']]));

$spaced = $makeProduct($productBody(['name' => "Top   Wear  {$pstamp}", 'base_price' => 99999999.99]));
$t->check('the highest price is accepted', [201, 99999999.99], [$spaced['status'], (float) TestKit::get($spaced, 'data.base_price')]);
$t->check('repeated spaces in a name are collapsed on save', "Top Wear {$pstamp}", TestKit::get($spaced, 'data.name'));
$spacedId = (int) TestKit::get($spaced, 'data.id');
$t->check('and on update', "Top Wear Two {$pstamp}", TestKit::get($A('PUT', "/admin/products/{$spacedId}", ['json' => ['name' => "Top  Wear  Two {$pstamp}"]]), 'data.name'));
$t->check('admin product search collapses the term', [$spacedId], array_map('intval', array_column((array) TestKit::get($A('GET', '/admin/products?search=' . urlencode("Top  Wear   Two {$pstamp}")), 'data'), 'id')));
$t->check('and so does inventory search', [$spacedId], array_map('intval', array_column((array) TestKit::get($A('GET', '/admin/inventory?search=' . urlencode("Top  Wear   Two {$pstamp}")), 'data.items'), 'product_id')));
$t->check('price too large on update: 422', 422, $A('PUT', "/admin/products/{$spacedId}", ['json' => ['base_price' => 100000000]])['status']);
$t->check('category that does not exist on update: 422', 422, $A('PUT', "/admin/products/{$spacedId}", ['json' => ['category_id' => 99999999]])['status']);
$t->check('renaming the slug to a number: 422', 422, $A('PUT', "/admin/products/{$spacedId}", ['json' => ['slug' => '2e3']])['status']);

$limitVariant = (int) $t->scalar("SELECT `id` FROM `product_variants` WHERE `product_id` = ? LIMIT 1", 'i', [$spacedId]);
$stockNow = static fn (): int => (int) $t->scalar("SELECT `stock_quantity` FROM `product_variants` WHERE `id` = ?", 'i', [$limitVariant]);
$adjust = static fn (mixed $by): array => $A('POST', '/admin/inventory/adjust', ['json' => ['variant_id' => $limitVariant, 'change_amount' => $by, 'reason' => 'Limit test']]);
$start = $stockNow();
$t->check('stock adjust +99,999,999,999 refused', [422, ['adjustment']], (static fn (array $r): array => [$r['status'], array_keys((array) TestKit::get($r, 'error.details'))])($adjust(99999999999)));
$t->check('stock adjust of part of a unit refused', [422, ['adjustment']], (static fn (array $r): array => [$r['status'], array_keys((array) TestKit::get($r, 'error.details'))])($adjust(1.5)));
$t->check('a refused adjust changes nothing', $start, $stockNow());
$t->check('adjusting up to the limit is fine', 1000000, TestKit::get($adjust(1000000 - $start), 'data.new_stock'));
$over = $adjust(500);
$t->check('going over the limit is refused, saying the limit', [422, true], [$over['status'], str_contains((string) TestKit::get($over, 'error.message'), '1,000,000')]);
$t->check('and nothing was logged for it', 1, (int) $t->scalar("SELECT COUNT(*) FROM `inventory_logs` WHERE `variant_id` = ?", 'i', [$limitVariant]));
$t->check('adjusting down works', 999995, TestKit::get($adjust(-5), 'data.new_stock'));
// Stock an old non-strict database capped at the column's limit does not block saving the product.
$t->sql("UPDATE `product_variants` SET `stock_quantity` = 2147483647 WHERE `id` = ?", 'i', [$limitVariant]);
$oldRow = ['id' => $limitVariant, 'size' => 'Standard', 'color_name' => 'Default', 'color_hex' => '#000000'];
$t->check('a product with old over-limit stock can still be saved', 200, $A('PUT', "/admin/products/{$spacedId}", ['json' => ['variants' => [$oldRow + ['stock_quantity' => 2147483647, 'original_stock_quantity' => 2147483647]]]])['status']);
$t->check('and its stock corrected', [200, 100], (function () use ($A, $spacedId, $oldRow, $stockNow): array {
    $res = $A('PUT', "/admin/products/{$spacedId}", ['json' => ['variants' => [$oldRow + ['stock_quantity' => 100, 'original_stock_quantity' => 2147483647]]]]);
    return [$res['status'], $stockNow()];
})());

// -----------------------------------------------------------------------------
$t->section('Admin account');
$second = 'admin-second-' . $adminId;
$t->http('POST', '/auth/login', ['json' => ['email' => $admin['email'], 'password' => $admin['password']], 'jar' => $second]);
$t->check('an over-long phone on the admin account refused', 422, $A('PUT', '/admin/account', ['json' => ['full_name' => 'Test adminapi', 'phone' => str_repeat('0', 51)]])['status']);
$t->check('an over-long name on the admin account refused', 422, $A('PUT', '/admin/account', ['json' => ['full_name' => str_repeat('n', 256)]])['status']);
$t->check('a new password with a zero byte refused', 422, $A('PUT', '/admin/account', ['json' => ['full_name' => 'Test adminapi', 'current_password' => $admin['password'], 'new_password' => "another\u{0}long-pass"]])['status']);
$t->check('wrong current password refused', 422, $A('PUT', '/admin/account', ['json' => ['full_name' => 'Test adminapi', 'current_password' => 'nope', 'new_password' => 'another-long-pass']])['status']);
$t->check('change password', 200, $A('PUT', '/admin/account', ['json' => ['full_name' => 'Test adminapi', 'current_password' => $admin['password'], 'new_password' => $admin['password'] . '-new']])['status']);
$t->check('other admin session signed out', 401, $t->http('GET', '/admin/account', ['jar' => $second])['status']);
$t->check('current session still works', 200, $A('GET', '/admin/account')['status']);

exit($t->finish());
