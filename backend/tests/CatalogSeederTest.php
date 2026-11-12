<?php

declare(strict_types=1);

/**
 * The demo catalogue seeder (backend/seeds/seed.php with a catalogue .json file,
 * App\Services\CatalogSeeder): one file fills a new shop with settings, delivery zones, a
 * promotion, categories, banners, products, pictures and reviews. It must check the whole file
 * before writing anything, and never touch a shop that already has products.
 *
 * It runs against its own throw-away database (the test database's name plus "_seed"), made and
 * dropped here, with its own pictures folder.
 *
 *   php backend/tests/run.php CatalogSeeder
 */

require __DIR__ . '/support/TestKit.php';

use App\Config\Env;

$t = new TestKit('CatalogSeeder');
$seedDb = preg_replace('/[^A-Za-z0-9_]/', '', (string) Env::get('DB_DATABASE', 'shopco_test')) . '_seed';
$tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/shopco-seeder-' . getmypid();
$uploads = "{$tmp}/uploads";
@mkdir("{$tmp}/shop/pictures", 0700, true);
@mkdir($uploads, 0700, true);

$t->sql("DROP DATABASE IF EXISTS `{$seedDb}`");
$t->sql("CREATE DATABASE `{$seedDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$t->cleanup(function () use ($t, $seedDb, $tmp): void {
    $t->sql("DROP DATABASE IF EXISTS `{$seedDb}`");
    if (is_dir($tmp)) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($tmp);
    }
});

// The test environment, pointed at the throw-away database and pictures folder.
$envFile = "{$tmp}/env";
$lines = array_values(array_filter(
    file((string) getenv('SHOPCO_ENV_FILE'), FILE_IGNORE_NEW_LINES) ?: [],
    static fn (string $l): bool => !preg_match('/^(DB_DATABASE|UPLOAD_DIR)=/', $l)
));
file_put_contents($envFile, implode("\n", array_merge($lines, ["DB_DATABASE={$seedDb}", "UPLOAD_DIR={$uploads}"])) . "\n");

/** Run a backend script on the throw-away database. @return array{0: int, 1: string} */
$run = static function (string $script, array $args = []) use ($envFile): array {
    $proc = proc_open(array_merge([PHP_BINARY, BASE_PATH . $script], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, array_merge(getenv(), ['SHOPCO_ENV_FILE' => $envFile]));
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out];
};
$value = static fn (string $sql, string $types = '', array $params = []): mixed => $t->scalar($sql, $types, $params);
$count = static fn (string $table, string $where = '1=1'): int => (int) $t->scalar("SELECT COUNT(*) FROM `{$seedDb}`.`{$table}` WHERE {$where}");
$rowOf = static fn (string $table, string $where): array => (array) ($t->sql("SELECT * FROM `{$seedDb}`.`{$table}` WHERE {$where} LIMIT 1")[0] ?? []);
/** A column that may hold NULL: the value, or 'missing' when the row has no such column. */
$col = static fn (array $row, string $name): mixed => array_key_exists($name, $row) ? $row[$name] : 'missing';
$write = static function (array $catalog) use ($tmp): string {
    file_put_contents("{$tmp}/shop/catalog.json", json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return "{$tmp}/shop/catalog.json";
};

[$code, $out] = $run('/migrations/migrate.php');
$t->ok('the throw-away database is migrated', $code === 0 && $count('schema_migrations') > 0, $out);
$t->check('migration 030 added the product columns', 3, (int) $value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'products' AND COLUMN_NAME IN ('specifications', 'warranty', 'is_heavy')", 's', [$seedDb]));
$t->check('migration 031 added the heavy item fee', 1, (int) $value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'shipping_zones' AND COLUMN_NAME = 'heavy_item_fee'", 's', [$seedDb]));
foreach (['030_product_specifications.sql', '031_heavy_item_delivery.sql'] as $migration) {
    $again = mysqli_connect((string) Env::get('DB_HOST', '127.0.0.1'), (string) Env::get('DB_USERNAME', 'root'), (string) Env::get('DB_PASSWORD', ''), $seedDb, Env::int('DB_PORT', 3306));
    $ok = $again->multi_query((string) file_get_contents(BASE_PATH . "/migrations/{$migration}"));
    while ($ok && $again->more_results() && $again->next_result()) {
        // every statement of the file
    }
    $t->ok("{$migration} can be run a second time", $ok && $again->errno === 0, $again->error);
    $again->close();
}

// -----------------------------------------------------------------------------
$t->section('A file with mistakes writes nothing');
$bad = $write([
    'settings' => ['store_name' => 'Bad Shop', 'dev_mode' => 'on'],
    'zones' => [['name' => 'Lagos', 'states' => ['Lagos', 'Atlantis'], 'fee' => 2500]],
    'promotions' => [['code' => 'OFF5000', 'type' => 'fixed', 'value' => 5000]],
    'categories' => [['name' => 'Scents', 'slug' => 'scents', 'image' => 'cat-missing.webp']],
    'products' => [
        ['name' => 'Good One', 'category' => 'scents', 'price' => 1000, 'images' => ['p-missing.png', '../secrets.png']],
        ['name' => 'No Price', 'category' => 'nowhere', 'variants' => [['option1' => '50ml'], ['option1' => '50ML']]],
    ],
    'reviews' => [['product' => 'not-a-product', 'name' => 'Ada', 'rating' => 9, 'body' => 'Lovely']],
]);
[$code, $out] = $run('/seeds/seed.php', [$bad]);
$t->check('it stops', 1, $code);
foreach ([
    'a setting it may not set' => '"dev_mode" is not a setting this file can set',
    'a state that does not exist' => '"Atlantis" is not a Nigerian state',
    'a fixed amount without a minimum order' => 'promotion OFF5000: a fixed amount needs a "min_order"',
    'a missing picture, by name' => 'Missing pictures',
    'the first missing picture' => 'cat-missing.webp',
    'the second missing picture' => 'p-missing.png',
    'a picture name that is a path' => '"../secrets.png" is not a picture file name',
    'a product without a price' => 'product "No Price": base_price',
    'a category that is not in the file' => 'the category "nowhere" is not in the file',
    'two variants that are the same' => 'another variant already has the same size and colour',
    'a review for an unknown product' => '"not-a-product" is not the slug of a product in the file',
    'a rating out of range' => 'the rating must be a whole number from 1 to 5',
    'that nothing was written' => 'nothing was written',
] as $label => $needle) {
    $t->ok("it reports {$label}", str_contains($out, $needle), $out);
}
$t->check('no category, product, zone or review was written', [0, 0, 0, 0], [$count('categories'), $count('products'), $count('shipping_zones'), $count('reviews')]);
$t->check('the store name was not changed', 0, $count('store_settings', "`key_name` = 'store_name'"));
$t->check('and no picture was copied', [], glob("{$uploads}/catalog/*") ?: []);
[$code, $out] = $run('/seeds/seed.php', ["{$tmp}/no-such-file.json"]);
$t->ok('a file that does not exist stops with a message', $code === 1 && str_contains($out, 'is not a folder of .sql files or a catalogue .json file'), $out);
file_put_contents("{$tmp}/shop/broken.json", '{"products": [');
[$code, $out] = $run('/seeds/seed.php', ["{$tmp}/shop/broken.json"]);
$t->ok('so does a file that is not JSON', $code === 1 && str_contains($out, 'is not valid JSON'), $out);

// -----------------------------------------------------------------------------
$t->section('The example file seeds a working shop');
$example = BASE_PATH . '/seeds/demo/catalog.example.json';
[$code, $out] = $run('/seeds/seed.php', [$example]);
$t->ok('it runs', $code === 0 && str_contains($out, '[SUCCESS]'), $out);
$t->check('one category, one product, two variants, two zones, one code, one review', [1, 1, 2, 2, 1, 1], [$count('categories'), $count('products'), $count('product_variants'), $count('shipping_zones'), $count('promotions'), $count('reviews')]);
$product = $rowOf('products', "`slug` = 'example-product'");
$t->check('the product: price, was price, category, published, featured', [12000.0, 15000.0, 'everyday', 'published', 1, 0], [
    (float) ($product['base_price'] ?? 0), (float) ($product['original_price'] ?? 0),
    $value("SELECT `slug` FROM `{$seedDb}`.`categories` WHERE `id` = ?", 'i', [(int) ($product['category_id'] ?? 0)]),
    $product['status'] ?? null, (int) ($product['is_featured'] ?? -1), (int) ($product['is_flash_deal'] ?? -1),
]);
$t->check('its specifications', [['name' => 'Made in', 'value' => 'Nigeria']], json_decode((string) ($product['specifications'] ?? ''), true));
$t->check('no warranty, not heavy, no style', [null, 0, ''], [$col($product, 'warranty'), (int) ($product['is_heavy'] ?? -1), $col($product, 'dress_style')]);
$variants = $t->sql("SELECT `sku`, `size`, `color_name`, `color_hex`, `price_override`, `stock_quantity` FROM `{$seedDb}`.`product_variants` ORDER BY `id`");
$t->check('the variants: options, own price, stock', [['M', 'Black', null, 20], ['L', 'Black', 13000.0, 10]], array_map(
    static fn (array $v): array => [$v['size'], $v['color_name'], $v['price_override'] === null ? null : (float) $v['price_override'], (int) $v['stock_quantity']],
    $variants
));
$sku = (string) ($product['sku'] ?? '');
$t->ok('SKUs are made the way the admin makes them', preg_match('/^EVE-EXAMPLE-+[0-9A-F]{4}$/', $sku) === 1 && array_column($variants, 'sku') === ["{$sku}-BLA-M", "{$sku}-BLA-L"], $sku . ' / ' . implode(',', array_column($variants, 'sku')));
$zone = $rowOf('shipping_zones', "`name` = 'Lagos'");
$t->check('the Lagos zone: states, fee, items included, extra and heavy fees, estimate, a code', [['Lagos'], 2500.0, 3, 300.0, 0.0, '1 to 2 days', 'ZONE-LAGOS', 1], [
    json_decode((string) ($zone['states_covered'] ?? ''), true), (float) ($zone['fee'] ?? 0), (int) ($zone['items_included'] ?? 0), (float) ($zone['extra_item_fee'] ?? -1),
    (float) ($zone['heavy_item_fee'] ?? -1), $zone['estimated_days'] ?? null, $zone['code'] ?? null, (int) ($zone['is_active'] ?? 0),
]);
$t->check('the other zone covers the rest of the country', ['*'], json_decode((string) $value("SELECT `states_covered` FROM `{$seedDb}`.`shipping_zones` WHERE `name` = 'Rest of Nigeria'"), true));
$promo = $rowOf('promotions', "`code` = 'WELCOME10'");
$t->check('the promotion: a percentage, shown on the homepage, no minimum or cap, no limit', ['percent', 10, null, null, null, 0, 1, 1], [
    $promo['discount_type'] ?? null, (int) ($promo['discount_percent'] ?? 0), $col($promo, 'discount_amount'), $col($promo, 'min_order_amount'),
    $col($promo, 'max_discount_amount'), (int) ($promo['max_uses'] ?? -1), (int) ($promo['is_active'] ?? 0), (int) ($promo['show_on_homepage'] ?? 0),
]);
$review = $rowOf('reviews', '1=1');
$t->check('the review is stored as an approved one', ['approved', 'Ada O.', 5, 'Just right', null], [$review['status'] ?? null, $review['author_name'] ?? null, (int) ($review['rating'] ?? 0), $review['title'] ?? null, $col($review, 'user_id')]);
$t->ok('ratings are recomputed: the starting rating and the review together', (int) ($product['rating_count'] ?? 0) === 1 && (float) ($product['rating_avg'] ?? 0) > 4.6 && (float) ($product['rating_avg'] ?? 0) < 5 && (float) ($product['rating_start'] ?? 0) === 4.6, json_encode([$product['rating_count'] ?? null, $product['rating_avg'] ?? null]));
$t->check('settings from the file are saved', ['hello@example.com', '4.5'], [
    $value("SELECT `value` FROM `{$seedDb}`.`store_settings` WHERE `key_name` = 'store_contact_email'"),
    $value("SELECT `value` FROM `{$seedDb}`.`store_settings` WHERE `key_name` = 'default_product_rating'"),
]);

// -----------------------------------------------------------------------------
$t->section('It never adds to a shop that already has products');
[$code, $out] = $run('/seeds/seed.php', [$example]);
$t->ok('a second run stops and says why', $code === 1 && str_contains($out, 'already has 1 product') && str_contains($out, 'nothing was changed'), $out);
$t->check('and nothing was added', [1, 1, 2, 2, 1, 1], [$count('categories'), $count('products'), $count('product_variants'), $count('shipping_zones'), $count('promotions'), $count('reviews')]);
$before = [(int) $t->scalar('SELECT COUNT(*) FROM `products`'), (int) $t->scalar('SELECT COUNT(*) FROM `categories`')];
$proc = proc_open([PHP_BINARY, BASE_PATH . '/seeds/seed.php', $example], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, getenv());
$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
$t->ok('the test shop, which has the fixture products, is refused too', proc_close($proc) === 1 && str_contains($out, 'already has'), $out);
$t->check('and left as it was', $before, [(int) $t->scalar('SELECT COUNT(*) FROM `products`'), (int) $t->scalar('SELECT COUNT(*) FROM `categories`')]);

// -----------------------------------------------------------------------------
$t->section('Pictures, products without an option, and what a shop already has');
// The throw-away shop loses its products (reviews and variants go with them); its zones,
// category and promotion stay, as an owner's own would.
$t->sql("DELETE FROM `{$seedDb}`.`products`");
copy(__DIR__ . '/fixtures/plain.png', "{$tmp}/shop/pictures/p-oud-1.png");
copy(__DIR__ . '/fixtures/meta.webp', "{$tmp}/shop/pictures/p-oud-2.webp");
copy(__DIR__ . '/fixtures/plain.png', "{$tmp}/shop/pictures/cat-for-her.png");
copy(__DIR__ . '/fixtures/plain.png', "{$tmp}/shop/pictures/banner-1.png");
$rich = $write([
    'settings' => ['store_name' => 'SCENT.CO', 'store_origin_city' => 'Lagos', 'storefront_brands' => ''],
    'zones' => [['name' => 'Abuja', 'states' => ['Abuja'], 'fee' => 3000]],
    'promotions' => [
        ['code' => 'WELCOME10', 'type' => 'percentage', 'value' => 25],
        ['code' => 'take2000', 'type' => 'fixed', 'value' => 2000, 'min_order' => 20000, 'description' => '2,000 off orders over 20,000'],
    ],
    'categories' => [
        ['name' => 'Everyday', 'slug' => 'everyday', 'description' => 'Changed in the file'],
        ['name' => 'For Her', 'description' => 'Soft and warm.', 'image' => 'cat-for-her.png'],
    ],
    'banners' => [['title' => 'New season', 'subtitle' => 'Fresh arrivals', 'cta_text' => 'Shop now', 'cta_url' => '/category', 'image' => 'banner-1.png']],
    'products' => [
        [
            'name' => 'Oud  Royale', 'category' => 'for-her', 'description' => 'Deep and smoky.', 'price' => 25000, 'original_price' => 30000,
            'style' => 'Woody', 'featured' => true, 'flash_deal' => true, 'rating_start' => 4.7, 'units_sold' => 12, 'warranty' => '6 months', 'heavy' => true,
            'specifications' => [['name' => 'Top notes', 'value' => 'Bergamot, pink pepper'], ['name' => '', 'value' => 'dropped']],
            'images' => ['p-oud-1.png', 'p-oud-2.webp', '/images/hero/extra.webp'],
            'variants' => [
                ['option1' => '50ml', 'option2' => 'Eau de Parfum', 'stock' => 25],
                ['option1' => '50ml', 'option2' => 'Eau de Toilette', 'price' => 21000, 'stock' => 8],
                ['option1' => '100ml', 'option2' => '', 'hex' => '#112233', 'stock' => 3],
            ],
        ],
        ['name' => 'Gift Card Holder', 'price' => 1500, 'variants' => [['stock' => 40]]],
        ['name' => 'Sample Vial', 'category' => 'everyday', 'price' => 900],
    ],
    'reviews' => [
        ['product' => 'oud-royale', 'name' => 'Ada O.', 'rating' => 5, 'title' => 'Lasts all day', 'body' => 'My new favourite.'],
        ['product' => 'oud-royale', 'name' => 'Bola A.', 'rating' => 4, 'body' => 'Strong at first, then lovely.'],
    ],
]);
[$code, $out] = $run('/seeds/seed.php', ["{$tmp}/shop"]);
$t->ok('a folder holding catalog.json works as the argument', $code === 0 && str_contains($out, '[SUCCESS]'), $out);
$t->ok('zones the shop already has are left alone, and it says so', $count('shipping_zones') === 2 && str_contains($out, 'Delivery zones were left as they are'), $out);
$t->ok('so is a promotion code that exists', (int) $value("SELECT `discount_percent` FROM `{$seedDb}`.`promotions` WHERE `code` = 'WELCOME10'") === 10 && str_contains($out, 'Promotion WELCOME10 was left as it is'), $out);
$fixed = $rowOf('promotions', "`code` = 'TAKE2000'");
$t->check('a fixed-amount code is stored as the admin stores one', ['fixed', 0, 2000.0, 20000.0, null, 0], [
    $fixed['discount_type'] ?? null, (int) ($fixed['discount_percent'] ?? -1), (float) ($fixed['discount_amount'] ?? 0), (float) ($fixed['min_order_amount'] ?? 0),
    $col($fixed, 'max_discount_amount'), (int) ($fixed['show_on_homepage'] ?? -1),
]);
$t->ok('an existing category is kept as it was', $value("SELECT `description` FROM `{$seedDb}`.`categories` WHERE `slug` = 'everyday'") === 'Things you reach for every day.' && str_contains($out, 'Category everyday was left as it is'));
$t->check('a new category gets a slug from its name and its picture', ['for-her', '/uploads/catalog/cat-for-her.png'], array_values($t->sql("SELECT `slug`, `image_url` FROM `{$seedDb}`.`categories` WHERE `name` = 'For Her'")[0] ?? []));
$banner = $rowOf('banners', '1=1');
$t->check('the banner', ['New season', 'Fresh arrivals', 'Shop now', '/category', '/uploads/catalog/banner-1.png', 1], [$banner['title'] ?? null, $banner['subtitle'] ?? null, $banner['cta_text'] ?? null, $banner['cta_url'] ?? null, $banner['image_url'] ?? null, (int) ($banner['is_active'] ?? 0)]);
$t->check('the store name from the file', 'SCENT.CO', $value("SELECT `value` FROM `{$seedDb}`.`store_settings` WHERE `key_name` = 'store_name'"));

$oud = $rowOf('products', "`slug` = 'oud-royale'");
$oudId = (int) ($oud['id'] ?? 0);
$t->check('the product: name tidied, style, warranty, heavy, sold, specifications', ['Oud Royale', 'Woody', '6 months', 1, 12, [['name' => 'Top notes', 'value' => 'Bergamot, pink pepper']]], [
    $oud['name'] ?? null, $oud['dress_style'] ?? null, $oud['warranty'] ?? null, (int) ($oud['is_heavy'] ?? 0), (int) ($oud['units_sold'] ?? 0), json_decode((string) ($oud['specifications'] ?? ''), true),
]);
$ends = strtotime((string) ($oud['flash_deal_ends_at'] ?? '')) ?: 0;
$t->ok('a flash deal ends 7 days after seeding', (int) ($oud['is_flash_deal'] ?? 0) === 1 && abs($ends - (time() + 7 * 86400)) < 600, (string) ($oud['flash_deal_ends_at'] ?? ''));
$t->check('pictures in order, the first one the main one', [['/uploads/catalog/p-oud-1.png', 1], ['/uploads/catalog/p-oud-2.webp', 0], ['/images/hero/extra.webp', 0]], array_map(
    static fn (array $i): array => [$i['image_url'], (int) $i['is_primary']],
    $t->sql("SELECT `image_url`, `is_primary` FROM `{$seedDb}`.`product_images` WHERE `product_id` = ? ORDER BY `display_order`", 'i', [$oudId])
));
$copied = array_map('basename', glob("{$uploads}/catalog/*") ?: []);
sort($copied);
$t->check('the pictures are copied into the picture storage under catalog/', ['banner-1.png', 'cat-for-her.png', 'p-oud-1.png', 'p-oud-2.webp'], $copied);
$t->check('byte for byte', hash_file('sha256', __DIR__ . '/fixtures/meta.webp'), hash_file('sha256', "{$uploads}/catalog/p-oud-2.webp"));
$oudVariants = $t->sql("SELECT `sku`, `size`, `color_name`, `color_hex`, `price_override`, `stock_quantity` FROM `{$seedDb}`.`product_variants` WHERE `product_id` = ? ORDER BY `id`", 'i', [$oudId]);
$t->check('variants: an empty second option is stored as the "none" word', [['50ml', 'Eau de Parfum', '#000000', null, 25], ['50ml', 'Eau de Toilette', '#000000', 21000.0, 8], ['100ml', 'Default', '#112233', null, 3]], array_map(
    static fn (array $v): array => [$v['size'], $v['color_name'], $v['color_hex'], $v['price_override'] === null ? null : (float) $v['price_override'], (int) $v['stock_quantity']],
    $oudVariants
));
$skus = array_column($oudVariants, 'sku');
$t->ok('options that shorten to the same letters still get their own SKU', count(array_unique($skus)) === 3 && $skus[1] === $skus[0] . '-2', implode(',', $skus));
$holder = $t->sql("SELECT v.`size`, v.`color_name`, v.`stock_quantity`, p.`category_id` FROM `{$seedDb}`.`product_variants` v JOIN `{$seedDb}`.`products` p ON p.id = v.product_id WHERE p.`slug` = 'gift-card-holder'");
$t->check('a variant with no options, on a product with no category', [['Standard', 'Default', 40, null]], array_map(static fn (array $v): array => [$v['size'], $v['color_name'], (int) $v['stock_quantity'], $v['category_id']], $holder));
$t->check('a product without variants gets one plain variant', [['Standard', 'Default']], array_map('array_values', $t->sql("SELECT v.`size`, v.`color_name` FROM `{$seedDb}`.`product_variants` v JOIN `{$seedDb}`.`products` p ON p.id = v.product_id WHERE p.`slug` = 'sample-vial'")));
$t->check('the reviews keep the file\'s order, newest first', ['Ada O.', 'Bola A.'], array_column($t->sql("SELECT `author_name` FROM `{$seedDb}`.`reviews` WHERE `product_id` = ? AND `status` = 'approved' ORDER BY `created_at` DESC", 'i', [$oudId]), 'author_name'));
$t->check('and count towards the rating', 2, (int) $value("SELECT `rating_count` FROM `{$seedDb}`.`products` WHERE `id` = ?", 'i', [$oudId]));

// -----------------------------------------------------------------------------
$t->section('The test fixtures still seed from their own folder');
$t->ok('the clothing catalogue the suites use lives in tests/fixtures/seeds', is_file(__DIR__ . '/fixtures/seeds/001_demo_seed_data.sql') && is_file(__DIR__ . '/fixtures/seeds/002_catalog_products.sql') && (glob(BASE_PATH . '/seeds/[0-9]*.sql') ?: []) === []);
$t->ok('and this run was seeded from it', (int) $t->scalar("SELECT COUNT(*) FROM `products` WHERE `slug` = 'gradient-graphic-t-shirt'") === 1 || (int) $t->scalar('SELECT COUNT(*) FROM `products`') > 10);

exit($t->finish());
