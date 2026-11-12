<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Repositories\ProductRepository;
use App\Storage\ObjectStorage;
use App\Storage\Storage;
use App\Storage\StorageKey;
use App\Support\Input;
use App\Support\ProductRatings;
use App\Validators\ProductValidator;
use InvalidArgumentException;

/**
 * Loads a shop's demo catalogue from ONE file (backend/seeds/demo/catalog.json; the shape is
 * shown in catalog.example.json beside it): store settings, delivery zones, a promotion code,
 * categories, banners, products with their variants and pictures, and reviews.
 *
 * It only ever adds. A database that already has products is left alone, and the whole file is
 * checked first (every product through the same rules as the admin's product form, every picture
 * for being on disk), so a mistake in the file stops the run before anything is written.
 *
 * Pictures named in the file live in the "pictures" folder beside it and are copied into the
 * shop's picture storage as catalog/<file name>, which the shop serves at /uploads/catalog/<file name>.
 * A value that is already an address ("/images/hero.webp", "https://...") is used as it is.
 */
final class CatalogSeeder
{
    /** The store settings a catalogue file may set. Anything else (sign-in, dev mode...) is refused. */
    private const SETTINGS = [
        'store_name', 'store_contact_email', 'store_contact_phone', 'store_whatsapp_phone',
        'store_origin_address', 'store_origin_city', 'store_origin_state',
        'default_product_rating', 'storefront_brands',
    ];

    private const PICTURE_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif',
    ];

    private const FLASH_DEAL_DAYS = 7;

    /** @var list<string> What is wrong with the file, in the order it was found. */
    private array $problems = [];
    /** @var array<string, string> picture file name => full path on disk */
    private array $pictures = [];
    /** @var list<string> pictures named in the file that are not on disk */
    private array $missingPictures = [];
    private string $pictureDir = '';

    public function __construct(private Database $db, private ?ObjectStorage $storage = null)
    {
    }

    /**
     * Load the catalogue file into the database.
     *
     * @return array{counts: array<string, int>, notes: list<string>}
     * @throws InvalidArgumentException with a message for the person running the seeder
     */
    public function seed(string $catalogFile): array
    {
        if (!is_file($catalogFile) || !is_readable($catalogFile)) {
            throw new InvalidArgumentException("The catalogue file {$catalogFile} cannot be read.");
        }
        $catalog = json_decode((string) file_get_contents($catalogFile), true);
        if (!is_array($catalog) || ($catalog !== [] && array_is_list($catalog))) {
            throw new InvalidArgumentException("{$catalogFile} is not valid JSON (" . (json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'it must be one object') . ').');
        }

        $existing = (int) ($this->db->fetchOne("SELECT COUNT(*) AS c FROM `products`")['c'] ?? 0);
        if ($existing > 0) {
            throw new InvalidArgumentException(
                "This database already has {$existing} " . ($existing === 1 ? 'product' : 'products') . '. The demo catalogue is only loaded into a shop with no products, '
                . 'so nothing was changed. To start again, use a new empty database.'
            );
        }

        $this->problems = [];
        $this->pictures = [];
        $this->missingPictures = [];
        $this->pictureDir = dirname($catalogFile) . '/pictures';

        $settings = $this->settings($catalog['settings'] ?? []);
        $zones = $this->zones($catalog['zones'] ?? []);
        $promotions = $this->promotions($catalog['promotions'] ?? []);
        $categories = $this->categories($catalog['categories'] ?? []);
        $banners = $this->banners($catalog['banners'] ?? []);
        $products = $this->products($catalog['products'] ?? [], array_column($categories, 'slug'));
        $reviews = $this->reviews($catalog['reviews'] ?? [], array_column(array_column($products, 'data'), 'slug'));

        if ($this->missingPictures !== []) {
            $this->problems[] = 'Missing pictures (put them in ' . $this->pictureDir . '): ' . implode(', ', array_unique($this->missingPictures)) . '.';
        }
        if ($this->problems !== []) {
            throw new InvalidArgumentException("The catalogue file has problems, so nothing was written:\n  - " . implode("\n  - ", $this->problems));
        }

        // Pictures first: a product row must never point at a picture that failed to copy.
        $storage = $this->pictures !== [] ? ($this->storage ?? Storage::uploads()) : null;
        foreach ($this->pictures as $name => $path) {
            $key = "catalog/{$name}";
            $storage->put($key, $path, self::PICTURE_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))], StorageKey::cacheControl($key));
        }

        $notes = [];
        $counts = $this->db->transaction(function (Database $db) use ($settings, $zones, $promotions, $categories, $banners, $products, $reviews, &$notes): array {
            return $this->write($db, $settings, $zones, $promotions, $categories, $banners, $products, $reviews, $notes);
        });
        $counts['pictures'] = count($this->pictures);

        // Shown ratings: each product's starting rating plus its approved reviews.
        ProductRatings::refresh($this->db);

        return ['counts' => $counts, 'notes' => $notes];
    }

    // -------------------------------------------------------------------------
    // Checking the file
    // -------------------------------------------------------------------------

    /** @return array<string, string> */
    private function settings(mixed $settings): array
    {
        $out = [];
        foreach ($this->object($settings, 'settings') as $key => $value) {
            if (!in_array($key, self::SETTINGS, true)) {
                $this->problems[] = "settings: \"{$key}\" is not a setting this file can set (allowed: " . implode(', ', self::SETTINGS) . ').';
            } elseif (is_array($value)) {
                $this->problems[] = "settings: \"{$key}\" must be text.";
            } elseif ($key === ProductRatings::SETTING && ProductRatings::parse($value) === false) {
                $this->problems[] = 'settings: "default_product_rating" must be from 1 to 5 (for example 4.5), or empty.';
            } elseif ($key === 'store_name' && Input::text($value) === '') {
                $this->problems[] = 'settings: "store_name" cannot be empty.';
            } else {
                $out[$key] = Input::text($value);
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function zones(mixed $zones): array
    {
        $out = [];
        $codes = [];
        $covered = [];
        foreach ($this->rows($zones, 'zones') as $i => $zone) {
            $at = 'zones, row ' . ($i + 1);
            $name = Input::text($zone['name'] ?? null);
            if ($name === '' || mb_strlen($name) > 100) {
                $this->problems[] = "{$at}: a name of at most 100 characters is needed.";
                continue;
            }
            $at = "zone \"{$name}\"";
            $states = [];
            foreach (is_array($zone['states'] ?? null) ? $zone['states'] : [] as $state) {
                $state = Input::text($state);
                $canonical = $state === '*' ? '*' : CheckoutService::canonicalState($state);
                if ($canonical === null) {
                    $this->problems[] = "{$at}: \"{$state}\" is not a Nigerian state (use \"*\" for all other states).";
                } elseif (isset($covered[$canonical])) {
                    // Checkout charges the first zone that covers a state, so a second one would never be used.
                    $this->problems[] = "{$at}: " . ($canonical === '*' ? 'all other states are' : "{$canonical} is") . " already covered by zone \"{$covered[$canonical]}\".";
                } else {
                    $covered[$canonical] = $name;
                    $states[] = $canonical;
                }
            }
            if (!is_array($zone['states'] ?? null) || $zone['states'] === []) {
                $this->problems[] = "{$at}: \"states\" must list at least one state.";
            }
            $money = [];
            foreach (['fee' => null, 'extra_item_fee' => 0, 'heavy_item_fee' => 0] as $field => $default) {
                $value = $zone[$field] ?? $default;
                if (!is_numeric($value) || (float) $value < 0 || (float) $value > 1000000) {
                    $this->problems[] = "{$at}: \"{$field}\" must be an amount from 0 to 1,000,000.";
                }
                $money[$field] = round((float) $value, 2);
            }
            $included = Input::wholeNumber($zone['items_included'] ?? 1, 1, 1000);
            if ($included === null) {
                $this->problems[] = "{$at}: \"items_included\" must be a whole number, 1 or more.";
            }
            $days = Input::text($zone['estimated_days'] ?? null, '2-4 business days');
            if ($days === '' || mb_strlen($days) > 50) {
                $this->problems[] = "{$at}: \"estimated_days\" must be 1 to 50 characters.";
            }
            // A code like the ones typed in admin ("ZONE-LAGOS"), made from the name and used once.
            $stem = 'ZONE-' . strtoupper(substr(Input::slugFromName($name, 'zone', static fn (string $slug): bool => false), 0, 20));
            for ($code = $stem, $copy = 2; isset($codes[$code]); $copy++) {
                $code = "{$stem}-{$copy}";
            }
            $codes[$code] = true;
            $out[] = [
                'name' => $name,
                'code' => $code,
                'description' => Input::text($zone['description'] ?? null),
                'states' => $states,
                'items_included' => $included ?? 1,
                'estimated_days' => $days,
            ] + $money;
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function promotions(mixed $promotions): array
    {
        $out = [];
        $codes = [];
        foreach ($this->rows($promotions, 'promotions') as $i => $promo) {
            $code = strtoupper(Input::text($promo['code'] ?? null));
            $at = $code !== '' ? "promotion {$code}" : 'promotions, row ' . ($i + 1);
            if (!preg_match('/^[A-Z0-9_-]{3,30}$/', $code) || isset($codes[$code])) {
                $this->problems[] = "{$at}: the code must be 3 to 30 letters, numbers, dashes or underscores, and used once.";
                continue;
            }
            $codes[$code] = true;
            $type = match (strtolower(Input::text($promo['type'] ?? null, 'percentage'))) {
                'percentage', 'percent' => 'percent',
                'fixed', 'amount' => 'fixed',
                default => null,
            };
            $value = $promo['value'] ?? null;
            $minOrder = is_numeric($promo['min_order'] ?? null) && (float) $promo['min_order'] > 0 ? round((float) $promo['min_order'], 2) : null;
            $maxDiscount = is_numeric($promo['max_discount'] ?? null) && (float) $promo['max_discount'] > 0 ? round((float) $promo['max_discount'], 2) : null;
            $maxUses = Input::wholeNumber($promo['max_uses'] ?? 0, 0, Input::MAX_PROMO_USES);
            $description = Input::text($promo['description'] ?? null);
            if ($type === null) {
                $this->problems[] = "{$at}: \"type\" must be \"percentage\" or \"fixed\".";
            } elseif ($type === 'percent' && Input::wholeNumber($value, 1, 90) === null) {
                $this->problems[] = "{$at}: a percentage must be a whole number from 1 to 90.";
            } elseif ($type === 'fixed' && (!is_numeric($value) || (float) $value <= 0 || (float) $value > Input::MAX_MONEY)) {
                $this->problems[] = "{$at}: a fixed amount must be above 0.";
            } elseif ($type === 'fixed' && ($minOrder === null || (float) $value >= $minOrder)) {
                // As in admin: an order must never be brought down to nothing.
                $this->problems[] = "{$at}: a fixed amount needs a \"min_order\" above the amount off.";
            }
            if ($maxUses === null) {
                $this->problems[] = "{$at}: \"max_uses\" must be a whole number (0 means no limit).";
            }
            if (mb_strlen($description) > 255) {
                $this->problems[] = "{$at}: keep the description to 255 characters.";
            }
            $out[] = [
                'code' => $code,
                'description' => $description,
                'type' => $type ?? 'percent',
                'percent' => $type === 'percent' ? (int) $value : 0,
                'amount' => $type === 'fixed' ? round((float) $value, 2) : null,
                'min_order' => $minOrder,
                'max_discount' => $type === 'percent' ? $maxDiscount : null,
                'max_uses' => $maxUses ?? 0,
                'show_on_homepage' => !empty($promo['show_on_homepage']) ? 1 : 0,
            ];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function categories(mixed $categories): array
    {
        $out = [];
        $slugs = [];
        foreach ($this->rows($categories, 'categories') as $i => $category) {
            $name = Input::text($category['name'] ?? null);
            if ($name === '' || mb_strlen($name) > 100) {
                $this->problems[] = 'categories, row ' . ($i + 1) . ': a name of at most 100 characters is needed.';
                continue;
            }
            $slug = Input::text($category['slug'] ?? null);
            $slug = $slug !== '' ? $slug : Input::slugFromName($name, 'category', static fn (string $s): bool => isset($slugs[$s]));
            if (!preg_match('/^[a-z0-9-]{1,100}$/', $slug) || isset($slugs[$slug])) {
                $this->problems[] = "category \"{$name}\": the slug \"{$slug}\" must be lowercase letters, numbers and hyphens, and used once.";
                continue;
            }
            $slugs[$slug] = true;
            $out[] = [
                'name' => $name,
                'slug' => $slug,
                'description' => Input::text($category['description'] ?? null),
                'image_url' => $this->picture($category['image'] ?? null, "category \"{$name}\""),
            ];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function banners(mixed $banners): array
    {
        $out = [];
        foreach ($this->rows($banners, 'banners') as $i => $banner) {
            $title = Input::text($banner['title'] ?? null);
            $at = $title !== '' ? "banner \"{$title}\"" : 'banners, row ' . ($i + 1);
            $row = [
                'title' => $title,
                'subtitle' => Input::text($banner['subtitle'] ?? null),
                'cta_text' => Input::text($banner['cta_text'] ?? null),
                'cta_url' => Input::text($banner['cta_url'] ?? null),
                'image_url' => $this->picture($banner['image'] ?? null, $at),
            ];
            if ($title === '') {
                $this->problems[] = "{$at}: a title is needed.";
            }
            if ($row['image_url'] === null) {
                $this->problems[] = "{$at}: an image is needed.";
            }
            if ($row['cta_url'] !== '' && !preg_match('#^(/(?!/)|https://)#', $row['cta_url'])) {
                $this->problems[] = "{$at}: \"cta_url\" must be a store path like /category or an https:// address.";
            }
            foreach (['title' => 255, 'subtitle' => 255, 'cta_text' => 50, 'cta_url' => 255] as $field => $max) {
                if (mb_strlen($row[$field]) > $max) {
                    $this->problems[] = "{$at}: keep \"{$field}\" to {$max} characters.";
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param list<string> $categorySlugs the categories this file creates
     * @return list<array{data: array<string, mixed>, variants: list<array<string, mixed>>, images: list<array<string, mixed>>, units_sold: int}>
     */
    private function products(mixed $products, array $categorySlugs): array
    {
        $out = [];
        $slugs = [];
        $skus = [];
        foreach ($this->rows($products, 'products') as $i => $product) {
            $name = Input::singleSpaced($product['name'] ?? null);
            if ($name === '') {
                $this->problems[] = 'products, row ' . ($i + 1) . ': a name is needed.';
                continue;
            }
            $at = "product \"{$name}\"";
            $slug = Input::text($product['slug'] ?? null);
            $slug = $slug !== '' ? $slug : Input::slugFromName($name, 'product', static fn (string $s): bool => isset($slugs[$s]));
            if (isset($slugs[$slug])) {
                $this->problems[] = "{$at}: the slug \"{$slug}\" is used by another product in the file.";
                continue;
            }
            $slugs[$slug] = true;

            $category = Input::text($product['category'] ?? null);
            if ($category !== '' && !in_array($category, $categorySlugs, true)
                && $this->db->fetchOne("SELECT `id` FROM `categories` WHERE `slug` = ?", 's', [$category]) === null) {
                $this->problems[] = "{$at}: the category \"{$category}\" is not in the file's categories.";
            }

            $flash = !empty($product['flash_deal']);
            $data = [
                'name' => $name,
                'slug' => $slug,
                'category_slug' => $category,
                'description' => Input::text($product['description'] ?? null),
                'base_price' => $product['price'] ?? null,
                'original_price' => $product['original_price'] ?? null,
                'dress_style' => Input::text($product['style'] ?? null),
                'status' => 'published',
                'is_featured' => !empty($product['featured']) ? 1 : 0,
                'is_flash_deal' => $flash ? 1 : 0,
                'flash_deal_ends_at' => $flash ? date('Y-m-d H:i:s', time() + self::FLASH_DEAL_DAYS * 86400) : null,
                'rating_start' => $product['rating_start'] ?? null,
                'specifications' => $product['specifications'] ?? [],
                'warranty' => $product['warranty'] ?? null,
                'is_heavy' => !empty($product['heavy']),
            ];
            // SKUs the way the admin makes them: from the category and the name, then one per variant.
            do {
                $data['sku'] = ProductRepository::generateSku($data);
            } while (isset($skus[$data['sku']]));
            $skus[$data['sku']] = true;

            $variants = [];
            foreach ($this->rows($product['variants'] ?? [], "{$at}, variants") as $n => $variant) {
                $row = [
                    'size' => Input::text($variant['option1'] ?? null),
                    'color_name' => Input::text($variant['option2'] ?? null),
                    'color_hex' => Input::text($variant['hex'] ?? null),
                    'price_override' => $variant['price'] ?? null,
                    'stock_quantity' => $variant['stock'] ?? 0,
                ];
                $options = ProductRepository::variantOptions($row);
                $sku = ProductRepository::generateVariantSku($data['sku'], $options['color_name'], $options['size'], $n + 1);
                // "Eau de Parfum" and "Eau de Toilette" shorten to the same letters: keep each SKU its own.
                for ($base = $sku, $copy = 2; isset($skus[$sku]); $copy++) {
                    $sku = "{$base}-{$copy}";
                }
                $skus[$sku] = true;
                $variants[] = $row + ['sku' => $sku];
            }

            $images = [];
            foreach (is_array($product['images'] ?? null) ? array_values($product['images']) : [] as $n => $image) {
                $url = $this->picture($image, $at);
                if ($url !== null) {
                    $images[] = ['image_url' => $url, 'alt_text' => $name, 'is_primary' => $n === 0 ? 1 : 0, 'display_order' => $n];
                }
            }

            // The same rules as the admin's product form (lengths, prices, variants, specifications).
            foreach (ProductValidator::validate($data + ['variants' => $variants, 'images' => $images]) as $field => $message) {
                $this->problems[] = "{$at}: {$field}: {$message}";
            }
            $unitsSold = Input::wholeNumber($product['units_sold'] ?? 0, 0, 100000000);
            if ($unitsSold === null) {
                $this->problems[] = "{$at}: \"units_sold\" must be a whole number, 0 or more.";
            }

            $out[] = ['data' => $data, 'variants' => $variants, 'images' => $images, 'units_sold' => $unitsSold ?? 0];
        }
        return $out;
    }

    /**
     * @param list<string> $productSlugs the products this file creates
     * @return list<array<string, mixed>>
     */
    private function reviews(mixed $reviews, array $productSlugs): array
    {
        $out = [];
        foreach ($this->rows($reviews, 'reviews') as $i => $review) {
            $at = 'reviews, row ' . ($i + 1);
            $row = [
                'product' => Input::text($review['product'] ?? null),
                'name' => Input::text($review['name'] ?? null),
                'rating' => Input::wholeNumber($review['rating'] ?? null, 1, 5),
                'title' => Input::text($review['title'] ?? null),
                'body' => Input::text($review['body'] ?? null),
            ];
            if (!in_array($row['product'], $productSlugs, true)) {
                $this->problems[] = "{$at}: \"{$row['product']}\" is not the slug of a product in the file.";
            }
            if ($row['name'] === '' || mb_strlen($row['name']) > 150) {
                $this->problems[] = "{$at}: a name of at most 150 characters is needed.";
            }
            if ($row['rating'] === null) {
                $this->problems[] = "{$at}: the rating must be a whole number from 1 to 5.";
            }
            if ($row['body'] === '' || mb_strlen($row['title']) > 255) {
                $this->problems[] = "{$at}: the review needs its text in \"body\" (and a title of at most 255 characters).";
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The address a picture named in the file will have, or null when none is given. A file name
     * is looked for in the pictures folder (and remembered for copying); an address is kept as it is.
     */
    private function picture(mixed $value, string $at): ?string
    {
        $value = Input::text($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('#^(/(?!/)|https://)#', $value)) {
            return $value;
        }
        $extension = strtolower(pathinfo($value, PATHINFO_EXTENSION));
        if (str_contains($value, '/') || str_contains($value, '\\') || !StorageKey::isValid("catalog/{$value}") || !isset(self::PICTURE_TYPES[$extension])) {
            $this->problems[] = "{$at}: \"{$value}\" is not a picture file name (letters, numbers, dots, dashes and underscores, ending in " . implode(', ', array_keys(self::PICTURE_TYPES)) . ').';
            return null;
        }
        $path = $this->pictureDir . '/' . $value;
        if (!is_file($path)) {
            $this->missingPictures[] = $value;
            return null;
        }
        $this->pictures[$value] = $path;
        return "/uploads/catalog/{$value}";
    }

    /** A list of rows from the file ([] when the key is missing); anything else is a problem. */
    private function rows(mixed $value, string $at): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            $this->problems[] = "{$at}: this must be a list.";
            return [];
        }
        $rows = [];
        foreach ($value as $i => $row) {
            if (!is_array($row)) {
                $this->problems[] = "{$at}, row " . ($i + 1) . ': this must be an object.';
                continue;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function object(mixed $value, string $at): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (!is_array($value) || array_is_list($value)) {
            $this->problems[] = "{$at}: this must be an object of names and values.";
            return [];
        }
        return $value;
    }

    // -------------------------------------------------------------------------
    // Writing (inside one transaction: all of it, or none)
    // -------------------------------------------------------------------------

    /**
     * @param list<string> $notes
     * @return array<string, int>
     */
    private function write(Database $db, array $settings, array $zones, array $promotions, array $categories, array $banners, array $products, array $reviews, array &$notes): array
    {
        $counts = ['settings' => 0, 'zones' => 0, 'promotions' => 0, 'categories' => 0, 'banners' => 0, 'products' => 0, 'variants' => 0, 'reviews' => 0];

        foreach ($settings as $key => $value) {
            $db->execute(
                "INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                'ss',
                [$key, $value]
            );
            $counts['settings']++;
        }

        // Zones an owner has already set up decide the delivery fees: the file's are not added on top.
        if ($zones !== [] && (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM `shipping_zones`")['c'] ?? 0) > 0) {
            $notes[] = 'Delivery zones were left as they are: this shop already has some.';
            $zones = [];
        }
        foreach ($zones as $zone) {
            $db->insert(
                "INSERT INTO `shipping_zones` (`name`, `code`, `description`, `states_covered`, `fee`, `items_included`, `extra_item_fee`, `heavy_item_fee`, `estimated_days`, `is_active`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                'ssssdidds',
                [$zone['name'], $zone['code'], $zone['description'], json_encode($zone['states']), $zone['fee'], $zone['items_included'], $zone['extra_item_fee'], $zone['heavy_item_fee'], $zone['estimated_days']]
            );
            $counts['zones']++;
        }

        foreach ($promotions as $promo) {
            if ($db->fetchOne("SELECT `id` FROM `promotions` WHERE `code` = ?", 's', [$promo['code']]) !== null) {
                $notes[] = "Promotion {$promo['code']} was left as it is: the code already exists.";
                continue;
            }
            $db->insert(
                "INSERT INTO `promotions` (`code`, `description`, `discount_type`, `discount_percent`, `discount_amount`, `min_order_amount`, `max_discount_amount`, `max_uses`, `is_active`, `show_on_homepage`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)",
                'sssidddii',
                [$promo['code'], $promo['description'], $promo['type'], $promo['percent'], $promo['amount'], $promo['min_order'], $promo['max_discount'], $promo['max_uses'], $promo['show_on_homepage']]
            );
            $counts['promotions']++;
        }

        $categoryIds = [];
        $order = (int) ($db->fetchOne("SELECT COALESCE(MAX(`display_order`), 0) AS m FROM `categories`")['m'] ?? 0);
        foreach ($categories as $category) {
            $found = $db->fetchOne("SELECT `id` FROM `categories` WHERE `slug` = ?", 's', [$category['slug']]);
            if ($found !== null) {
                $categoryIds[$category['slug']] = (int) $found['id'];
                $notes[] = "Category {$category['slug']} was left as it is: it already exists.";
                continue;
            }
            $categoryIds[$category['slug']] = (int) $db->insert(
                "INSERT INTO `categories` (`name`, `slug`, `description`, `image_url`, `display_order`, `is_active`) VALUES (?, ?, ?, ?, ?, 1)",
                'ssssi',
                [$category['name'], $category['slug'], $category['description'] !== '' ? $category['description'] : null, $category['image_url'], ++$order]
            );
            $counts['categories']++;
        }

        $order = (int) ($db->fetchOne("SELECT COALESCE(MAX(`display_order`), 0) AS m FROM `banners`")['m'] ?? 0);
        foreach ($banners as $banner) {
            $db->insert(
                "INSERT INTO `banners` (`title`, `subtitle`, `cta_text`, `cta_url`, `image_url`, `is_active`, `display_order`) VALUES (?, ?, ?, ?, ?, 1, ?)",
                'sssssi',
                [$banner['title'], $banner['subtitle'], $banner['cta_text'], $banner['cta_url'], $banner['image_url'], ++$order]
            );
            $counts['banners']++;
        }

        $repository = new ProductRepository();
        $productIds = [];
        foreach ($products as $product) {
            $data = $product['data'];
            $slug = (string) $data['category_slug'];
            if ($slug !== '' && !isset($categoryIds[$slug])) {
                $categoryIds[$slug] = (int) ($db->fetchOne("SELECT `id` FROM `categories` WHERE `slug` = ?", 's', [$slug])['id'] ?? 0);
            }
            $data['category_id'] = $slug !== '' ? $categoryIds[$slug] : null;
            $data['original_price'] = is_numeric($data['original_price']) && (float) $data['original_price'] > 0 ? (float) $data['original_price'] : null;
            $data['base_price'] = (float) $data['base_price'];

            $id = $repository->create($data, $product['variants'], $product['images']);
            if ($product['units_sold'] > 0) {
                $db->execute("UPDATE `products` SET `units_sold` = ? WHERE `id` = ?", 'ii', [$product['units_sold'], $id]);
            }
            $productIds[$data['slug']] = $id;
            $counts['products']++;
            $counts['variants'] += max(1, count($product['variants']));
        }

        // Stored the way an approved review is: shown on the product's page and on the home page.
        // Dated a day apart, so they keep the file's order: its first review is the newest one shown.
        foreach ($reviews as $i => $review) {
            $db->insert(
                "INSERT INTO `reviews` (`product_id`, `user_id`, `author_name`, `author_email`, `rating`, `title`, `comment`, `status`, `is_verified_purchase`, `created_at`)
                 VALUES (?, NULL, ?, NULL, ?, ?, ?, 'approved', 0, DATE_SUB(NOW(), INTERVAL ? DAY))",
                'isissi',
                [$productIds[$review['product']], $review['name'], $review['rating'], $review['title'] !== '' ? $review['title'] : null, $review['body'], $i + 1]
            );
            $counts['reviews']++;
        }

        return $counts;
    }
}
