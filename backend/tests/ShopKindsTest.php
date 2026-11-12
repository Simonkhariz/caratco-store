<?php

declare(strict_types=1);

/**
 * One code base, several kinds of shop (backend/config/shop.json, App\Config\Shop). With the
 * fixture shop (clothing: sizes and colours, no warranties, heavy items or age checks) everything
 * must behave as it always has; another kind of shop gets its own option names, its "none" words
 * hidden, specifications and warranties, a fee for heavy items and an age confirmation.
 *
 * The other kind of shop is a second API on the same database (TestKit::startApi), and
 * Shop::overrideForTests() for what is checked inside this process.
 *
 *   php backend/tests/run.php ShopKinds
 */

require __DIR__ . '/support/TestKit.php';

use App\Config\Env;
use App\Config\Shop;
use App\Repositories\ProductRepository;
use App\Services\CheckoutService;
use App\Services\MailTemplates;
use App\Support\SessionCookie;
use App\Validators\ProductValidator;

$t = new TestKit('ShopKinds');
$admin = $t->adminSession('kinds');
$A = static fn (string $method, string $path, array $opts = []): array
    => $t->http($method, $path, $opts + ['jar' => $admin['jar'], 'csrf' => $admin['csrf']]);
$stamp = getmypid() . bin2hex(random_bytes(2));
$details = static fn (array $res): array => array_keys((array) TestKit::get($res, 'error.details'));

// Products made here are removed again, after the orders that refer to them.
$madeProducts = [];
$t->cleanup(function () use ($t, &$madeProducts): void {
    foreach ($madeProducts as $id) {
        $t->sql("DELETE FROM `inventory_logs` WHERE `product_id` = ?", 'i', [$id]);
        $t->sql("DELETE FROM `products` WHERE `id` = ?", 'i', [$id]);
    }
});
$placed = [];
$buyers = [];
$t->cleanup(function () use ($t, &$placed, &$buyers): void {
    foreach ($placed as $oid) {
        foreach (['payments', 'order_status_history', 'order_items'] as $table) {
            $t->sql("DELETE FROM `{$table}` WHERE `order_id` = ?", 'i', [$oid]);
        }
        $t->sql("DELETE FROM `orders` WHERE `id` = ?", 'i', [$oid]);
    }
    foreach ($buyers as $email) {
        $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$email]);
    }
});

$tshirts = (int) $t->scalar("SELECT `id` FROM `categories` WHERE `slug` = 't-shirts'");
$accessories = (int) $t->scalar("SELECT `id` FROM `categories` WHERE `slug` = 'accessories'");
$makeProduct = static function (string $label, array $over = []) use ($A, $stamp, $tshirts, &$madeProducts): array {
    $res = $A('POST', '/admin/products', ['json' => $over + [
        'name' => "Kinds {$label} {$stamp}",
        'slug' => "kinds-{$label}-{$stamp}",
        'sku' => strtoupper("KND-{$label}-{$stamp}"),
        'base_price' => 5000,
        'status' => 'published',
        'category_id' => $tshirts,
    ]]);
    if ($res['status'] === 201) {
        $madeProducts[] = (int) TestKit::get($res, 'data.id');
    }
    return $res;
};
$update = static fn (int $id, array $body): array => $A('PUT', "/admin/products/{$id}", ['json' => $body]);

/** Place an order as a guest on the given API; the order is removed when the suite ends. */
$order = static function (string $api, array $items, array $extra = []) use ($t, &$placed, &$buyers): array {
    $email = $t->uniq('t-kinds') . '@example.test';
    $buyers[] = $email;
    $res = $t->http('POST', "{$api}/checkout/orders", [
        'headers' => ['Idempotency-Key: ' . bin2hex(random_bytes(16))],
        'json' => $extra + [
            'email' => $email,
            'items' => $items,
            'shipping_address' => ['full_name' => 'Kinds Buyer', 'phone' => '0803' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT), 'address_line1' => '4 Kinds Street', 'city' => 'Enugu', 'state' => 'Enugu', 'country' => 'Nigeria'],
        ],
    ]);
    if ($res['status'] === 201) {
        $placed[] = (int) $t->scalar("SELECT `id` FROM `orders` WHERE `order_number` = ?", 's', [(string) TestKit::get($res, 'data.order_number')]);
    }
    return $res + ['email' => $email];
};
$quote = static fn (string $api, array $items): array => $t->http('POST', "{$api}/checkout/quote", [
    'json' => ['items' => $items, 'shipping_address' => ['state' => 'Enugu', 'country' => 'Nigeria']],
]);
$line = static fn (array $product, int $quantity = 1): array => [
    'product_id' => (int) TestKit::get($product, 'data.id'),
    'variant_id' => (int) TestKit::get($product, 'data.variants.0.id'),
    'quantity' => $quantity,
];
// The "10 orders in 10 minutes per connection" limit is not what this suite is about.
$freshLimit = static fn () => $t->sql("DELETE FROM `rate_limits` WHERE `id` IN (?, ?)", 'ss', [hash('sha256', 'checkout_order|127.0.0.1'), hash('sha256', 'checkout_order|::1')]);
/** The order-received email queued for a buyer, as the outbox holds it. */
$orderEmail = static function (string $email) use ($t): array {
    for ($i = 0; $i < 25; $i++) {
        $payload = $t->scalar("SELECT `payload` FROM `email_outbox` WHERE `recipient_email` = ? AND `template` = 'order_received' ORDER BY `id` DESC LIMIT 1", 's', [$email]);
        if ($payload !== null) {
            return (array) json_decode((string) $payload, true);
        }
        usleep(200000);
    }
    return [];
};

// -----------------------------------------------------------------------------
$t->section('The fixture shop is the clothing shop, whatever this copy sells');
$t->check('its name', 'SHOP.CO', Shop::name());
$t->check('its options', ['Size', 'Colour', 'Standard', 'Default'], [Shop::option1(), Shop::option2(), Shop::option1None(), Shop::option2None()]);
$t->check('no warranties, heavy items or age checks', [false, false, false, []], [Shop::feature('warranty'), Shop::feature('heavyDelivery'), Shop::feature('ageCheck'), Shop::ageRestrictedCategories()]);
$t->check('"a size", "an option"', ['a size', 'an option'], [Shop::withArticle('size'), Shop::withArticle('option')]);
$t->check('options read without the "none" words', ['M / Navy', 'Navy', 'M', ''], [Shop::optionsText('M', 'Navy'), Shop::optionsText('Standard', 'Navy'), Shop::optionsText('M', 'default'), Shop::optionsText('Standard', 'Default')]);

// -----------------------------------------------------------------------------
$t->section('A variant can be saved without an option');
$plain = $makeProduct('plain', ['variants' => [['stock_quantity' => 30]]]);
$t->check('a variant with neither option is saved', 201, $plain['status']);
$t->check('as the "none" words, with a black colour code', ['Standard', 'Default', '#000000'], [TestKit::get($plain, 'data.variants.0.size'), TestKit::get($plain, 'data.variants.0.color_name'), TestKit::get($plain, 'data.variants.0.color_hex')]);
$plainId = (int) TestKit::get($plain, 'data.id');

$kindColour = 'Midnight K' . $stamp;
$coloured = $makeProduct('coloured', ['variants' => [
    ['size' => '', 'color_name' => $kindColour, 'color_hex' => '#101020', 'stock_quantity' => 30],
    ['color_name' => 'Olive K' . $stamp, 'stock_quantity' => 5],
]]);
$t->check('variants with a colour and no size are saved', [201, 'Standard', 'Standard'], [$coloured['status'], TestKit::get($coloured, 'data.variants.0.size'), TestKit::get($coloured, 'data.variants.1.size')]);
$t->check('a missing colour code becomes black', '#000000', TestKit::get($coloured, 'data.variants.1.color_hex'));
$colouredId = (int) TestKit::get($coloured, 'data.id');

$twice = $makeProduct('twice', ['variants' => [['stock_quantity' => 1], ['size' => '', 'color_name' => '', 'stock_quantity' => 2]]]);
$t->check('two variants without options are the same pair: refused', [422, ['variants']], [$twice['status'], $details($twice)]);
$t->check('and the message names the options', 'Variant 2: another variant already has the same size and colour.', TestKit::get($twice, 'error.details.variants'));
$samePair = $makeProduct('pair', ['variants' => [['size' => 'M', 'color_name' => 'Navy'], ['size' => 'm', 'color_name' => 'NAVY']]]);
$t->check('the same size and colour twice: refused', [422, ['variants']], [$samePair['status'], $details($samePair)]);
$long = $makeProduct('long', ['variants' => [['size' => str_repeat('s', 51), 'color_name' => 'Navy']]]);
$t->check('the length message reads as before', 'Variant 1: size and colour can be at most 50 characters.', TestKit::get($long, 'error.details.variants'));
$listed = $makeProduct('listed', ['variants' => [['size' => ['M'], 'color_name' => 'Navy']]]);
$t->check('an option sent as a list is refused', 'Variant 1: send it as a size and a colour.', TestKit::get($listed, 'error.details.variants'));

$plainVariant = (int) TestKit::get($plain, 'data.variants.0.id');
$kept = $update($plainId, ['variants' => [['id' => $plainVariant, 'size' => '', 'color_name' => '', 'stock_quantity' => 30, 'original_stock_quantity' => 30]]]);
$t->check('saving it again with the options empty keeps it', [200, $plainVariant, 'Standard', 'Default'], [$kept['status'], TestKit::get($kept, 'data.variants.0.id'), TestKit::get($kept, 'data.variants.0.size'), TestKit::get($kept, 'data.variants.0.color_name')]);
$added = $update($plainId, ['variants' => [
    ['id' => $plainVariant, 'size' => '', 'color_name' => '', 'stock_quantity' => 30, 'original_stock_quantity' => 30],
    ['size' => 'L', 'stock_quantity' => 4],
]]);
$t->check('a variant with only a size can be added', [200, 'L', 'Default'], [$added['status'], TestKit::get($added, 'data.variants.1.size'), TestKit::get($added, 'data.variants.1.color_name')]);
$back = $update($plainId, ['variants' => [['id' => $plainVariant, 'size' => '', 'color_name' => '', 'stock_quantity' => 30, 'original_stock_quantity' => 30]]]);
$t->check('and removed again', [200, 1], [$back['status'], count((array) TestKit::get($back, 'data.variants'))]);

// -----------------------------------------------------------------------------
$t->section('The "none" words are not filter choices');
$facets = $t->http('GET', '/catalog/facets');
$sizes = (array) TestKit::get($facets, 'data.sizes');
$colours = array_column((array) TestKit::get($facets, 'data.colors'), 'name');
$t->ok('the filter does not offer "Standard" as a size', $sizes !== [] && !in_array('Standard', $sizes, true), implode(',', $sizes));
$t->ok('nor "Default" as a colour', !in_array('Default', $colours, true));
$t->ok('real colours of the same products are offered', in_array($kindColour, $colours, true));
$expectedOrder = array_values(array_intersect(['Medium', 'Large', 'X-Large', '32', '34'], $sizes));
$t->check('sizes come in the shop\'s order', $expectedOrder, array_values(array_filter($sizes, static fn (string $s): bool => in_array($s, $expectedOrder, true))));
$page = $t->http('GET', "/products/kinds-plain-{$stamp}");
$t->check('the product page still lists what is stored (the storefront hides it)', [['Standard'], 'Default'], [TestKit::get($page, 'data.available_sizes'), TestKit::get($page, 'data.available_colors.0.name')]);

// -----------------------------------------------------------------------------
$t->section('Emails never show the "none" words');
$freshLimit();
$plainOrder = $order($t->api, [$line($plain, 2)]);
$t->check('an order for a product without options', 201, $plainOrder['status']);
$t->check('the order line keeps the stored values', ['Standard', 'Default'], [TestKit::get($plainOrder, 'data.items.0.variant_options.size'), TestKit::get($plainOrder, 'data.items.0.variant_options.color')]);
$t->ok('and no warranty in this shop', !array_key_exists('warranty', (array) TestKit::get($plainOrder, 'data.items.0.variant_options')));
$payload = $orderEmail($plainOrder['email']);
$t->check('the email is queued with no options for the line', '', $payload['items'][0]['options'] ?? null);
$rendered = (new MailTemplates())->render('order_received', $payload);
$t->ok('its HTML shows neither "Standard" nor "Default"', $rendered['html'] !== '' && !str_contains($rendered['html'], 'Standard') && !str_contains($rendered['html'], 'Default'));
$t->ok('nor does its text', !str_contains($rendered['text'], 'Standard') && !str_contains($rendered['text'], 'Default') && str_contains($rendered['text'], "- Kinds plain {$stamp} x2"));
$t->ok('the footer line and the black button are as before', str_contains($rendered['html'], 'Clothes that suit your style and that you&#039;re proud to wear, delivered across Nigeria.') && str_contains($rendered['html'], '<td style="background:#000000;border-radius:62px">'));

$colouredOrder = $order($t->api, [$line($coloured)]);
$payload = $orderEmail($colouredOrder['email']);
$t->check('a product with only a colour shows only the colour', $kindColour, $payload['items'][0]['options'] ?? null);

// -----------------------------------------------------------------------------
$t->section('Specifications, warranty and heavy items through admin');
$spec = $makeProduct('spec', [
    'specifications' => [['name' => ' Material ', 'value' => 'Cotton'], ['name' => '', 'value' => 'no name'], ['name' => 'No value', 'value' => ''], ['name' => 'Care', 'value' => 'Machine wash']],
    'warranty' => ' 12 months ',
    'is_heavy' => true,
    'variants' => [['size' => 'M', 'color_name' => 'Black', 'stock_quantity' => 40]],
]);
$specId = (int) TestKit::get($spec, 'data.id');
$wanted = [['name' => 'Material', 'value' => 'Cotton'], ['name' => 'Care', 'value' => 'Machine wash']];
$t->check('saved on create, rows without a name or a value dropped', [201, $wanted, '12 months', true], [$spec['status'], TestKit::get($spec, 'data.specifications'), TestKit::get($spec, 'data.warranty'), TestKit::get($spec, 'data.is_heavy')]);
$public = $t->http('GET', "/products/kinds-spec-{$stamp}");
$t->check('the product page returns them', [$wanted, '12 months', true, false], [TestKit::get($public, 'data.specifications'), TestKit::get($public, 'data.warranty'), TestKit::get($public, 'data.is_heavy'), TestKit::get($public, 'data.age_restricted')]);
$row = (array) TestKit::get($A('GET', '/admin/products?search=' . urlencode("kinds-spec-{$stamp}")), 'data.0');
$t->check('the admin list row carries them (the editor opens from it)', [$specId, $wanted, '12 months', true, false], [(int) ($row['id'] ?? 0), $row['specifications'] ?? null, $row['warranty'] ?? null, $row['is_heavy'] ?? null, $row['age_restricted'] ?? null]);
$plainList = $A('GET', '/admin/products?search=' . urlencode("kinds-plain-{$stamp}"));
$plainRow = (array) TestKit::get($plainList, 'data.0');
$t->check('a product without them: an empty list, null and false', [[], null, false], [$plainRow['specifications'] ?? 'missing', array_key_exists('warranty', $plainRow) ? $plainRow['warranty'] : 'missing', $plainRow['is_heavy'] ?? 'missing']);
$t->ok('the empty list is a JSON list, not an object', str_contains($plainList['body'], '"specifications":[]'));
$publicPlain = $t->http('GET', "/products/kinds-plain-{$stamp}");
$t->ok('and so on the product page', str_contains($publicPlain['body'], '"specifications":[]') && TestKit::get($publicPlain, 'data.warranty') === null && TestKit::get($publicPlain, 'data.is_heavy') === false);
$inList = (new ProductRepository())->listPublished(['ids' => [$specId]])['items'][0] ?? [];
$t->ok('product lists say only whether it is age restricted', ($inList['age_restricted'] ?? null) === false && !array_key_exists('specifications', $inList) && !array_key_exists('warranty', $inList));

$renamed = $update($specId, ['name' => "Kinds spec renamed {$stamp}"]);
$t->check('an update without the keys keeps them', [200, $wanted, '12 months', true], [$renamed['status'], TestKit::get($renamed, 'data.specifications'), TestKit::get($renamed, 'data.warranty'), TestKit::get($renamed, 'data.is_heavy')]);
$cleared = $update($specId, ['specifications' => [], 'warranty' => '', 'is_heavy' => false]);
$t->check('an empty list, empty text and false clear them', [200, [], null, false], [$cleared['status'], TestKit::get($cleared, 'data.specifications'), TestKit::get($cleared, 'data.warranty'), TestKit::get($cleared, 'data.is_heavy')]);
$inDb = (array) $t->sql("SELECT `specifications`, `warranty`, `is_heavy` FROM `products` WHERE `id` = ?", 'i', [$specId])[0];
$t->check('nothing is left in the database', [null, null, 0], [$inDb['specifications'], $inDb['warranty'], (int) $inDb['is_heavy']]);
$update($specId, ['specifications' => $wanted, 'warranty' => '2 years', 'is_heavy' => true]);
$nulled = $update($specId, ['specifications' => null, 'warranty' => null]);
$t->check('null clears too, and leaves the heavy mark alone', [200, [], null, true], [$nulled['status'], TestKit::get($nulled, 'data.specifications'), TestKit::get($nulled, 'data.warranty'), TestKit::get($nulled, 'data.is_heavy')]);

$manyRows = static fn (int $n): array => array_map(static fn (int $i): array => ['name' => "Row {$i}", 'value' => 'x'], range(1, $n));
$full = $update($specId, ['specifications' => $manyRows(30), 'warranty' => str_repeat('w', 160)]);
$t->check('30 rows and a 160 character warranty are accepted', [200, 30], [$full['status'], count((array) TestKit::get($full, 'data.specifications'))]);
$edge = $update($specId, ['specifications' => [['name' => str_repeat('n', 60), 'value' => str_repeat('v', 300)]]]);
$t->check('a 60 character name and a 300 character value are accepted', 200, $edge['status']);
foreach ([
    '31 rows' => ['specifications' => $manyRows(31)],
    'a 61 character name' => ['specifications' => [['name' => str_repeat('n', 61), 'value' => 'x']]],
    'a 301 character value' => ['specifications' => [['name' => 'x', 'value' => str_repeat('v', 301)]]],
    'specifications as text' => ['specifications' => 'Material: Cotton'],
    'a row that is not a name and a value' => ['specifications' => ['Cotton']],
    'a name sent as a list' => ['specifications' => [['name' => ['x'], 'value' => 'y']]],
] as $label => $body) {
    $res = $update($specId, $body);
    $t->check("{$label}: 422 against specifications", [422, ['specifications']], [$res['status'], $details($res)]);
}
$res = $update($specId, ['warranty' => str_repeat('w', 161)]);
$t->check('a 161 character warranty: 422 against warranty', [422, ['warranty']], [$res['status'], $details($res)]);
$res = $update($specId, ['is_heavy' => 'maybe']);
$t->check('a heavy mark that is not yes or no: 422 against is_heavy', [422, ['is_heavy']], [$res['status'], $details($res)]);
$t->check('a refused update changed nothing', 1, count((array) TestKit::get($A('GET', '/admin/products?search=' . urlencode("kinds-spec-{$stamp}")), 'data.0.specifications')));
$ready = $update($specId, ['specifications' => $wanted, 'warranty' => '2 years', 'is_heavy' => true]);
$t->check('the heavy product with a warranty, for the checks below', [200, true, '2 years'], [$ready['status'], TestKit::get($ready, 'data.is_heavy'), TestKit::get($ready, 'data.warranty')]);

// -----------------------------------------------------------------------------
$t->section('Delivery zones carry a fee for heavy items');
$zones = (array) TestKit::get($t->http('GET', '/checkout/shipping-zones'), 'data.zones');
$t->ok('the public zone list returns it for every zone', $zones !== [] && array_filter($zones, static fn (array $z): bool => !is_float($z['heavy_item_fee'] ?? null) && !is_int($z['heavy_item_fee'] ?? null)) === []);
$newZone = $A('POST', '/admin/shipping-zones', ['json' => ['name' => "Kinds zone {$stamp}", 'code' => 'KND-' . strtoupper($stamp), 'states_covered' => ['Lagos'], 'fee' => 1000, 'heavy_item_fee' => 750.5, 'estimated_days' => '1-2 days', 'is_active' => false]]);
$newZoneId = (int) TestKit::get($newZone, 'data.id');
$t->cleanup(fn () => $t->sql("DELETE FROM `shipping_zones` WHERE `id` = ?", 'i', [$newZoneId]));
$t->check('a zone is created with it', [201, 750.5], [$newZone['status'], TestKit::get($newZone, 'data.heavy_item_fee')]);
$t->check('a zone that never set it has 0', 0.0, (float) TestKit::get($A('GET', '/admin/shipping-zones/' . (int) $t->scalar("SELECT `id` FROM `shipping_zones` WHERE `code` = 'ZONE-NATIONAL'")), 'data.heavy_item_fee'));
$t->check('an update without it keeps it', 750.5, TestKit::get($A('PUT', "/admin/shipping-zones/{$newZoneId}", ['json' => ['fee' => 1200]]), 'data.heavy_item_fee'));
foreach ([-1, 1000001, 'lots'] as $bad) {
    $res = $A('PUT', "/admin/shipping-zones/{$newZoneId}", ['json' => ['heavy_item_fee' => $bad]]);
    $t->check('heavy item fee ' . json_encode($bad) . ': 422 against the field', [422, ['heavy_item_fee']], [$res['status'], $details($res)]);
}

// The Enugu zone charges by quantity and by heavy item for the checks below; put back afterwards.
$enugu = $t->sql("SELECT `id`, `fee`, `items_included`, `extra_item_fee`, `heavy_item_fee` FROM `shipping_zones` WHERE `code` = 'ZONE-ENUGU-METRO'")[0];
$t->cleanup(fn () => $t->sql(
    "UPDATE `shipping_zones` SET `items_included` = ?, `extra_item_fee` = ?, `heavy_item_fee` = ? WHERE `id` = ?",
    'iddi',
    [(int) $enugu['items_included'], (float) $enugu['extra_item_fee'], (float) $enugu['heavy_item_fee'], (int) $enugu['id']]
));
$set = $A('PUT', "/admin/shipping-zones/{$enugu['id']}", ['json' => ['items_included' => 2, 'extra_item_fee' => 100, 'heavy_item_fee' => 2000]]);
$t->check('the Enugu zone: 2 items included, ₦100 for each more, ₦2,000 for each heavy one', [200, 2000.0], [$set['status'], (float) TestKit::get($set, 'data.heavy_item_fee')]);
$baseFee = (float) $enugu['fee'];

// -----------------------------------------------------------------------------
$t->section('Heavy items cost nothing extra unless the shop charges for them');
$heavyLines = [$line($spec, 2), $line($coloured, 1)];
$off = $quote($t->api, $heavyLines);
$t->check('the quote: the usual fee by quantity', [200, $baseFee + 100, 0], [$off['status'], (float) TestKit::get($off, 'data.shipping_fee'), TestKit::get($off, 'data.heavy_items')]);
$t->check('no age check either', [false, 18], [TestKit::get($off, 'data.age_check_required'), TestKit::get($off, 'data.minimum_age')]);
$t->check('the fee formula with the feature off', 1600.0, CheckoutService::deliveryFee(['fee' => 1500, 'items_included' => 2, 'extra_item_fee' => 100, 'heavy_item_fee' => 2000], 3, 2));
$freshLimit();
$offOrder = $order($t->api, $heavyLines);
$t->check('the order: the same fee', [201, $baseFee + 100], [$offOrder['status'], (float) TestKit::get($offOrder, 'data.shipping_fee')]);
$t->check('its zone snapshot counts no heavy items', [0, 2000.0], [TestKit::get($offOrder, 'data.shipping_zone.heavy_items'), (float) TestKit::get($offOrder, 'data.shipping_zone.heavy_item_fee')]);
$t->ok('and its lines carry no warranty', !array_key_exists('warranty', (array) TestKit::get($offOrder, 'data.items.0.variant_options')));

// -----------------------------------------------------------------------------
$t->section('Another kind of shop: its own API on the same database');
$restricted = $makeProduct('adult', ['category_id' => $accessories, 'variants' => [['stock_quantity' => 25]]]);
$t->check('a product in the accessories category', 201, $restricted['status']);
$otherPort = (int) (getenv('TEST_SHOP_API_PORT') ?: 8142);
$other = $t->startApi($otherPort, [
    'features' => ['warranty' => true, 'heavyDelivery' => true, 'ageCheck' => true],
    'ageCheck' => ['minAge' => 21, 'categories' => ['accessories']],
], ['ADMIN_COOKIE_NAME' => 'kinds_admin']);
$t->ok('the second API started', $other !== null);
if ($other === null) {
    exit($t->finish());
}

$t->section('The admin cookie name comes from the environment');
$otherJar = 'kinds-other';
$login = $t->http('POST', "{$other}/auth/login", ['json' => ['email' => $admin['email'], 'password' => $admin['password']], 'jar' => $otherJar]);
$cookies = (string) @file_get_contents($t->cookieFile($otherJar));
$t->ok('signing in there sets the cookie under its own name', $login['status'] === 200 && preg_match('/\tkinds_admin\t/', $cookies) === 1 && !str_contains($cookies, 'shopco_admin'));
$t->check('and the session works with it', 200, $t->http('GET', "{$other}/admin/account", ['jar' => $otherJar])['status']);
$t->check('the main API does not know that cookie', 401, $t->http('GET', '/admin/account', ['jar' => $otherJar])['status']);
$vars = new ReflectionProperty(Env::class, 'vars');
$originalEnv = $vars->getValue();
$t->cleanup(fn () => $vars->setValue(null, $originalEnv));
$nameWith = static function (?string $value) use ($vars, $originalEnv): string {
    $vars->setValue(null, $value === null ? array_diff_key($originalEnv, ['ADMIN_COOKIE_NAME' => '']) : ['ADMIN_COOKIE_NAME' => $value] + $originalEnv);
    return SessionCookie::name(SessionCookie::SCOPE_ADMIN);
};
$t->check('a name from the environment is used', 'perfume_admin', $nameWith('perfume_admin'));
$t->check('an empty value falls back to the usual name', 'shopco_admin', $nameWith(''));
$t->check('so does something that is not a cookie name', 'shopco_admin', $nameWith('bad name; path=/'));
$vars->setValue(null, $originalEnv);
$t->check('the customer cookie is not affected', (string) (Env::get('SESSION_COOKIE_NAME', 'shopco_session') ?: 'shopco_session'), SessionCookie::name(SessionCookie::SCOPE_CUSTOMER));

// -----------------------------------------------------------------------------
$t->section('Heavy items add to the delivery fee where the shop charges for them');
$on = $quote($other, $heavyLines);
$t->check('the quote: ₦2,000 more for each of the 2 heavy items', [200, $baseFee + 100 + 4000, 2], [$on['status'], (float) TestKit::get($on, 'data.shipping_fee'), TestKit::get($on, 'data.heavy_items')]);
$t->check('the zone in the quote says what a heavy item costs', 2000.0, (float) TestKit::get($on, 'data.shipping_zone.heavy_item_fee'));
$t->check('the total includes it', (float) TestKit::get($on, 'data.subtotal') + $baseFee + 4100, (float) TestKit::get($on, 'data.grand_total'));
$light = $quote($other, [$line($coloured, 3)]);
$t->check('an order without heavy items pays the usual fee', [$baseFee + 100, 0], [(float) TestKit::get($light, 'data.shipping_fee'), TestKit::get($light, 'data.heavy_items')]);
$freshLimit();
$onOrder = $order($other, $heavyLines);
$t->check('the order is charged the same as the quote', [201, $baseFee + 4100], [$onOrder['status'], (float) TestKit::get($onOrder, 'data.shipping_fee')]);
$snapshot = (array) json_decode((string) $t->scalar("SELECT `shipping_zone_snapshot` FROM `orders` WHERE `order_number` = ?", 's', [(string) TestKit::get($onOrder, 'data.order_number')]), true);
$t->check('and its zone snapshot keeps the heavy fee and count', [2000.0, 2, $baseFee + 4100, $baseFee], [(float) ($snapshot['heavy_item_fee'] ?? -1), $snapshot['heavy_items'] ?? null, (float) ($snapshot['fee'] ?? -1), (float) ($snapshot['base_fee'] ?? -1)]);

// -----------------------------------------------------------------------------
$t->section('The warranty stays on the order line and shows in the emails');
$t->check('the order line carries it', '2 years', TestKit::get($onOrder, 'data.items.0.variant_options.warranty'));
$t->ok('a product without one has none', !array_key_exists('warranty', (array) TestKit::get($onOrder, 'data.items.1.variant_options')));
$stored = (array) json_decode((string) $t->scalar(
    "SELECT oi.`variant_options` FROM `order_items` oi JOIN `orders` o ON o.id = oi.order_id WHERE o.order_number = ? AND oi.product_id = ?",
    'si',
    [(string) TestKit::get($onOrder, 'data.order_number'), $specId]
), true);
$t->check('it is saved with the size and colour', ['M', 'Black', '2 years'], [$stored['size'] ?? null, $stored['color'] ?? null, $stored['warranty'] ?? null]);
$update($specId, ['warranty' => '5 years']);
$payload = $orderEmail($onOrder['email']);
$t->check('the queued email has the warranty the item was sold with', ['M / Black', '2 years'], [$payload['items'][0]['options'] ?? null, $payload['items'][0]['warranty'] ?? null]);
$rendered = (new MailTemplates())->render('order_received', $payload);
$t->ok('printed under the item in the HTML', str_contains($rendered['html'], 'Warranty: 2 years'));
$t->ok('and in the text', str_contains($rendered['text'], "  Warranty: 2 years\n"));
$t->check('only for the item that has one', 1, substr_count($rendered['text'], 'Warranty:'));

// -----------------------------------------------------------------------------
$t->section('Age confirmation');
$adultSlug = "kinds-adult-{$stamp}";
$t->check('the product is age restricted in that shop', true, TestKit::get($t->http('GET', "{$other}/products/{$adultSlug}"), 'data.age_restricted'));
$t->check('other categories are not', false, TestKit::get($t->http('GET', "{$other}/products/kinds-plain-{$stamp}"), 'data.age_restricted'));
$t->check('and nothing is in the fixture shop', false, TestKit::get($t->http('GET', "/products/{$adultSlug}"), 'data.age_restricted'));
$adultQuote = $quote($other, [$line($restricted), $line($coloured)]);
$t->check('the quote asks for the confirmation, with the shop\'s age', [200, true, 21], [$adultQuote['status'], TestKit::get($adultQuote, 'data.age_check_required'), TestKit::get($adultQuote, 'data.minimum_age')]);
$t->check('a quote without such items does not', false, TestKit::get($quote($other, [$line($coloured)]), 'data.age_check_required'));

$adultVariant = (int) TestKit::get($restricted, 'data.variants.0.id');
$stock = static fn (): int => (int) $t->scalar("SELECT `stock_quantity` FROM `product_variants` WHERE `id` = ?", 'i', [$adultVariant]);
$before = $stock();
$freshLimit();
$refused = $order($other, [$line($restricted)]);
$t->check('an order without the confirmation: 422 against age_confirmed', [422, ['age_confirmed']], [$refused['status'], $details($refused)]);
$t->check('saying which age', 'Please confirm that you are 21 or older to order these items.', TestKit::get($refused, 'error.details.age_confirmed'));
$t->check('"yes" is not a confirmation', 422, $order($other, [$line($restricted)], ['age_confirmed' => 'yes'])['status']);
$t->check('false is not either', 422, $order($other, [$line($restricted)], ['age_confirmed' => false])['status']);
$t->check('a refused order takes no stock', $before, $stock());
$confirmed = $order($other, [$line($restricted)], ['age_confirmed' => true]);
$t->check('with the confirmation the order is placed', [201, $before - 1], [$confirmed['status'], $stock()]);
$t->check('items that are not restricted need no confirmation', 201, $order($other, [$line($coloured)])['status']);
$freshLimit();
$t->check('the fixture shop asks for nothing', 201, $order($t->api, [$line($restricted)])['status']);
$freshLimit();

// -----------------------------------------------------------------------------
$t->section('Inside this process: a shop with other options');
$t->cleanup(static fn () => Shop::overrideForTests([]));
Shop::overrideForTests([
    'name' => 'SCENT.CO',
    'tagline' => '',
    'theme' => ['accent' => '#7A1F3D', 'accentInk' => '#FFF8F0'],
    'option1' => ['label' => 'Volume', 'none' => 'One size', 'choices' => ['Large', 'Medium']],
    'option2' => ['label' => 'Scent', 'none' => 'Original', 'swatch' => false],
    'features' => ['heavyDelivery' => true],
]);
$t->check('the option names', ['Volume', 'Scent', 'volume', 'scent'], [Shop::option1(), Shop::option2(), Shop::option1Word(), Shop::option2Word()]);
$t->check('an empty option is stored as that shop\'s "none" word', ['size' => 'One size', 'color_name' => 'Original', 'color_hex' => '#000000'], ProductRepository::variantOptions(['size' => ' ', 'color_name' => null]));
$t->check('messages use the names', 'Variant 1: volume and scent can be at most 50 characters.', ProductValidator::validate(['variants' => [['size' => str_repeat('x', 51)]]], true)['variants'] ?? null);
$t->check('and the right article', 'Variant 1: send it as a volume and a scent.', ProductValidator::validate(['variants' => ['50ml']], true)['variants'] ?? null);
$t->check('the file name for downloads', 'scentco', Shop::fileName());
$t->check('the fee formula with heavy items charged', 5600.0, CheckoutService::deliveryFee(['fee' => 1500, 'items_included' => 2, 'extra_item_fee' => 100, 'heavy_item_fee' => 2000], 3, 2));

$repo = new ProductRepository();
$sizes = $repo->facets()['sizes'];
$t->ok('the filter lists that shop\'s choices first, in its order', array_slice($sizes, 0, 2) === ['Large', 'Medium'], implode(',', $sizes));
$rest = array_slice($sizes, 2);
$sortedRest = $rest;
usort($sortedRest, 'strnatcasecmp');
$t->check('and everything else after, naturally sorted', $sortedRest, $rest);
$t->ok('that shop\'s "none" words are left out instead', in_array('Standard', $sizes, true) && !in_array('One size', $sizes, true));

$found = static fn (string $colour): int => $repo->listPublished(['colors' => $colour, 'ids' => [$colouredId]])['total'];
$t->check('a scent is matched by its exact name, whatever the case', 1, $found(strtoupper($kindColour)));
$t->check('not by a colour family', 0, $found('black'));
$t->check('nor by part of the name', 0, $found('Midnight'));
Shop::overrideForTests([]);
// No variant is called just "Green" or "Midnight", so the family words and the part match apply
// (ShopSafeguardsTest covers a name that some variant really has).
$t->check('a colour is still matched by its family ("olive" is a green)', 1, $found('green'));
$t->check('and by part of its name', 1, $found('midnight'));

Shop::overrideForTests(['name' => 'SCENT.CO', 'tagline' => '', 'theme' => ['accent' => '#7A1F3D', 'accentInk' => '#FFF8F0']]);
$savedName = (string) $t->scalar("SELECT `value` FROM `store_settings` WHERE `key_name` = 'store_name'");
$t->cleanup(fn () => $t->sql("UPDATE `store_settings` SET `value` = ? WHERE `key_name` = 'store_name'", 's', [$savedName]));
$templates = new MailTemplates();
$t->check('the owner\'s store name wins while it is set', $savedName, $templates->storeName());
$t->sql("UPDATE `store_settings` SET `value` = '' WHERE `key_name` = 'store_name'");
$templates = new MailTemplates();
$t->check('without one, emails use the shop\'s name', 'SCENT.CO', $templates->storeName());
$t->check('and so does checkout', 'SCENT.CO', (new CheckoutService())->getStoreOrigin()['store_name']);
$t->sql("UPDATE `store_settings` SET `value` = ? WHERE `key_name` = 'store_name'", 's', [$savedName]);
$html = $templates->render('order_received', $payload)['html'];
$t->ok('the button and wordmark take the shop\'s colour', str_contains($html, '<td style="background:#7A1F3D;border-radius:62px">') && str_contains($html, 'color:#FFF8F0;text-decoration:none') && str_contains($html, 'text-transform:uppercase;color:#7A1F3D;text-decoration:none'));
$t->ok('a shop without a tagline has no such line', !str_contains($html, '<div style="margin:6px 0 0">'));
Shop::overrideForTests(['tagline' => 'Scents & <stories>']);
$t->ok('a tagline is escaped', str_contains((new MailTemplates())->render('order_received', $payload)['html'], '<div style="margin:6px 0 0">Scents &amp; &lt;stories&gt;</div>'));

$t->section('The override is for tests only');
$vars->setValue(null, ['APP_ENV' => 'production'] + $originalEnv);
Shop::overrideForTests(['name' => 'NOT.THIS', 'features' => ['ageCheck' => true], 'ageCheck' => ['categories' => ['accessories']]]);
$t->ok('on a real shop neither the method nor the env value changes anything', Shop::name() !== 'NOT.THIS' && Shop::get('name') === json_decode((string) file_get_contents(BASE_PATH . '/config/shop.json'), true)['name']);
$vars->setValue(null, $originalEnv);
Shop::overrideForTests([]);
$t->check('back in the tests, the fixture shop again', ['SHOP.CO', []], [Shop::name(), Shop::ageRestrictedCategories()]);

exit($t->finish());
