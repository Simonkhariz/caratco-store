<?php

declare(strict_types=1);

/**
 * Safeguards found by a review of the "any kind of shop" work:
 *
 *   - variant SKUs that stay unique for option values that shorten to the same letters, and a
 *     typed SKU that clashes refused against its row;
 *   - a variant removed and typed in again within one edit keeps its stock;
 *   - the "none" word refused as a real choice beside other choices;
 *   - cart lines without a variant id matched with the "none" words filled in;
 *   - a colour that some variant really has matched exactly ("Gold" is not "Rose Gold");
 *   - the age check by product as well as by category, the confirmation kept on the order, and
 *     a category for adults only that cannot be renamed out of the check;
 *   - a starting sales figure that real sales add to;
 *   - a missing or broken config/shop.json stops the shop instead of turning it into a default one.
 *
 *   php backend/tests/run.php ShopSafeguards
 */

require __DIR__ . '/support/TestKit.php';

use App\Config\Env;
use App\Config\Shop;
use App\Repositories\ProductRepository;
use App\Services\ProductSales;

$t = new TestKit('ShopSafeguards');
$admin = $t->adminSession('safeguards');
$as = ['jar' => $admin['jar'], 'csrf' => $admin['csrf']];
$A = static fn (string $method, string $path, array $opts = []): array => $t->http($method, $path, $opts + $as);
$stamp = getmypid() . bin2hex(random_bytes(2));
$details = static fn (array $res): array => array_keys((array) TestKit::get($res, 'error.details'));
$variantsError = static fn (array $res): string => (string) TestKit::get($res, 'error.details.variants');

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
        'name' => "Guard {$label} {$stamp}",
        'slug' => "guard-{$label}-{$stamp}",
        'sku' => strtoupper("GRD-{$label}-{$stamp}"),
        'base_price' => 4000,
        'status' => 'published',
        'category_id' => $tshirts,
    ]]);
    if ($res['status'] === 201) {
        $madeProducts[] = (int) TestKit::get($res, 'data.id');
    }
    return $res;
};
$update = static fn (int $id, array $body): array => $A('PUT', "/admin/products/{$id}", ['json' => $body]);
$skusOf = static fn (int $productId, string $where = '1=1'): array => array_column(
    $t->sql("SELECT `sku` FROM `product_variants` WHERE `product_id` = ? AND {$where} ORDER BY `id`", 'i', [$productId]),
    'sku'
);
$variantRow = static fn (int $variantId): array => (array) ($t->sql("SELECT * FROM `product_variants` WHERE `id` = ?", 'i', [$variantId])[0] ?? []);
$order = static function (string $api, array $items, array $extra = []) use ($t, &$placed, &$buyers): array {
    $email = $t->uniq('t-guard') . '@example.test';
    $buyers[] = $email;
    $res = $t->http('POST', "{$api}/checkout/orders", [
        'headers' => ['Idempotency-Key: ' . bin2hex(random_bytes(16))],
        'json' => $extra + [
            'email' => $email,
            'items' => $items,
            'shipping_address' => ['full_name' => 'Guard Buyer', 'phone' => '0803' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT), 'address_line1' => '4 Guard Street', 'city' => 'Enugu', 'state' => 'Enugu', 'country' => 'Nigeria'],
        ],
    ]);
    $res['order_id'] = 0;
    if ($res['status'] === 201) {
        $res['order_id'] = (int) $t->scalar("SELECT `id` FROM `orders` WHERE `order_number` = ?", 's', [(string) TestKit::get($res, 'data.order_number')]);
        $placed[] = $res['order_id'];
    }
    return $res;
};
$quote = static fn (string $api, array $items): array => $t->http('POST', "{$api}/checkout/quote", [
    'json' => ['items' => $items, 'shipping_address' => ['state' => 'Enugu', 'country' => 'Nigeria']],
]);
$line = static fn (array $product, int $quantity = 1): array => [
    'product_id' => (int) TestKit::get($product, 'data.id'),
    'variant_id' => (int) TestKit::get($product, 'data.variants.0.id'),
    'quantity' => $quantity,
];
$freshLimit = static fn () => $t->sql("DELETE FROM `rate_limits` WHERE `id` IN (?, ?)", 'ss', [hash('sha256', 'checkout_order|127.0.0.1'), hash('sha256', 'checkout_order|::1')]);

// -----------------------------------------------------------------------------
$t->section('Generated variant SKUs are unique, and leave out an option the product does not use');
$packs = $makeProduct('packs', ['variants' => [
    ['size' => 'Pack of 3', 'stock_quantity' => 9],
    ['size' => 'Pack of 6', 'stock_quantity' => 9],
    ['size' => 'Pack of 12', 'stock_quantity' => 9],
]]);
$packsId = (int) TestKit::get($packs, 'data.id');
$packSku = strtoupper("GRD-packs-{$stamp}");
$t->check('"Pack of 3", "Pack of 6" and "Pack of 12" all save without typing a SKU', 201, $packs['status']);
$t->check('each with its own SKU, and no part for the colour the product does not use', ["{$packSku}-PAC", "{$packSku}-PAC-2", "{$packSku}-PAC-3"], array_column((array) TestKit::get($packs, 'data.variants'), 'sku'));

$scents = $makeProduct('scents', ['variants' => [
    ['size' => '50ml', 'color_name' => 'Eau de Parfum'],
    ['size' => '50ml', 'color_name' => 'Eau de Toilette'],
    ['size' => '100ml', 'color_name' => 'Eau de Parfum'],
]]);
$scentSku = strtoupper("GRD-scents-{$stamp}");
$t->check('with both options the SKU is built as before, and made unique', ["{$scentSku}-EAU-50M", "{$scentSku}-EAU-50M-2", "{$scentSku}-EAU-100"], array_column((array) TestKit::get($scents, 'data.variants'), 'sku'));
$plain = $makeProduct('plain', ['variants' => [['stock_quantity' => 20]]]);
$plainId = (int) TestKit::get($plain, 'data.id');
$t->check('a variant with neither option', [strtoupper("GRD-plain-{$stamp}") . '-STD'], array_column((array) TestKit::get($plain, 'data.variants'), 'sku'));
$bare = $makeProduct('bare');
$t->check('and the one variant of a product created without any', [strtoupper("GRD-bare-{$stamp}") . '-STD'], array_column((array) TestKit::get($bare, 'data.variants'), 'sku'));
$t->check('a product created without a style has none (not "Casual")', '', TestKit::get($bare, 'data.dress_style'));

$packRows = static fn (): array => array_map(
    static fn (array $v): array => ['id' => (int) $v['id'], 'sku' => $v['sku'], 'size' => $v['size'], 'color_name' => $v['color_name'], 'stock_quantity' => (int) $v['stock_quantity'], 'original_stock_quantity' => (int) $v['stock_quantity']],
    $t->sql("SELECT `id`, `sku`, `size`, `color_name`, `stock_quantity` FROM `product_variants` WHERE `product_id` = ? AND `is_active` = 1 ORDER BY `id`", 'i', [$packsId])
);
$rows = $packRows();
$more = $update($packsId, ['variants' => array_merge($rows, [['size' => 'Pack of 24', 'stock_quantity' => 5]])]);
$t->check('an edit that sends every row back with its own SKU is fine, and a new row gets the next free one', [200, "{$packSku}-PAC-4"], [$more['status'], TestKit::get($more, 'data.variants.3.sku')]);
// The new variant's first stock was logged, so removing it archives it: it keeps its SKU.
$less = $update($packsId, ['variants' => $rows]);
$t->check('a removed variant that has history is archived with its SKU', [200, ["{$packSku}-PAC-4"]], [$less['status'], $skusOf($packsId, '`is_active` = 0')]);
$next = $update($packsId, ['variants' => array_merge($rows, [['size' => 'Pack of 48']])]);
$t->check('the next new variant skips the archived SKU too', [200, "{$packSku}-PAC-5"], [$next['status'], TestKit::get($next, 'data.variants.3.sku')]);
$t->check('no SKU is used twice', count($skusOf($packsId)), count(array_unique(array_map('strtolower', $skusOf($packsId)))));

$ahead = $makeProduct('ahead', ['variants' => [['size' => 'Pack of 3'], ['size' => 'Pack of 6', 'sku' => strtoupper("GRD-ahead-{$stamp}") . '-PAC']]]);
$t->check('a generated SKU gives way to one typed on a later row', [201, [strtoupper("GRD-ahead-{$stamp}") . '-PAC-2', strtoupper("GRD-ahead-{$stamp}") . '-PAC']], [$ahead['status'], array_column((array) TestKit::get($ahead, 'data.variants'), 'sku')]);

$t->section('A SKU typed by hand that is already in use is refused against its row');
$clash = $makeProduct('clash', ['variants' => [['size' => 'M', 'color_name' => 'Black', 'sku' => "FREE-{$stamp}"], ['size' => 'L', 'color_name' => 'Black', 'sku' => "{$packSku}-PAC"]]]);
$t->check('on create: 422 against the variants', [422, 'VALIDATION_ERROR', ['variants']], [$clash['status'], TestKit::get($clash, 'error.code'), $details($clash)]);
$t->check('naming the row and the SKU', "Variant 2: another product already uses the SKU {$packSku}-PAC.", $variantsError($clash));
$t->check('and nothing was created', 0, (int) $t->scalar("SELECT COUNT(*) FROM `products` WHERE `slug` = ?", 's', ["guard-clash-{$stamp}"]));
$twice = $makeProduct('twice', ['variants' => [['size' => 'M', 'sku' => "SAME-{$stamp}"], ['size' => 'L', 'sku' => "same-{$stamp}"]]]);
$t->check('the same SKU typed on two rows', [422, "Variant 2: another variant of this product already uses the SKU same-{$stamp}."], [$twice['status'], $variantsError($twice)]);
$archivedClash = $makeProduct('archived', ['variants' => [['size' => 'M', 'sku' => "{$packSku}-PAC-4"]]]);
$t->check('an archived variant\'s SKU is still in use', [422, "Variant 1: another product already uses the SKU {$packSku}-PAC-4."], [$archivedClash['status'], $variantsError($archivedClash)]);

$rows = $packRows();
$rows[1]['sku'] = "{$scentSku}-EAU-50M";
$onUpdate = $update($packsId, ['variants' => $rows]);
$t->check('on update: the same answer', [422, 'VALIDATION_ERROR', "Variant 2: another product already uses the SKU {$scentSku}-EAU-50M."], [$onUpdate['status'], TestKit::get($onUpdate, 'error.code'), $variantsError($onUpdate)]);
$rows = $packRows();
$t->check('and nothing was changed', "{$packSku}-PAC-2", $rows[1]['sku']);
$rows[1]['sku'] = $rows[0]['sku'];
$own = $update($packsId, ['variants' => $rows]);
$t->check('a SKU of another variant of the same product', [422, "Variant 2: another variant of this product already uses the SKU {$packSku}-PAC."], [$own['status'], $variantsError($own)]);
$rows = $packRows();
$rows[1]['sku'] = "TYPED-{$stamp}";
$typed = $update($packsId, ['variants' => $rows]);
$t->check('a free SKU typed by hand is saved', [200, "TYPED-{$stamp}"], [$typed['status'], TestKit::get($typed, 'data.variants.1.sku')]);

// -----------------------------------------------------------------------------
$t->section('A variant removed and typed in again within one edit keeps its stock');
$stocked = $makeProduct('stocked', ['variants' => [
    ['size' => 'M', 'color_name' => 'Black', 'stock_quantity' => 30],
    ['size' => 'L', 'color_name' => 'Black', 'stock_quantity' => 10],
]]);
$stockedId = (int) TestKit::get($stocked, 'data.id');
$mId = (int) TestKit::get($stocked, 'data.variants.0.id');
$lId = (int) TestKit::get($stocked, 'data.variants.1.id');
$mRow = ['id' => $mId, 'size' => 'M', 'color_name' => 'Black', 'stock_quantity' => 30, 'original_stock_quantity' => 30];
$logs = static fn (int $variantId): int => (int) $t->scalar("SELECT COUNT(*) FROM `inventory_logs` WHERE `variant_id` = ?", 'i', [$variantId]);
// The form sends the pair as a brand new row: no id, stock 0.
$retyped = $update($stockedId, ['variants' => [$mRow, ['size' => 'l', 'color_name' => 'black', 'stock_quantity' => 0]]]);
$t->check('it is the same variant, with its stock as it was', [200, $lId, 10, 1], [$retyped['status'], (int) TestKit::get($retyped, 'data.variants.1.id'), (int) $variantRow($lId)['stock_quantity'], (int) $variantRow($lId)['is_active']]);
$t->check('and no stock change was logged', 0, $logs($lId));
$retyped = $update($stockedId, ['variants' => [$mRow, ['size' => 'L', 'color_name' => 'Black', 'stock_quantity' => 99]]]);
$t->check('whatever stock the new row carries', [200, 10], [$retyped['status'], (int) $variantRow($lId)['stock_quantity']]);
$withId = $update($stockedId, ['variants' => [$mRow, ['id' => $lId, 'size' => 'L', 'color_name' => 'Black', 'stock_quantity' => 12, 'original_stock_quantity' => 10]]]);
$t->check('a row sent with its id still changes the stock', [200, 12, 1], [$withId['status'], (int) $variantRow($lId)['stock_quantity'], $logs($lId)]);
$gone = $update($stockedId, ['variants' => [$mRow]]);
$t->check('removed for good (it has history, so it is archived)', [200, 0], [$gone['status'], (int) $variantRow($lId)['is_active']]);
$backAgain = $update($stockedId, ['variants' => [$mRow, ['size' => 'L', 'color_name' => 'Black', 'stock_quantity' => 7]]]);
$t->check('an archived variant added back takes the stock typed, as before', [200, $lId, 7, 1], [$backAgain['status'], (int) TestKit::get($backAgain, 'data.variants.1.id'), (int) $variantRow($lId)['stock_quantity'], (int) $variantRow($lId)['is_active']]);
$t->check('and that is logged as added back', 'Variant added back', $t->scalar("SELECT `reason` FROM `inventory_logs` WHERE `variant_id` = ? ORDER BY `id` DESC LIMIT 1", 'i', [$lId]));

// -----------------------------------------------------------------------------
$t->section('The "none" word cannot be a real choice beside others');
$model = $makeProduct('model', ['variants' => [['size' => 'Standard', 'stock_quantity' => 3], ['size' => 'Pro', 'stock_quantity' => 3]]]);
$t->check('"Standard" beside "Pro": refused', [422, ['variants']], [$model['status'], $details($model)]);
$t->check('naming the word and suggesting another', 'Variant 1: "Standard" is the word this shop keeps for a product without a size, so it cannot be one of the choices. Use another name, for example "Standard size".', $variantsError($model));
$lower = $makeProduct('lower', ['variants' => [['size' => 'Pro'], ['size' => 'standard']]]);
$t->ok('whatever the case, and whichever row', $lower['status'] === 422 && str_starts_with($variantsError($lower), 'Variant 2: "standard" is the word'), $variantsError($lower));
$tone = $makeProduct('tone', ['variants' => [['size' => 'M', 'color_name' => 'Navy'], ['size' => 'L', 'color_name' => 'Default']]]);
$t->ok('the same for the second option', $tone['status'] === 422 && str_contains($variantsError($tone), '"Default" is the word this shop keeps for a product without a colour') && str_contains($variantsError($tone), '"Default colour"'), $variantsError($tone));
$single = $makeProduct('single', ['variants' => [['size' => 'Standard', 'color_name' => 'Default', 'stock_quantity' => 2]]]);
$t->check('a single variant that sends the words is a product without options, as before', [201, 'Standard', 'Default'], [$single['status'], TestKit::get($single, 'data.variants.0.size'), TestKit::get($single, 'data.variants.0.color_name')]);
$noSize = $makeProduct('nosize', ['variants' => [['size' => 'Standard', 'color_name' => 'Navy'], ['size' => '', 'color_name' => 'Olive']]]);
$t->check('and so are several variants that all lack the option', 201, $noSize['status']);
$onEdit = $update((int) TestKit::get($single, 'data.id'), ['variants' => [
    ['id' => (int) TestKit::get($single, 'data.variants.0.id'), 'size' => 'Standard', 'color_name' => 'Default'],
    ['size' => 'Pro', 'color_name' => ''],
]]);
$t->check('refused on an edit too', [422, 'VALIDATION_ERROR', ['variants']], [$onEdit['status'], TestKit::get($onEdit, 'error.code'), $details($onEdit)]);

// -----------------------------------------------------------------------------
$t->section('Cart lines without a variant id');
$volumes = $makeProduct('volumes', ['variants' => [['size' => '50ml', 'stock_quantity' => 8], ['size' => '100ml', 'stock_quantity' => 8]]]);
$volumesId = (int) TestKit::get($volumes, 'data.id');
$found = $quote($t->api, [['product_id' => $volumesId, 'size' => '50ML', 'quantity' => 1]]);
$t->check('a line with only the size finds that variant of a product without colours', [200, (int) TestKit::get($volumes, 'data.variants.0.id')], [$found['status'], TestKit::get($found, 'data.items.0.variant_id')]);
$found = $quote($t->api, [['product_id' => $volumesId, 'size' => '100ml', 'color' => '', 'quantity' => 1]]);
$t->check('an empty colour is the same as none', (int) TestKit::get($volumes, 'data.variants.1.id'), TestKit::get($found, 'data.items.0.variant_id'));
$coloursOnly = $makeProduct('colours', ['variants' => [['color_name' => 'Navy', 'stock_quantity' => 8], ['color_name' => 'Olive', 'stock_quantity' => 8]]]);
$found = $quote($t->api, [['product_id' => (int) TestKit::get($coloursOnly, 'data.id'), 'color' => ['name' => 'olive'], 'quantity' => 1]]);
$t->check('a line with only the colour finds that variant of a product without sizes', (int) TestKit::get($coloursOnly, 'data.variants.1.id'), TestKit::get($found, 'data.items.0.variant_id'));
$missing = $quote($t->api, [['product_id' => $volumesId, 'size' => '75ml', 'quantity' => 1]]);
$message = (string) TestKit::get($missing, 'error.message');
$t->check('a size the product does not have is refused', 422, $missing['status']);
$t->ok('saying what is not offered, without the "none" words', str_contains($message, "('75ml' is not offered)") && !str_contains($message, 'Default') && !str_contains($message, 'Standard'), $message);
$none = $quote($t->api, [['product_id' => $volumesId, 'quantity' => 1]]);
$message = (string) TestKit::get($none, 'error.message');
$t->ok('a line that chooses nothing is asked to choose', $none['status'] === 422 && str_contains($message, 'Please choose an available size and colour') && !str_contains($message, 'not offered') && !str_contains($message, 'Default'), $message);
$t->check('a product with one variant still needs no choice', (int) TestKit::get($plain, 'data.variants.0.id'), TestKit::get($quote($t->api, [['product_id' => $plainId, 'quantity' => 1]]), 'data.items.0.variant_id'));

// -----------------------------------------------------------------------------
$t->section('A colour some variant really has is matched exactly');
$gold = $makeProduct('gold', ['variants' => [['color_name' => 'Gold', 'color_hex' => '#D4AF37', 'stock_quantity' => 4]]]);
$roseGold = $makeProduct('rosegold', ['variants' => [['color_name' => 'Rose Gold', 'color_hex' => '#B76E79', 'stock_quantity' => 4]]]);
$goldId = (int) TestKit::get($gold, 'data.id');
$roseGoldId = (int) TestKit::get($roseGold, 'data.id');
$repo = new ProductRepository();
$matched = static function (string $colour, array $ids) use ($repo): array {
    $found = array_map('intval', array_column($repo->listPublished(['colors' => $colour, 'ids' => $ids], 1, 50)['items'], 'id'));
    sort($found);
    return $found;
};
$t->check('"Gold" finds the gold piece, not the rose gold one', [$goldId], $matched('Gold', [$goldId, $roseGoldId]));
$t->check('whatever the case', [$goldId], $matched('gOLD', [$goldId, $roseGoldId]));
$t->check('"Rose Gold" finds only the rose gold one', [$roseGoldId], $matched('rose gold', [$goldId, $roseGoldId]));
$both = [$goldId, $roseGoldId];
sort($both);
$t->check('both chosen: both found', $both, $matched('Gold,Rose Gold', [$goldId, $roseGoldId]));
$t->check('through the API too', [$goldId], (static function () use ($t, $goldId, $roseGoldId): array {
    $ids = array_map('intval', array_column((array) TestKit::get($t->http('GET', '/products?colors=Gold&per_page=100'), 'data'), 'id'));
    return array_values(array_intersect($ids, [$goldId, $roseGoldId]));
})());
$t->check('a word no variant is called still finds the pieces that contain it', $both, $matched('gol', [$goldId, $roseGoldId]));
// A family whose own name no variant has, to see the family words still at work.
$families = ['cyan' => 'Teal', 'purple' => 'Plum', 'orange' => 'Rust', 'pink' => 'Blush'];
$family = null;
foreach ($families as $name => $word) {
    if ((int) $t->scalar("SELECT COUNT(*) FROM `product_variants` WHERE LOWER(`color_name`) = ?", 's', [$name]) === 0) {
        $family = $name;
        break;
    }
}
$shade = $makeProduct('shade', ['variants' => [['color_name' => $families[$family] . ' Mist', 'stock_quantity' => 4]]]);
$shadeId = (int) TestKit::get($shade, 'data.id');
$t->check("\"{$family}\" typed by hand still finds its family (\"{$families[$family]} Mist\")", [$shadeId], $matched($family, [$shadeId]));
$exact = $makeProduct('exact', ['variants' => [['color_name' => ucfirst((string) $family), 'stock_quantity' => 4]]]);
$exactId = (int) TestKit::get($exact, 'data.id');
$t->check('until a variant is called exactly that: then only it is found', [$exactId], $matched((string) $family, [$shadeId, $exactId]));

// -----------------------------------------------------------------------------
$t->section('A product can be marked for adults only');
$hamper = $makeProduct('hamper', ['is_age_restricted' => true, 'variants' => [['stock_quantity' => 30]]]);
$hamperId = (int) TestKit::get($hamper, 'data.id');
$hamperSlug = "guard-hamper-{$stamp}";
$t->check('the mark is saved; in a shop without the age check it changes nothing', [201, true, false], [$hamper['status'], TestKit::get($hamper, 'data.is_age_restricted'), TestKit::get($hamper, 'data.age_restricted')]);
$row = (array) TestKit::get($A('GET', '/admin/products?search=' . urlencode($hamperSlug)), 'data.0');
$t->check('the admin list row carries the mark and the answer', [true, false], [$row['is_age_restricted'] ?? null, $row['age_restricted'] ?? null]);
$plainRow = (array) TestKit::get($A('GET', '/admin/products?search=' . urlencode("guard-plain-{$stamp}")), 'data.0');
$t->check('an unmarked product: false for both', [false, false], [$plainRow['is_age_restricted'] ?? null, $plainRow['age_restricted'] ?? null]);
$renamed = $update($hamperId, ['name' => "Guard hamper renamed {$stamp}"]);
$t->check('an update without the key keeps the mark', [200, true], [$renamed['status'], TestKit::get($renamed, 'data.is_age_restricted')]);
$cleared = $update($hamperId, ['is_age_restricted' => 0]);
$t->check('0 clears it', [200, false, 0], [$cleared['status'], TestKit::get($cleared, 'data.is_age_restricted'), (int) $t->scalar("SELECT `is_age_restricted` FROM `products` WHERE `id` = ?", 'i', [$hamperId])]);
$marked = $update($hamperId, ['is_age_restricted' => '1']);
$t->check('"1" sets it', [200, true], [$marked['status'], TestKit::get($marked, 'data.is_age_restricted')]);
$bad = $update($hamperId, ['is_age_restricted' => 'maybe']);
$t->check('anything else: 422 against is_age_restricted', [422, ['is_age_restricted']], [$bad['status'], $details($bad)]);
$freshLimit();
$unchecked = $order($t->api, [$line($hamper)]);
$t->check('this shop asks for no confirmation', 201, $unchecked['status']);
$t->check('and records none', null, $t->scalar("SELECT `age_confirmed_at` FROM `orders` WHERE `id` = ?", 'i', [$unchecked['order_id']]));

$adult = $makeProduct('adult', ['category_id' => $accessories, 'variants' => [['stock_quantity' => 30]]]);
$otherPort = (int) (getenv('TEST_SAFEGUARD_API_PORT') ?: 8144);
$other = $t->startApi($otherPort, ['features' => ['ageCheck' => true], 'ageCheck' => ['minAge' => 18, 'categories' => ['accessories']]]);
$t->ok('a shop with the age check on (its own API on the same database)', $other !== null);
if ($other === null) {
    exit($t->finish());
}
$onOther = static fn (string $method, string $path, array $opts = []): array => $t->http($method, $other . $path, $opts + $as);

$detail = $t->http('GET', "{$other}/products/{$hamperSlug}");
$t->check('there the marked product is for adults only, though its category is not', [true, true], [TestKit::get($detail, 'data.is_age_restricted'), TestKit::get($detail, 'data.age_restricted')]);
$detail = $t->http('GET', "{$other}/products/guard-adult-{$stamp}");
$t->check('a product in a listed category: not marked, but restricted', [false, true], [TestKit::get($detail, 'data.is_age_restricted'), TestKit::get($detail, 'data.age_restricted')]);
$detail = $t->http('GET', "{$other}/products/guard-plain-{$stamp}");
$t->check('any other product: neither', [false, false], [TestKit::get($detail, 'data.is_age_restricted'), TestKit::get($detail, 'data.age_restricted')]);
$row = (array) TestKit::get($onOther('GET', '/admin/products?search=' . urlencode($hamperSlug)), 'data.0');
$t->check('the admin list there: marked and restricted', [true, true], [$row['is_age_restricted'] ?? null, $row['age_restricted'] ?? null]);
Shop::overrideForTests(['features' => ['ageCheck' => true], 'ageCheck' => ['categories' => ['accessories']]]);
$listed = array_column($repo->listPublished(['ids' => [$hamperId, $plainId, (int) TestKit::get($adult, 'data.id')]], 1, 10)['items'], null, 'id');
Shop::overrideForTests([]);
$t->check('product lists say which are restricted', [true, false, true], [$listed[$hamperId]['age_restricted'] ?? null, $listed[$plainId]['age_restricted'] ?? null, $listed[(int) TestKit::get($adult, 'data.id')]['age_restricted'] ?? null]);
$t->ok('and only that: the mark itself is not in a list row', !array_key_exists('is_age_restricted', $listed[$hamperId] ?? ['is_age_restricted' => 1]));

$t->section('The confirmation is asked for, and kept on the order');
$asked = $quote($other, [$line($hamper), $line($plain)]);
$t->check('the quote asks for it because of the marked product', [200, true, true, false], [$asked['status'], TestKit::get($asked, 'data.age_check_required'), TestKit::get($asked, 'data.items.0.age_restricted'), TestKit::get($asked, 'data.items.1.age_restricted')]);
$t->check('a quote with nothing restricted does not', false, TestKit::get($quote($other, [$line($plain)]), 'data.age_check_required'));
$freshLimit();
$refused = $order($other, [$line($hamper)]);
$t->check('an order without it: 422 against age_confirmed', [422, ['age_confirmed']], [$refused['status'], $details($refused)]);
$confirmed = $order($other, [$line($hamper)], ['age_confirmed' => true]);
$t->check('with it, the order is placed', 201, $confirmed['status']);
$at = (string) $t->scalar("SELECT `age_confirmed_at` FROM `orders` WHERE `id` = ?", 'i', [$confirmed['order_id']]);
$t->ok('and when it was confirmed is saved on the order', $at !== '' && abs(strtotime($at) - time()) < 120, $at);
$adminOrder = $A('GET', '/admin/orders/' . rawurlencode((string) TestKit::get($confirmed, 'data.order_number')));
$t->check('the admin order page gets it', [200, $at], [$adminOrder['status'], TestKit::get($adminOrder, 'data.age_confirmed_at')]);
$needless = $order($other, [$line($plain)], ['age_confirmed' => true]);
$t->check('an order that needed none records none, even when it was sent', [201, null], [$needless['status'], $t->scalar("SELECT `age_confirmed_at` FROM `orders` WHERE `id` = ?", 'i', [$needless['order_id']])]);
$adminOrder = $A('GET', '/admin/orders/' . rawurlencode((string) TestKit::get($needless, 'data.order_number')));
$t->ok('and the admin order page says so with null', $adminOrder['status'] === 200 && array_key_exists('age_confirmed_at', (array) TestKit::get($adminOrder, 'data')) && TestKit::get($adminOrder, 'data.age_confirmed_at') === null);
$byCategory = $order($other, [$line($adult)], ['age_confirmed' => true]);
$t->ok('a product restricted by its category is recorded the same way', $byCategory['status'] === 201 && $t->scalar("SELECT `age_confirmed_at` FROM `orders` WHERE `id` = ?", 'i', [$byCategory['order_id']]) !== null);
$freshLimit();

$t->section('A category for adults only cannot be renamed out of the check');
$accessoryName = (string) $t->scalar("SELECT `name` FROM `categories` WHERE `id` = ?", 'i', [$accessories]);
$t->cleanup(fn () => $t->sql("UPDATE `categories` SET `name` = ?, `slug` = 'accessories' WHERE `id` = ?", 'si', [$accessoryName, $accessories]));
$moved = $onOther('PUT', "/admin/categories/{$accessories}", ['json' => ['slug' => 'bags-and-belts']]);
$t->check('a new slug is refused against the field', [422, ['slug']], [$moved['status'], $details($moved)]);
$t->check('with the reason', 'Products in this category are for adults only, so its address cannot be changed.', TestKit::get($moved, 'error.details.slug'));
$t->check('and the slug is as it was', 'accessories', $t->scalar("SELECT `slug` FROM `categories` WHERE `id` = ?", 'i', [$accessories]));
$sameSlug = $onOther('PUT', "/admin/categories/{$accessories}", ['json' => ['name' => 'Accessories & More', 'slug' => 'accessories']]);
$t->check('its name can change while the slug stays', [200, 'Accessories & More', 'accessories'], [$sameSlug['status'], TestKit::get($sameSlug, 'data.name'), TestKit::get($sameSlug, 'data.slug')]);
$temp = $onOther('POST', '/admin/categories', ['json' => ['name' => "Guard cat {$stamp}", 'slug' => "guard-cat-{$stamp}"]]);
$tempId = (int) TestKit::get($temp, 'data.id');
$t->cleanup(fn () => $t->sql("DELETE FROM `categories` WHERE `id` = ?", 'i', [$tempId]));
$t->check('any other category can still be given a new slug', [200, "guard-cat-two-{$stamp}"], (static function () use ($onOther, $tempId, $stamp): array {
    $res = $onOther('PUT', "/admin/categories/{$tempId}", ['json' => ['slug' => "guard-cat-two-{$stamp}"]]);
    return [$res['status'], TestKit::get($res, 'data.slug')];
})());
$free = $A('PUT', "/admin/categories/{$accessories}", ['json' => ['slug' => 'accessories-shop']]);
$t->check('and a shop without the age check can rename this one', [200, 'accessories-shop'], [$free['status'], TestKit::get($free, 'data.slug')]);
$t->sql("UPDATE `categories` SET `name` = ?, `slug` = 'accessories' WHERE `id` = ?", 'si', [$accessoryName, $accessories]);
\App\Support\Cache::bumpCatalog(); // put back behind the API's back: later suites must not read the renamed one from the cache

// -----------------------------------------------------------------------------
$t->section('A starting sales figure is added to, not replaced');
$salesBefore = $t->sql("SELECT `id`, `units_sold` FROM `products`");
$t->cleanup(function () use ($t, $salesBefore): void {
    foreach ($salesBefore as $row) {
        $t->sql("UPDATE `products` SET `units_sold` = ? WHERE `id` = ?", 'ii', [(int) $row['units_sold'], (int) $row['id']]);
    }
});
$sold = static fn (int $productId): int => (int) $t->scalar("SELECT `units_sold` FROM `products` WHERE `id` = ?", 'i', [$productId]);
$t->sql("UPDATE `products` SET `units_sold_start` = 12, `units_sold` = 12 WHERE `id` = ?", 'i', [$plainId]);
$freshLimit();
$sale = $order($t->api, [$line($plain, 2), $line($stocked, 3)]);
$t->check('an order for 2 of a product that starts at 12, and 3 of one that starts at 0', 201, $sale['status']);
$t->check('unpaid, it changes nothing', [12, 0], [$sold($plainId), $sold($stockedId)]);
$t->sql("UPDATE `orders` SET `payment_status` = 'successful', `order_status` = 'processing' WHERE `id` = ?", 'i', [$sale['order_id']]);
ProductSales::refreshForOrder($t->db, $sale['order_id']);
$t->check('paid: the real units are added to the starting figure', [14, 3], [$sold($plainId), $sold($stockedId)]);
ProductSales::refreshAll($t->db);
$t->check('a full recount gives the same', [14, 3], [$sold($plainId), $sold($stockedId)]);
$t->sql("UPDATE `orders` SET `order_status` = 'cancelled' WHERE `id` = ?", 'i', [$sale['order_id']]);
ProductSales::refreshForOrder($t->db, $sale['order_id']);
$t->check('cancelled: back to the starting figure, never below it', [12, 0], [$sold($plainId), $sold($stockedId)]);
$adminPlain = $update($plainId, ['name' => "Guard plain again {$stamp}"]);
$t->ok('the starting figure is not part of any answer', $adminPlain['status'] === 200 && !array_key_exists('units_sold_start', (array) TestKit::get($adminPlain, 'data')) && (int) TestKit::get($adminPlain, 'data.units_sold') === 12
    && !str_contains($t->http('GET', "/products/guard-plain-{$stamp}")['body'], 'units_sold'));
$t->check('and an edit leaves it alone', 12, (int) $t->scalar("SELECT `units_sold_start` FROM `products` WHERE `id` = ?", 'i', [$plainId]));

// -----------------------------------------------------------------------------
$t->section('A missing or broken settings file stops the shop');
$configFile = str_replace('\\', '/', sys_get_temp_dir()) . "/shopco-guard-{$stamp}.json";
$t->cleanup(static fn () => @unlink($configFile));
$real = (string) file_get_contents(BASE_PATH . '/config/shop.json');
$brokenPort = $otherPort + 1;
$broken = $t->startApi($brokenPort, [], ['SHOP_CONFIG_FILE' => $configFile]);
$t->ok('an API whose settings file does not exist still answers', $broken !== null);
$health = $t->http('GET', "{$broken}/health");
$t->check('with a 500 on every request', [500, 'SHOP_CONFIG_ERROR'], [$health['status'], TestKit::get($health, 'error.code')]);
$said = (string) TestKit::get($health, 'error.message');
$t->ok('that says the settings file could not be read', str_contains($said, "This shop's settings file could not be read") && str_contains($said, 'backend/config/shop.json') && str_contains($said, 'missing'), $said);
$t->check('the catalogue is not served as some default shop', 500, $t->http('GET', "{$broken}/products")['status']);
$t->check('nor is checkout', 500, $quote((string) $broken, [$line($plain)])['status']);
$t->ok('one clear line goes to the PHP error log', str_contains($t->apiLog($brokenPort), "[Shop] This shop's settings file could not be read"), substr($t->apiLog($brokenPort), 0, 300));

file_put_contents($configFile, '{"name": "HALF.WRITTEN", "option1": {');
$health = $t->http('GET', "{$broken}/health");
$t->ok('a file that is not valid JSON: the same, saying so', $health['status'] === 500 && str_contains((string) TestKit::get($health, 'error.message'), 'not valid JSON'), (string) TestKit::get($health, 'error.message'));
file_put_contents($configFile, '["a", "list"]');
$t->check('a file that is JSON but not an object', 500, $t->http('GET', "{$broken}/health")['status']);
file_put_contents($configFile, "\xEF\xBB\xBF" . $real);
$t->check('a good file saved with a byte order mark is read', 200, $t->http('GET', "{$broken}/health")['status']);
$t->check('and the shop works', 200, $t->http('GET', "{$broken}/products?per_page=1")['status']);
file_put_contents($configFile, $real);
$t->check('as does the same file without one', 200, $t->http('GET', "{$broken}/health")['status']);

// Command-line scripts: the same message, and they stop.
$script = str_replace('\\', '/', sys_get_temp_dir()) . "/shopco-guard-{$stamp}.php";
$scriptEnv = str_replace('\\', '/', sys_get_temp_dir()) . "/shopco-guard-{$stamp}.env";
$t->cleanup(static function () use ($script, $scriptEnv): void {
    @unlink($script);
    @unlink($scriptEnv);
});
file_put_contents($script, '<?php define("BASE_PATH", ' . var_export(BASE_PATH, true) . '); require BASE_PATH . "/src/autoload.php";'
    . ' App\Config\Env::load(BASE_PATH . "/.env"); echo "kind=" . App\Config\Shop::get("kind", "none") . "|minAge=" . App\Config\Shop::minimumAge();');
file_put_contents($scriptEnv, implode("\n", array_merge(file((string) getenv('SHOPCO_ENV_FILE'), FILE_IGNORE_NEW_LINES) ?: [], ["SHOP_CONFIG_FILE={$configFile}"])) . "\n");
$cli = static function () use ($script, $scriptEnv): array {
    $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, array_merge(getenv(), ['SHOPCO_ENV_FILE' => $scriptEnv, 'SHOP_CONFIG_OVERRIDES' => '']));
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), (string) $out, (string) $err];
};
file_put_contents($configFile, "\xEF\xBB\xBF" . '{"kind": "a shop saved with a mark", "ageCheck": {"minAge": 21}}');
[$code, $out, $err] = $cli();
$t->check('a script reads a file with a byte order mark', [0, 'kind=a shop saved with a mark|minAge=21'], [$code, $out]);
unlink($configFile);
[$code, $out, $err] = $cli();
$t->ok('without the file it prints the message and stops', $code === 1 && $out === '' && str_contains($err, "This shop's settings file could not be read") && !str_contains($err, 'Stack trace'), "code {$code}: {$out} {$err}");

// The other file is a test hook only: a real shop always reads config/shop.json.
$vars = new ReflectionProperty(Env::class, 'vars');
$originalEnv = $vars->getValue();
$t->cleanup(function () use ($vars, $originalEnv): void {
    $vars->setValue(null, $originalEnv);
    Shop::overrideForTests([]);
});
$vars->setValue(null, ['APP_ENV' => 'production', 'SHOP_CONFIG_FILE' => $configFile] + $originalEnv);
Shop::overrideForTests([]);
$t->check('on a real shop SHOP_CONFIG_FILE is ignored', json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', $real), true)['name'] ?? null, Shop::get('name'));
$vars->setValue(null, $originalEnv);
Shop::overrideForTests([]);
$t->check('back in the tests, the fixture shop', 'SHOP.CO', Shop::name());

exit($t->finish());
