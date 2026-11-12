<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Shop;
use App\Support\Input;
use App\Support\ProductRatings;
use App\Database\Database;

class ProductRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * List published products with dynamic filtering, sorting, and pagination.
     *
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function listPublished(array $filters = [], int $page = 1, int $perPage = 12): array
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);
        $offset = ($page - 1) * $perPage;

        $conditions = ["p.status = 'published'"];
        $params = [];
        $types = "";

        // Category filter (by ID or Slug)
        if (!empty($filters['category'])) {
            if (is_numeric($filters['category'])) {
                $conditions[] = "p.category_id = ?";
                $params[] = (int) $filters['category'];
                $types .= "i";
            } else {
                $conditions[] = "c.slug = ?";
                $params[] = (string) $filters['category'];
                $types .= "s";
            }
        }

        // Specific products (e.g. a customer's saved wishlist)
        if (!empty($filters['ids']) && is_array($filters['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['ids']), static fn (int $v): bool => $v > 0));
            if (empty($ids)) {
                $ids = [0];
            }
            $conditions[] = "p.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
            foreach ($ids as $pid) {
                $params[] = $pid;
                $types .= "i";
            }
        }

        // Search: whole words (and their beginnings, "shirt" finds "shirts") in the name, dress style and
        // description through the full-text index, so it stays fast with a large catalogue.
        // Very short searches ("ts") fall back to matching the name.
        if (!empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $words = self::searchWords($term);
            if ($words !== []) {
                $conditions[] = "MATCH(p.name, p.description, p.dress_style) AGAINST (? IN BOOLEAN MODE)";
                $params[] = implode(' ', array_map(static fn (string $w): string => '+' . $w . '*', $words));
                $types .= "s";
            } else {
                $conditions[] = "p.name LIKE ?";
                $params[] = '%' . addcslashes($term, '%_\\') . '%';
                $types .= "s";
            }
        }

        // Price range
        if (isset($filters['min_price']) && is_numeric($filters['min_price'])) {
            $conditions[] = "p.base_price >= ?";
            $params[] = (float) $filters['min_price'];
            $types .= "d";
        }
        if (isset($filters['max_price']) && is_numeric($filters['max_price'])) {
            $conditions[] = "p.base_price <= ?";
            $params[] = (float) $filters['max_price'];
            $types .= "d";
        }

        // Dress style filter (the column's collation already ignores case, so the index is used)
        if (!empty($filters['dress_style'])) {
            $conditions[] = "p.dress_style = ?";
            $params[] = (string) $filters['dress_style'];
            $types .= "s";
        }

        // Color filter (matches color names or keyword families via active variants)
        if (!empty($filters['colors']) || !empty($filters['color'])) {
            $rawColors = !empty($filters['colors']) 
                ? (is_array($filters['colors']) ? $filters['colors'] : explode(',', (string) $filters['colors'])) 
                : [Input::text($filters['color'])];
            
            $colorFamilies = [
                'black' => ['black', 'onyx', 'pitch', 'midnight', 'charcoal', 'dark', 'ebony', 'gray', 'grey'],
                'white' => ['white', 'clean', 'sand', 'ivory', 'cream', 'off-white'],
                'blue' => ['blue', 'navy', 'indigo', 'ocean', 'cobalt', 'royal', 'denim'],
                'red' => ['red', 'crimson', 'maroon', 'burgundy', 'ruby'],
                'green' => ['green', 'olive', 'sage', 'emerald', 'mint'],
                'yellow' => ['yellow', 'mustard', 'gold', 'amber', 'khaki', 'tan'],
                'orange' => ['orange', 'rust', 'peach', 'coral'],
                'cyan' => ['cyan', 'teal', 'aqua', 'turquoise', 'multi-color'],
                'purple' => ['purple', 'violet', 'lavender', 'plum'],
                'pink' => ['pink', 'rose', 'blush', 'fuchsia'],
            ];

            // The families only make sense for colours. A shop whose second option is a scent, a
            // flavour or a type filters on the exact name instead ("rose" must not find "Rosewood").
            $byFamily = Shop::option2IsColour();

            $colorConditions = [];
            foreach ($rawColors as $col) {
                $trimmed = mb_strtolower(Input::text($col));
                if ($trimmed !== '' && !$byFamily) {
                    $colorConditions[] = "LOWER(pv_c.color_name) = ?";
                    $params[] = $trimmed;
                    $types .= "s";
                } elseif ($trimmed !== '') {
                    $familyWords = $colorFamilies[$trimmed] ?? [$trimmed];
                    $subConditions = [];
                    foreach ($familyWords as $word) {
                        $subConditions[] = "LOWER(pv_c.color_name) LIKE ?";
                        $params[] = '%' . $word . '%';
                        $types .= "s";
                    }
                    if (!empty($subConditions)) {
                        $colorConditions[] = '(' . implode(' OR ', $subConditions) . ')';
                    }
                }
            }
            if (!empty($colorConditions)) {
                $colorSql = implode(' OR ', $colorConditions);
                $conditions[] = "EXISTS (SELECT 1 FROM product_variants pv_c WHERE pv_c.product_id = p.id AND pv_c.is_active = 1 AND ({$colorSql}))";
            }
        }

        // Size filter (matches variant sizes)
        if (!empty($filters['sizes']) || !empty($filters['size'])) {
            $rawSizes = !empty($filters['sizes']) 
                ? (is_array($filters['sizes']) ? $filters['sizes'] : explode(',', (string) $filters['sizes'])) 
                : [Input::text($filters['size'])];

            $sizeConditions = [];
            foreach ($rawSizes as $sz) {
                $trimmed = Input::text($sz);
                if ($trimmed !== '') {
                    $sizeConditions[] = "LOWER(pv_s.size) = LOWER(?)";
                    $params[] = $trimmed;
                    $types .= "s";
                }
            }
            if (!empty($sizeConditions)) {
                $sizeSql = implode(' OR ', $sizeConditions);
                $conditions[] = "EXISTS (SELECT 1 FROM product_variants pv_s WHERE pv_s.product_id = p.id AND pv_s.is_active = 1 AND ({$sizeSql}))";
            }
        }

        // On Sale / Discount filter
        if (!empty($filters['on_sale'])) {
            $conditions[] = "p.original_price IS NOT NULL AND p.original_price > p.base_price";
        }

        // Always enforce physical products
        $conditions[] = "p.product_type = 'physical'";

        // Flash deals or featured
        if (!empty($filters['is_flash_deal'])) {
            // An expired deal is not a flash deal any more.
            $conditions[] = "p.is_flash_deal = 1 AND (p.flash_deal_ends_at IS NULL OR p.flash_deal_ends_at > NOW())";
        }
        if (!empty($filters['is_featured'])) {
            $conditions[] = "p.is_featured = 1";
        }
        // Home page rows: products with real sales only, and none already shown in a row above.
        if (!empty($filters['has_sales'])) {
            $conditions[] = "p.units_sold > 0";
        }
        if (!empty($filters['exclude_ids'])) {
            $excluded = array_map('intval', $filters['exclude_ids']);
            $conditions[] = 'p.id NOT IN (' . implode(',', array_fill(0, count($excluded), '?')) . ')';
            $types .= str_repeat('i', count($excluded));
            array_push($params, ...$excluded);
        }

        $whereSql = implode(' AND ', $conditions);

        // Sorting
        $sortOrder = "p.created_at DESC";
        $sortKey = $filters['sort'] ?? 'newest';
        switch ($sortKey) {
            case 'price_asc':
                $sortOrder = "p.base_price ASC";
                break;
            case 'price_desc':
                $sortOrder = "p.base_price DESC";
                break;
            case 'rating':
            case 'rating_desc':
                $sortOrder = "p.rating_avg DESC, p.rating_count DESC";
                break;
            case 'best_selling':
            case 'popular':
            case 'popularity':
                // Real sales: units on paid orders (refunded/cancelled orders don't count),
                // kept on the product by App\Services\ProductSales.
                $sortOrder = "p.units_sold DESC, p.created_at DESC";
                break;
            case 'newest':
            case 'latest':
            default:
                $sortOrder = "p.created_at DESC";
                break;
        }

        // 1. Total matching count
        $countSql = "SELECT COUNT(DISTINCT p.id) AS total
                     FROM products p
                     LEFT JOIN categories c ON c.id = p.category_id
                     WHERE {$whereSql}";
        $countResult = $this->db->fetchOne($countSql, $types, $params);
        $total = $countResult ? (int) $countResult['total'] : 0;

        // 2. Paginated rows with category & primary image
        $itemsSql = "SELECT p.id, p.uuid, p.name, p.slug, p.description, p.product_type,
                            p.base_price, p.original_price, p.currency, p.sku,
                            p.rating_avg, p.rating_count, p.is_featured, p.is_flash_deal, p.flash_deal_ends_at, p.dress_style,
                            p.created_at,
                            c.id AS category_id, c.name AS category_name, c.slug AS category_slug,
                            (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.display_order ASC LIMIT 1) AS primary_image,
                            (SELECT COALESCE(SUM(pv.stock_quantity), 0) FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_active = 1) AS total_stock
                     FROM products p
                     LEFT JOIN categories c ON c.id = p.category_id
                     WHERE {$whereSql}
                     ORDER BY {$sortOrder}, p.id DESC
                     LIMIT ? OFFSET ?";

        $itemParams = $params;
        $itemParams[] = $perPage;
        $itemParams[] = $offset;
        $itemTypes = $types . "ii";

        $rows = $this->db->fetchAll($itemsSql, $itemTypes, $itemParams);

        // Format and enrich each product row with colors and sizes from variants
        if (!empty($rows)) {
            $productIds = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $variantTypes = str_repeat('i', count($productIds));
            $variantRows = $this->db->fetchAll(
                "SELECT product_id, color_name, color_hex, size
                 FROM product_variants
                 WHERE is_active = 1 AND product_id IN ({$placeholders})
                 ORDER BY id ASC",
                $variantTypes,
                $productIds
            );

            $colorsByProduct = [];
            $sizesByProduct = [];
            foreach ($variantRows as $v) {
                $pid = (int) $v['product_id'];
                if (!isset($colorsByProduct[$pid])) {
                    $colorsByProduct[$pid] = [];
                }
                if (!isset($sizesByProduct[$pid])) {
                    $sizesByProduct[$pid] = [];
                }

                if (!empty($v['color_name'])) {
                    $colorKey = strtolower(Input::text($v['color_name'] ?? null));
                    if (!isset($colorsByProduct[$pid][$colorKey])) {
                        $colorsByProduct[$pid][$colorKey] = [
                            'name' => Input::text($v['color_name'] ?? null),
                            'hex' => !empty($v['color_hex']) ? Input::text($v['color_hex'] ?? null) : '#000000',
                        ];
                    }
                }

                if (!empty($v['size'])) {
                    $sizeTrim = Input::text($v['size'] ?? null);
                    if (!in_array($sizeTrim, $sizesByProduct[$pid], true)) {
                        $sizesByProduct[$pid][] = $sizeTrim;
                    }
                }
            }

            foreach ($rows as &$row) {
                $pid = (int) $row['id'];
                $row['id'] = $pid;
                $row['base_price'] = (float) $row['base_price'];
                $row['original_price'] = $row['original_price'] !== null ? (float) $row['original_price'] : null;
                $row['rating_avg'] = (float) $row['rating_avg'];
                $row['rating_count'] = (int) $row['rating_count'];
                $row['is_featured'] = (bool) $row['is_featured'];
                $row['is_flash_deal'] = (bool) $row['is_flash_deal'];
                $row['total_stock'] = (int) $row['total_stock'];
                $row['in_stock'] = $row['total_stock'] > 0;
                $row['available_colors'] = isset($colorsByProduct[$pid]) ? array_values($colorsByProduct[$pid]) : [];
                $row['available_sizes'] = isset($sizesByProduct[$pid]) ? $sizesByProduct[$pid] : [];
                $row['age_restricted'] = Shop::isAgeRestricted($row['category_slug'] ?? null);

                // Calculate discount percentage if original price is set
                if ($row['original_price'] && $row['original_price'] > $row['base_price']) {
                    $row['discount_percent'] = (int) round((($row['original_price'] - $row['base_price']) / $row['original_price']) * 100);
                } else {
                    $row['discount_percent'] = 0;
                }
            }
            unset($row);
        }

        return [
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /** Words that the full-text index ignores; requiring them would match nothing. */
    private const SEARCH_STOPWORDS = ['a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];

    /**
     * Search words usable with the full-text index: letters/digits, at least 3 characters
     * (the index's shortest word), no stop words, at most 8 words.
     *
     * @return string[]
     */
    private static function searchWords(string $term): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($parts, static fn (string $w): bool => mb_strlen($w) >= 3 && !in_array($w, self::SEARCH_STOPWORDS, true));
        return array_slice(array_values(array_unique($words)), 0, 8);
    }

    /**
     * Find single published product details with images and variants.
     */
    public function findDetail(string $idOrSlug, bool $allowDraft = false): ?array
    {
        $statusCondition = $allowDraft ? "1=1" : "p.status = 'published'";

        if (is_numeric($idOrSlug)) {
            $whereClause = "p.id = ? AND {$statusCondition}";
            $types = "i";
            $params = [(int) $idOrSlug];
        } else {
            $whereClause = "(p.slug = ? OR p.uuid = ?) AND {$statusCondition}";
            $types = "ss";
            $params = [$idOrSlug, $idOrSlug];
        }

        $sql = "SELECT p.*, c.name AS category_name, c.slug AS category_slug
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE {$whereClause}
                LIMIT 1";

        $product = $this->db->fetchOne($sql, $types, $params);
        if (!$product) {
            return null;
        }

        $productId = (int) $product['id'];

        // 1. Fetch images
        $images = $this->db->fetchAll(
            "SELECT id, image_url, alt_text, is_primary, display_order
             FROM product_images
             WHERE product_id = ?
             ORDER BY is_primary DESC, display_order ASC",
            "i",
            [$productId]
        );

        // 2. Fetch variants
        $variants = $this->db->fetchAll(
            "SELECT id, sku, size, color_name, color_hex, price_override, stock_quantity, low_stock_threshold
             FROM product_variants
             WHERE product_id = ? AND is_active = 1
             ORDER BY id ASC",
            "i",
            [$productId]
        );

        foreach ($variants as &$v) {
            $v['id'] = (int) $v['id'];
            $v['price_override'] = ($v['price_override'] !== null && (float) $v['price_override'] > 0) ? (float) $v['price_override'] : null;
            $v['stock_quantity'] = (int) $v['stock_quantity'];
            $v['in_stock'] = $v['stock_quantity'] > 0;
            if (!$allowDraft) {
                unset($v['low_stock_threshold']); // how the store manages stock: admin only
            }
        }
        unset($v);

        // 3. Collect distinct sizes and color options for convenient frontend consumption
        $colors = [];
        $sizes = [];
        foreach ($variants as $v) {
            $colorKey = $v['color_name'] . '|' . $v['color_hex'];
            if (!isset($colors[$colorKey])) {
                $colors[$colorKey] = ['name' => $v['color_name'], 'hex' => $v['color_hex']];
            }
            if (!in_array($v['size'], $sizes, true)) {
                $sizes[] = $v['size'];
            }
        }

        if (!$allowDraft) {
            // Sales figures and how the rating is made up: admin only.
            unset($product['units_sold'], $product['rating_start'], $product['review_avg']);
        } else {
            $product['rating_start'] = $product['rating_start'] !== null ? (float) $product['rating_start'] : null;
            $product['review_avg'] = (float) $product['review_avg'];
        }
        $product['id'] = $productId;
        $product['base_price'] = (float) $product['base_price'];
        $product['original_price'] = $product['original_price'] !== null ? (float) $product['original_price'] : null;
        $product['rating_avg'] = (float) $product['rating_avg'];
        $product['rating_count'] = (int) $product['rating_count'];
        $product['is_featured'] = (bool) $product['is_featured'];
        $product['is_flash_deal'] = (bool) $product['is_flash_deal'];
        $product['specifications'] = self::specificationRows($product['specifications'] ?? null);
        $product['warranty'] = Input::text($product['warranty'] ?? null) !== '' ? Input::text($product['warranty']) : null;
        $product['is_heavy'] = (bool) ($product['is_heavy'] ?? false);
        $product['age_restricted'] = Shop::isAgeRestricted($product['category_slug'] ?? null);
        $product['images'] = $images;
        $product['variants'] = $variants;
        $product['available_colors'] = array_values($colors);
        $product['available_sizes'] = $sizes;

        // Digital deliverable metadata (without revealing storage path)
        return $product;
    }

    /**
     * Get top featured products.
     */
    public function getFeatured(int $limit = 4, array $excludeIds = []): array
    {
        return $this->listPublished(['is_featured' => 1, 'exclude_ids' => $excludeIds], 1, $limit)['items'];
    }

    /**
     * Normalised flash-deal end time from admin input ("YYYY-MM-DDTHH:MM" or "YYYY-MM-DD HH:MM:SS").
     * Missing key keeps the current value; empty clears it; not a flash deal clears it.
     */
    private static function flashEnd(array $data, ?array $existing): ?string
    {
        $isFlash = isset($data['is_flash_deal']) ? (bool) $data['is_flash_deal'] : (bool) ($existing['is_flash_deal'] ?? false);
        if (!$isFlash) {
            return null;
        }
        if (!array_key_exists('flash_deal_ends_at', $data)) {
            return $existing['flash_deal_ends_at'] ?? null;
        }
        $value = trim((string) $data['flash_deal_ends_at']);
        $ts = $value === '' ? false : strtotime(str_replace('T', ' ', $value));
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    /**
     * The product's own starting rating from admin input: a missing key keeps the stored one,
     * empty or null clears it (the store default applies). Validated by ProductValidator.
     */
    private static function ratingStart(array $data, ?array $existing): ?float
    {
        if (!array_key_exists('rating_start', $data)) {
            return isset($existing['rating_start']) ? (float) $existing['rating_start'] : null;
        }
        $parsed = ProductRatings::parse($data['rating_start']);
        return $parsed === false ? null : $parsed;
    }

    /**
     * Get flash deal products with highest discounts.
     */
    public function getFlashDeals(int $limit = 4): array
    {
        return $this->listPublished(['is_flash_deal' => 1], 1, $limit)['items'];
    }

    /**
     * Get latest new arrival products.
     */
    public function getNewArrivals(int $limit = 4, array $excludeIds = []): array
    {
        return $this->listPublished(['sort' => 'newest', 'exclude_ids' => $excludeIds], 1, $limit)['items'];
    }

    /**
     * Get top selling / popular products.
     */
    public function getTopSelling(int $limit = 4, array $excludeIds = []): array
    {
        return $this->listPublished(['sort' => 'best_selling', 'has_sales' => 1, 'exclude_ids' => $excludeIds], 1, $limit)['items'];
    }

    /** Sizes written out in full, and the short form they sort with in the filter. */
    private const LONG_SIZE_NAMES = [
        'xx-small' => 'XXS', 'x-small' => 'XS', 'small' => 'S', 'medium' => 'M', 'large' => 'L',
        'x-large' => 'XL', 'xx-large' => 'XXL', '3x-large' => '3XL', '4x-large' => '4XL',
    ];

    /**
     * The two options of a variant as they are stored. One left empty (or missing) means the
     * product does not use it, which is stored as the shop's "none" word (config/shop.json) so the
     * columns stay NOT NULL and the "one variant per pair" rule keeps working.
     *
     * @param array<string, mixed> $variant
     * @return array{size: string, color_name: string, color_hex: string}
     */
    public static function variantOptions(array $variant): array
    {
        $size = Input::text($variant['size'] ?? null);
        $colour = Input::text($variant['color_name'] ?? null);
        return [
            'size' => $size !== '' ? $size : Shop::option1None(),
            'color_name' => $colour !== '' ? $colour : Shop::option2None(),
            'color_hex' => Input::text($variant['color_hex'] ?? null) ?: '#000000',
        ];
    }

    /**
     * A product's specification rows ("Material: Cotton") from a form or from the stored JSON:
     * always a list of {name, value}, without the rows that lack a name or a value.
     *
     * @return list<array{name: string, value: string}>
     */
    public static function specificationRows(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        $rows = [];
        foreach (is_array($value) ? $value : [] as $row) {
            $name = is_array($row) ? Input::text($row['name'] ?? null) : '';
            $text = is_array($row) ? Input::text($row['value'] ?? null) : '';
            if ($name !== '' && $text !== '') {
                $rows[] = ['name' => $name, 'value' => $text];
            }
        }
        return $rows;
    }

    /** What goes in products.warranty: the text, or NULL when there is none. */
    private static function warranty(mixed $value): ?string
    {
        $text = Input::text($value);
        return $text !== '' ? mb_substr($text, 0, 160) : null;
    }

    /** A yes/no from a form (true, 1, "1") as the 1 or 0 the column holds. */
    private static function flag(mixed $value): int
    {
        return in_array($value, [true, 1, '1'], true) ? 1 : 0;
    }

    /** What goes in products.specifications: the rows as JSON, or NULL when there are none. */
    private static function specificationsJson(mixed $value): ?string
    {
        $rows = self::specificationRows($value);
        return $rows === [] ? null : json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Filter options that actually exist in the published catalogue, or in one category of it
     * (so a category page only offers the colours, sizes, styles and prices it really has).
     *
     * @return array{colors: array, sizes: string[], dress_styles: string[], price: array{min: float, max: float}}
     */
    public function facets(?string $categorySlug = null): array
    {
        $published = "p.status = 'published' AND p.product_type = 'physical'";
        $types = '';
        $params = [];
        if ($categorySlug !== null && $categorySlug !== '') {
            $published .= ' AND p.category_id = (SELECT c.id FROM categories c WHERE c.slug = ? LIMIT 1)';
            $types = 's';
            $params = [$categorySlug];
        }

        $colors = $this->db->fetchAll(
            "SELECT pv.color_name AS name, MIN(pv.color_hex) AS hex, COUNT(DISTINCT p.id) AS product_count
             FROM product_variants pv
             JOIN products p ON p.id = pv.product_id
             WHERE {$published} AND pv.is_active = 1
             GROUP BY pv.color_name
             ORDER BY product_count DESC, pv.color_name ASC",
            $types,
            $params
        );
        foreach ($colors as &$c) {
            $c['product_count'] = (int) $c['product_count'];
        }
        unset($c);
        // "Default" (or whatever the shop's "none" word is) means the product has no such option:
        // it is not something to filter by.
        $colors = array_values(array_filter($colors, static fn (array $c): bool => Shop::usesOption2((string) $c['name'])));

        $sizeRows = $this->db->fetchAll(
            "SELECT DISTINCT pv.size AS size
             FROM product_variants pv
             JOIN products p ON p.id = pv.product_id
             WHERE {$published} AND pv.is_active = 1",
            $types,
            $params
        );
        // The shop's own choices come first, in the order config/shop.json lists them; anything
        // else follows, sorted naturally ("50ml" before "100ml"). The "none" word is left out.
        $order = array_map('mb_strtolower', Shop::option1Choices());
        $rank = static function (string $size) use ($order): int|false {
            $key = mb_strtolower(trim($size));
            $at = array_search($key, $order, true);
            // Clothing sizes written out in full ("Medium") sit with their short form ("M").
            return $at !== false ? $at : array_search(mb_strtolower(self::LONG_SIZE_NAMES[$key] ?? ''), $order, true);
        };
        $sizes = array_values(array_filter(
            array_map('strval', array_column($sizeRows, 'size')),
            static fn (string $size): bool => Shop::usesOption1($size)
        ));
        usort($sizes, static function (string $a, string $b) use ($rank): int {
            $ia = $rank($a);
            $ib = $rank($b);
            if ($ia !== false && $ib !== false) {
                return $ia <=> $ib ?: strnatcasecmp($a, $b);
            }
            if ($ia !== false) {
                return -1;
            }
            if ($ib !== false) {
                return 1;
            }
            return strnatcasecmp($a, $b);
        });

        $styleRows = $this->db->fetchAll(
            "SELECT DISTINCT p.dress_style AS style FROM products p
             WHERE {$published} AND p.dress_style IS NOT NULL AND p.dress_style <> ''
             ORDER BY p.dress_style ASC",
            $types,
            $params
        );

        $price = $this->db->fetchOne(
            "SELECT COALESCE(MIN(p.base_price), 0) AS min_price, COALESCE(MAX(p.base_price), 0) AS max_price
             FROM products p WHERE {$published}",
            $types,
            $params
        );

        return [
            'colors' => $colors,
            'sizes' => $sizes,
            'dress_styles' => array_column($styleRows, 'style'),
            'price' => ['min' => (float) $price['min_price'], 'max' => (float) $price['max_price']],
        ];
    }

    /**
     * Approved reviews for a product, newest first.
     */
    public function approvedReviews(int $productId, int $limit = 50): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, author_name AS author, rating, title, comment, is_verified_purchase AS verified, created_at
             FROM reviews
             WHERE product_id = ? AND status = 'approved'
             ORDER BY created_at DESC
             LIMIT ?",
            'ii',
            [$productId, $limit]
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['rating'] = (int) $r['rating'];
            $r['verified'] = (bool) $r['verified'];
        }
        unset($r);
        return $rows;
    }

    /**
     * Recompute a product's shown rating from its starting rating and approved reviews.
     */
    public function refreshRating(int $productId): void
    {
        ProductRatings::refresh($this->db, $productId);
    }

    /**
     * Admin: List all products with advanced filtering (status, inventory, category, search) and full variant details.
     */
    public function listForAdmin(array $filters = [], int $page = 1, int $perPage = 15): array
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);
        $offset = ($page - 1) * $perPage;

        $conditions = ["1=1"];
        $params = [];
        $types = "";

        // Status filter: 'all', 'published', 'draft', 'archived'
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $conditions[] = "p.status = ?";
            $params[] = (string) $filters['status'];
            $types .= "s";
        }

        // Product type filter: 'all', 'physical', 'digital'
        if (!empty($filters['product_type']) && $filters['product_type'] !== 'all') {
            $conditions[] = "p.product_type = ?";
            $params[] = (string) $filters['product_type'];
            $types .= "s";
        }

        // Category filter
        if (!empty($filters['category']) && $filters['category'] !== 'all') {
            if (is_numeric($filters['category'])) {
                $conditions[] = "p.category_id = ?";
                $params[] = (int) $filters['category'];
                $types .= "i";
            } else {
                $conditions[] = "c.slug = ?";
                $params[] = (string) $filters['category'];
                $types .= "s";
            }
        }

        // Search (name, slug, SKU, description)
        if (!empty($filters['search'])) {
            $search = '%' . Input::singleSpaced($filters['search']) . '%';
            $conditions[] = "(p.name LIKE ? OR p.sku LIKE ? OR p.slug LIKE ? OR p.description LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $types .= "ssss";
        }

        // Inventory status filter (subquery based)
        if (!empty($filters['inventory']) && $filters['inventory'] !== 'all') {
            if ($filters['inventory'] === 'out_of_stock') {
                $conditions[] = "(SELECT COALESCE(SUM(pv.stock_quantity), 0) FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_active = 1) = 0";
            } elseif ($filters['inventory'] === 'low_stock') {
                $conditions[] = "EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_active = 1 AND pv.stock_quantity <= pv.low_stock_threshold AND pv.stock_quantity > 0)";
            } elseif ($filters['inventory'] === 'in_stock') {
                $conditions[] = "(SELECT COALESCE(SUM(pv.stock_quantity), 0) FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_active = 1) > 0";
            }
        }

        $whereSql = implode(' AND ', $conditions);

        // Sorting
        $sortOrder = "p.created_at DESC";
        $sort = $filters['sort'] ?? 'newest';
        if ($sort === 'oldest') {
            $sortOrder = "p.created_at ASC";
        } elseif ($sort === 'price_asc') {
            $sortOrder = "p.base_price ASC";
        } elseif ($sort === 'price_desc') {
            $sortOrder = "p.base_price DESC";
        } elseif ($sort === 'name_asc') {
            $sortOrder = "p.name ASC";
        }

        // 1. Total matching count
        $countSql = "SELECT COUNT(DISTINCT p.id) AS total
                     FROM products p
                     LEFT JOIN categories c ON c.id = p.category_id
                     WHERE {$whereSql}";
        $countResult = $this->db->fetchOne($countSql, $types, $params);
        $total = $countResult ? (int) $countResult['total'] : 0;

        // 2. Paginated rows
        $itemsSql = "SELECT p.id, p.uuid, p.name, p.slug, p.description, p.product_type,
                            p.status, p.base_price, p.original_price, p.currency, p.sku,
                            p.rating_avg, p.rating_count, p.rating_start, p.review_avg, p.is_featured, p.is_flash_deal, p.flash_deal_ends_at, p.dress_style,
                            p.specifications, p.warranty, p.is_heavy,
                            p.created_at, p.updated_at,
                            c.id AS category_id, c.name AS category_name, c.slug AS category_slug,
                            (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.display_order ASC LIMIT 1) AS primary_image,
                            (SELECT COALESCE(SUM(pv.stock_quantity), 0) FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_active = 1) AS total_stock
                     FROM products p
                     LEFT JOIN categories c ON c.id = p.category_id
                     WHERE {$whereSql}
                     ORDER BY {$sortOrder}
                     LIMIT ? OFFSET ?";

        $itemParams = $params;
        $itemParams[] = $perPage;
        $itemParams[] = $offset;
        $itemTypes = $types . "ii";

        $rows = $this->db->fetchAll($itemsSql, $itemTypes, $itemParams);

        // Fetch variants, images, and digital metadata for each product
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['base_price'] = (float) $row['base_price'];
            $row['original_price'] = $row['original_price'] !== null ? (float) $row['original_price'] : null;
            $row['total_stock'] = (int) $row['total_stock'];
            $row['is_featured'] = (bool) $row['is_featured'];
            $row['is_flash_deal'] = (bool) $row['is_flash_deal'];
            $row['rating_avg'] = (float) $row['rating_avg'];
            $row['rating_count'] = (int) $row['rating_count'];
            $row['rating_start'] = $row['rating_start'] !== null ? (float) $row['rating_start'] : null;
            $row['review_avg'] = (float) $row['review_avg'];
            $row['specifications'] = self::specificationRows($row['specifications']);
            $row['warranty'] = Input::text($row['warranty']) !== '' ? Input::text($row['warranty']) : null;
            $row['is_heavy'] = (bool) $row['is_heavy'];
            $row['age_restricted'] = Shop::isAgeRestricted($row['category_slug'] ?? null);

            // Variants
            $variants = $this->db->fetchAll(
                "SELECT id, sku, size, color_name, color_hex, price_override, stock_quantity, low_stock_threshold
                 FROM product_variants
                 WHERE product_id = ? AND is_active = 1
                 ORDER BY id ASC",
                "i",
                [$row['id']]
            );

            $hasLowStock = false;
            foreach ($variants as &$v) {
                $v['id'] = (int) $v['id'];
                $v['price_override'] = ($v['price_override'] !== null && (float) $v['price_override'] > 0) ? (float) $v['price_override'] : null;
                $v['stock_quantity'] = (int) $v['stock_quantity'];
                $v['low_stock_threshold'] = (int) ($v['low_stock_threshold'] ?? 5);
                $v['in_stock'] = $v['stock_quantity'] > 0;
                $v['is_low_stock'] = $v['stock_quantity'] <= $v['low_stock_threshold'];
                if ($v['is_low_stock']) {
                    $hasLowStock = true;
                }
            }
            unset($v);
            $row['variants'] = $variants;
            $row['variants_count'] = count($variants);
            $row['is_low_stock'] = $hasLowStock;

            // Images
            $images = $this->db->fetchAll(
                "SELECT id, image_url, alt_text, is_primary, display_order
                 FROM product_images
                 WHERE product_id = ?
                 ORDER BY is_primary DESC, display_order ASC",
                "i",
                [$row['id']]
            );
            $row['images'] = $images;

            $row['digital_deliverable'] = null;
        }
        unset($row);

        return [
            'items' => $rows,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Admin: Adjust variant stock quantity with reason and immutable audit log.
     */
    public function adjustVariantStock(int $variantId, int $adjustment, string $reason, ?string $notes = null, ?int $adminId = null): array
    {
        return $this->db->transaction(function (Database $db) use ($variantId, $adjustment, $reason, $notes, $adminId) {
            $variant = $db->fetchOne(
                "SELECT id, product_id, sku, stock_quantity, low_stock_threshold FROM product_variants WHERE id = ? FOR UPDATE",
                "i",
                [$variantId]
            );

            if (!$variant) {
                throw new \InvalidArgumentException("Variant not found.");
            }

            $currentStock = (int) $variant['stock_quantity'];
            $newStock = $currentStock + $adjustment;

            if ($newStock < 0) {
                throw new \InvalidArgumentException("Cannot reduce stock below zero. Current stock is {$currentStock}, requested adjustment is {$adjustment}.");
            }
            if ($adjustment > 0 && $newStock > Input::MAX_STOCK) {
                throw new \InvalidArgumentException('Stock can be at most ' . number_format(Input::MAX_STOCK) . ". Current stock is {$currentStock}, so this adjustment of {$adjustment} is too large.");
            }

            $db->execute(
                "UPDATE product_variants SET stock_quantity = ? WHERE id = ?",
                "ii",
                [$newStock, $variantId]
            );

            $productId = (int) $variant['product_id'];

            $db->execute(
                "INSERT INTO `inventory_logs` (`variant_id`, `product_id`, `previous_stock`, `new_stock`, `change_amount`, `reason`, `adjusted_by_user_id`)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                'iiiiisi',
                [$variantId, $productId, $currentStock, $newStock, $adjustment, mb_substr($reason . ($notes ? " ({$notes})" : ''), 0, 255), $adminId]
            );

            \App\Services\AuditService::record(
                $adminId,
                'inventory.adjust',
                'product_variant',
                (string) $variantId,
                [
                    'sku' => $variant['sku'],
                    'product_id' => $productId,
                    'stock_quantity' => $currentStock,
                ],
                [
                    'sku' => $variant['sku'],
                    'product_id' => $productId,
                    'stock_quantity' => $newStock,
                    'adjustment' => $adjustment,
                    'reason' => $reason,
                    'notes' => $notes,
                ]
            );

            $tot = $db->fetchOne(
                "SELECT COALESCE(SUM(stock_quantity), 0) AS total_stock FROM product_variants WHERE product_id = ?",
                "i",
                [$productId]
            );

            return [
                'variant_id' => $variantId,
                'product_id' => $productId,
                'sku' => $variant['sku'],
                'previous_stock' => $currentStock,
                'adjustment' => $adjustment,
                'new_stock' => $newStock,
                'product_total_stock' => (int) ($tot['total_stock'] ?? $newStock),
                'reason' => $reason,
                'notes' => $notes,
            ];
        });
    }

    /**
     * Admin: Quick update product status (draft, published, archived) with audit log.
     */
    public function updateStatus(int $productId, string $status, ?int $adminId = null): array
    {
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new \InvalidArgumentException("Invalid product status.");
        }

        $existing = $this->db->fetchOne("SELECT id, name, status FROM products WHERE id = ?", "i", [$productId]);
        if (!$existing) {
            throw new \InvalidArgumentException("Product not found.");
        }

        $oldStatus = $existing['status'];
        $this->db->execute("UPDATE products SET status = ? WHERE id = ?", "si", [$status, $productId]);


        return [
            'id' => $productId,
            'previous_status' => $oldStatus,
            'status' => $status,
        ];
    }

    /**
     * Admin: Attach or update private digital deliverable metadata (Disabled).
     */
    public function saveDigitalDeliverable(int $productId, array $data, ?int $adminId = null): array
    {
        throw new \DomainException("Digital products are disabled. The store operates exclusively with physical products.");
    }

    /**
     * Auto-generate a readable master SKU based on category, dress style, name, and random salt.
     */
    public static function generateSku(array $data): string
    {
        $prefix = 'SHOP';
        if (!empty($data['category_slug'])) {
            $prefix = strtoupper(substr(str_replace(['-', '_', ' '], '', (string) $data['category_slug']), 0, 3));
        } elseif (!empty($data['dress_style'])) {
            $prefix = strtoupper(substr(str_replace(['-', '_', ' '], '', (string) $data['dress_style']), 0, 3));
        }

        $mid = 'ITEM';
        if (!empty($data['slug'])) {
            $words = explode('-', strtolower((string) $data['slug']));
            $clean = array_filter($words, fn($w) => strlen($w) > 1);
            if (!empty($clean)) {
                $mid = strtoupper(substr(implode('-', array_slice($clean, 0, 2)), 0, 8));
            }
        } elseif (!empty($data['name'])) {
            $words = preg_split('/\s+/', strtolower((string) $data['name']));
            $clean = array_filter($words, fn($w) => strlen($w) > 1);
            if (!empty($clean)) {
                $mid = strtoupper(substr(implode('-', array_slice($clean, 0, 2)), 0, 8));
            }
        }

        $salt = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        return sprintf('%s-%s-%s', $prefix, $mid, $salt);
    }

    /**
     * Auto-generate a readable variant SKU based on master SKU, color, and size.
     */
    public static function generateVariantSku(string $masterSku, string $color, string $size, int $index = 1): string
    {
        $colorPart = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $color), 0, 3));
        if ($colorPart === '') $colorPart = 'DEF';

        $sizePart = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $size), 0, 3));
        if ($sizePart === '') $sizePart = (string) $index;

        return sprintf('%s-%s-%s', $masterSku, $colorPart, $sizePart);
    }

    /**
     * Admin: Create a product with variants and images inside an atomic transaction.
     */
    public function create(array $data, array $variants = [], array $images = [], ?array $digital = null): int
    {
        return $this->db->transaction(function (Database $db) use ($data, $variants, $images, $digital) {
            $uuid = $data['uuid'] ?? sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );

            // Auto-generate SKU if omitted or empty
            $masterSku = !empty($data['sku']) ? trim((string) $data['sku']) : self::generateSku($data);

            $sql = "INSERT INTO products (uuid, category_id, name, slug, description, product_type, status, base_price, original_price, currency, sku, dress_style, is_featured, is_flash_deal, flash_deal_ends_at, rating_start, specifications, warranty, is_heavy)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $productId = (int) $db->insert(
                $sql,
                "sisssssddsssiisdssi",
                [
                    $uuid,
                    empty($data['category_id']) ? null : (int) $data['category_id'],
                    $data['name'],
                    $data['slug'],
                    $data['description'] ?? null,
                    $data['product_type'] ?? 'physical',
                    $data['status'] ?? 'published',
                    $data['base_price'],
                    $data['original_price'] ?? null,
                    $data['currency'] ?? 'NGN',
                    $masterSku,
                    // The editor's "Not set" sends null: store '' like an edit does. Callers that leave
                    // the field out get the column's usual 'Casual'.
                    array_key_exists('dress_style', $data) ? trim((string) ($data['dress_style'] ?? '')) : 'Casual',
                    $data['is_featured'] ?? 0,
                    $data['is_flash_deal'] ?? 0,
                    self::flashEnd($data, null),
                    self::ratingStart($data, null),
                    self::specificationsJson($data['specifications'] ?? null),
                    self::warranty($data['warranty'] ?? null),
                    self::flag($data['is_heavy'] ?? false),
                ]
            );

            // Insert variants
            if (!empty($variants)) {
                foreach ($variants as $idx => $v) {
                    // An option left empty is stored as the shop's "none" word; a missing colour code as black.
                    $v = self::variantOptions($v) + $v;
                    $variantSku = !empty($v['sku'])
                        ? trim((string) $v['sku'])
                        : self::generateVariantSku($masterSku, $v['color_name'], $v['size'], $idx + 1);

                    $db->execute(
                        "INSERT INTO product_variants (product_id, sku, size, color_name, color_hex, price_override, stock_quantity, low_stock_threshold)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        "issssdii",
                        [
                            $productId,
                            $variantSku,
                            $v['size'],
                            $v['color_name'],
                            $v['color_hex'],
                            (!empty($v['price_override']) && (float) $v['price_override'] > 0) ? (float) $v['price_override'] : null,
                            $v['stock_quantity'] ?? 0,
                            $v['low_stock_threshold'] ?? 5,
                        ]
                    );
                }
            } else {
                $db->execute(
                    "INSERT INTO product_variants (product_id, sku, size, color_name, color_hex, price_override, stock_quantity, low_stock_threshold)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    "issssdii",
                    [
                        $productId,
                        $masterSku . '-STD',
                        Shop::option1None(),
                        Shop::option2None(),
                        '#000000',
                        null,
                        $data['stock_quantity'] ?? 10,
                        $data['low_stock_threshold'] ?? 5,
                    ]
                );
            }

            // Insert images
            if (!empty($images)) {
                foreach ($images as $idx => $img) {
                    $db->execute(
                        "INSERT INTO product_images (product_id, image_url, alt_text, is_primary, display_order)
                         VALUES (?, ?, ?, ?, ?)",
                        "issii",
                        [
                            $productId,
                            $img['image_url'],
                            $img['alt_text'] ?? $data['name'],
                            $img['is_primary'] ?? ($idx === 0 ? 1 : 0),
                            $img['display_order'] ?? $idx,
                        ]
                    );
                }
            }

            ProductRatings::refresh($db, $productId);

            return $productId;
        });
    }

    /**
     * Admin: Update product details with variants, images and digital deliverable.
     */
    public function update(int $id, array $data, ?array $variants = null, ?array $images = null, ?array $digital = null): bool
    {
        return $this->db->transaction(function (Database $db) use ($id, $data, $variants, $images, $digital) {
            $existing = $this->findDetail((string) $id, true);
            if (!$existing) {
                return false;
            }

            $sql = "UPDATE products
                    SET category_id = ?, name = ?, slug = ?, sku = ?, description = ?, status = ?,
                        product_type = ?, base_price = ?, original_price = ?, dress_style = ?,
                        is_featured = ?, is_flash_deal = ?, flash_deal_ends_at = ?, rating_start = ?,
                        specifications = ?, warranty = ?, is_heavy = ?
                    WHERE id = ?";

            // Fields the editor can clear (no "was" price, no category, no style) arrive as null:
            // null means "clear", only a missing key keeps the stored value.
            $clearable = static fn (string $key) => array_key_exists($key, $data) ? $data[$key] : ($existing[$key] ?? null);
            $categoryId = $clearable('category_id');
            $originalPrice = $clearable('original_price');
            $dressStyle = $clearable('dress_style');
            $sku = Input::text($data['sku'] ?? null);

            $db->execute(
                $sql,
                "issssssddsiisdssii",
                [
                    $categoryId === null || $categoryId === '' || (int) $categoryId <= 0 ? null : (int) $categoryId,
                    $data['name'] ?? $existing['name'],
                    $data['slug'] ?? $existing['slug'],
                    $sku !== '' ? $sku : $existing['sku'],
                    $data['description'] ?? $existing['description'],
                    $data['status'] ?? $existing['status'],
                    $data['product_type'] ?? $existing['product_type'],
                    $data['base_price'] ?? $existing['base_price'],
                    $originalPrice === null || $originalPrice === '' ? null : (float) $originalPrice,
                    // "Not set" is stored as '' (the column is NOT NULL; strict SQL modes reject NULL).
                    $dressStyle === null ? '' : trim((string) $dressStyle),
                    isset($data['is_featured']) ? (int) $data['is_featured'] : (int) $existing['is_featured'],
                    isset($data['is_flash_deal']) ? (int) $data['is_flash_deal'] : (int) $existing['is_flash_deal'],
                    self::flashEnd($data, $existing),
                    self::ratingStart($data, $existing),
                    // Like the fields above: null or empty clears, only a missing key keeps.
                    self::specificationsJson($clearable('specifications')),
                    self::warranty($clearable('warranty')),
                    self::flag(array_key_exists('is_heavy', $data) ? $data['is_heavy'] : ($existing['is_heavy'] ?? false)),
                    $id,
                ]
            );
            ProductRatings::refresh($db, $id);

            // Sync variants if provided: upsert by id so variant IDs (referenced by orders, carts
            // and inventory logs) stay stable.
            if ($variants !== null && is_array($variants)) {
                // New variants without a SKU are named after the SKU being saved, not the old one.
                $this->syncVariants($db, $id, $sku !== '' ? $sku : (string) ($existing['sku'] ?? ''), $variants);
            }

            // Sync images if provided; an empty list removes every photo (the editor allows it).
            if ($images !== null && is_array($images)) {
                $db->execute("DELETE FROM product_images WHERE product_id = ?", "i", [$id]);
                foreach ($images as $idx => $img) {
                    $db->execute(
                        "INSERT INTO product_images (product_id, image_url, alt_text, is_primary, display_order)
                         VALUES (?, ?, ?, ?, ?)",
                        "issii",
                        [
                            $id,
                            $img['image_url'],
                            $img['alt_text'] ?? ($data['name'] ?? $existing['name']),
                            $img['is_primary'] ?? ($idx === 0 ? 1 : 0),
                            $img['display_order'] ?? $idx,
                        ]
                    );
                }
            }


            return true;
        });
    }

    /**
     * Upsert a product's variants from the admin form.
     *
     * - Existing variants (matched by id, or by size + colour) are updated in place.
     * - Stock on an existing variant changes only if the form's `original_stock_quantity`
     *   still matches the database; otherwise orders arrived meanwhile and the edit is refused
     *   rather than silently overwriting reserved stock. A variant the form adds again (archived,
     *   or a row with no id) has no stock on the form to compare: its stock is simply set.
     *   Every stock change is logged.
     * - Variants removed from the form are archived if anything references them, else deleted.
     */
    private function syncVariants(Database $db, int $productId, string $masterSku, array $variants): void
    {
        $adminId = \App\Support\RequestContext::getUser()['id'] ?? null;

        $rows = $db->fetchAll(
            "SELECT id, sku, size, color_name, stock_quantity, is_active FROM product_variants WHERE product_id = ? FOR UPDATE",
            'i',
            [$productId]
        );
        $byId = [];
        $byOptions = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
            $byOptions[strtolower(Input::text($row['size'] ?? null)) . '|' . strtolower(Input::text($row['color_name'] ?? null))] = $row;
        }

        $keptIds = [];
        foreach ($variants as $index => $v) {
            // An option left empty means the product does not use it: stored as the shop's "none" word.
            ['size' => $size, 'color_name' => $colorName, 'color_hex' => $colorHex] = self::variantOptions($v);
            $optionsKey = strtolower($size) . '|' . strtolower($colorName);
            $priceOverride = (!empty($v['price_override']) && (float) $v['price_override'] > 0) ? (float) $v['price_override'] : null;
            $threshold = max(0, (int) ($v['low_stock_threshold'] ?? 5));

            $vid = isset($v['id']) ? (int) $v['id'] : 0;
            $matchedById = $vid > 0 && isset($byId[$vid]);
            $current = $matchedById ? $byId[$vid] : ($byOptions[$optionsKey] ?? null);

            if ($current !== null) {
                $vid = (int) $current['id'];
                $revived = !$matchedById || (int) $current['is_active'] === 0;
                if (in_array($vid, $keptIds, true)) {
                    // Named by what the admin typed, never by the "none" word.
                    $named = Shop::optionsText($size, $colorName);
                    throw new \InvalidArgumentException($named !== ''
                        ? "Duplicate variant {$named} in the form."
                        : 'Only one variant can be without ' . Shop::withArticle(Shop::option1Word()) . ' and ' . Shop::withArticle(Shop::option2Word()) . '.');
                }
                $sku = trim((string) ($v['sku'] ?? '')) ?: $current['sku'];

                $db->execute(
                    "UPDATE product_variants
                     SET sku = ?, size = ?, color_name = ?, color_hex = ?, price_override = ?, low_stock_threshold = ?, is_active = 1
                     WHERE id = ? AND product_id = ?",
                    'ssssdiii',
                    [$sku, $size, $colorName, $colorHex, $priceOverride, $threshold, $vid, $productId]
                );

                if (array_key_exists('stock_quantity', $v) && (int) $v['stock_quantity'] !== (int) $current['stock_quantity']) {
                    $newStock = (int) $v['stock_quantity'];
                    $expected = isset($v['original_stock_quantity']) ? (int) $v['original_stock_quantity'] : null;
                    if ($newStock < 0) {
                        throw new \InvalidArgumentException("Stock for {$sku} cannot be negative.");
                    }
                    if (!$revived && ($expected === null || $expected !== (int) $current['stock_quantity'])) {
                        throw new \InvalidArgumentException(
                            "Stock for {$sku} changed to {$current['stock_quantity']} since this form was opened (orders or another edit). Reload the product and try again."
                        );
                    }
                    $db->execute("UPDATE product_variants SET stock_quantity = ? WHERE id = ?", 'ii', [$newStock, $vid]);
                    $db->execute(
                        "INSERT INTO `inventory_logs` (`variant_id`, `product_id`, `previous_stock`, `new_stock`, `change_amount`, `reason`, `adjusted_by_user_id`)
                         VALUES (?, ?, ?, ?, ?, ?, ?)",
                        'iiiiisi',
                        [$vid, $productId, (int) $current['stock_quantity'], $newStock, $newStock - (int) $current['stock_quantity'], $revived ? 'Variant added back' : 'Product form edit', $adminId]
                    );
                }
            } else {
                $sku = trim((string) ($v['sku'] ?? '')) ?: self::generateVariantSku($masterSku, $colorName, $size, $index + 1);
                $initialStock = max(0, (int) ($v['stock_quantity'] ?? 0));
                $vid = (int) $db->insert(
                    "INSERT INTO product_variants (product_id, sku, size, color_name, color_hex, price_override, stock_quantity, low_stock_threshold)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    'issssdii',
                    [$productId, $sku, $size, $colorName, $colorHex, $priceOverride, $initialStock, $threshold]
                );
                if ($initialStock > 0) {
                    $db->execute(
                        "INSERT INTO `inventory_logs` (`variant_id`, `product_id`, `previous_stock`, `new_stock`, `change_amount`, `reason`, `adjusted_by_user_id`)
                         VALUES (?, ?, 0, ?, ?, 'Initial stock (new variant)', ?)",
                        'iiiii',
                        [$vid, $productId, $initialStock, $initialStock, $adminId]
                    );
                }
            }

            $keptIds[] = $vid;
        }

        foreach ($byId as $vid => $row) {
            if (in_array($vid, $keptIds, true) || (int) $row['is_active'] === 0) {
                continue;
            }
            $referenced = $db->fetchOne(
                "SELECT (SELECT COUNT(*) FROM order_items WHERE variant_id = ?) + (SELECT COUNT(*) FROM inventory_logs WHERE variant_id = ?) AS refs",
                'ii',
                [$vid, $vid]
            );
            if ((int) ($referenced['refs'] ?? 0) > 0) {
                $db->execute("UPDATE product_variants SET is_active = 0 WHERE id = ?", 'i', [$vid]);
            } else {
                $db->execute("DELETE FROM product_variants WHERE id = ?", 'i', [$vid]);
            }
        }
    }

    /**
     * Admin: Soft-delete / archive a product.
     */
    public function delete(int $id): bool
    {
        $existing = $this->db->fetchOne("SELECT id, name, status FROM products WHERE id = ?", "i", [$id]);
        $this->db->execute("UPDATE products SET status = 'archived' WHERE id = ?", "i", [$id]);


        return true;
    }
}

