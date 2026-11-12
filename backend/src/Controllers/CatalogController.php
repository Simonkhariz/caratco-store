<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use App\Services\CheckoutService;
use App\Support\Cache;
use App\Support\Response;
use App\Support\StoreBrands;

/**
 * Public Storefront Catalogue Controller.
 * Delivers product listings, dynamic filters, detail pages, categories, and homepage aggregates.
 */
class CatalogController
{
    /** Products in each row of the home page: one line on desktop, two on a phone. */
    private const HOME_ROW_LIMIT = 4;

    private ProductRepository $productRepo;
    private CategoryRepository $categoryRepo;

    public function __construct()
    {
        $this->productRepo = new ProductRepository();
        $this->categoryRepo = new CategoryRepository();
    }

    /**
     * GET /api/v1/products
     * Filterable, searchable, and paginated product catalogue.
     */
    public function index(): void
    {
        $filters = [
            'category' => $_GET['category'] ?? $_GET['cat'] ?? null,
            'search' => $_GET['search'] ?? $_GET['q'] ?? null,
            'min_price' => $_GET['min_price'] ?? null,
            'max_price' => $_GET['max_price'] ?? null,
            'dress_style' => $_GET['dress_style'] ?? $_GET['style'] ?? null,
            'color' => $_GET['color'] ?? null,
            'colors' => $_GET['colors'] ?? null,
            'size' => $_GET['size'] ?? null,
            'sizes' => $_GET['sizes'] ?? null,
            'on_sale' => !empty($_GET['on_sale']) || !empty($_GET['onSale']),
            'is_featured' => !empty($_GET['is_featured']) || !empty($_GET['featured']),
            'is_flash_deal' => !empty($_GET['flash_deals']) || !empty($_GET['flashDeals']),
            'product_type' => 'physical',
            'sort' => $_GET['sort'] ?? (!empty($_GET['topSelling']) ? 'best_selling' : 'newest'),
        ];

        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : (isset($_GET['limit']) ? (int) $_GET['limit'] : 12);

        // Browsing pages are the same for every visitor; searches vary too much to be worth keeping.
        $load = fn (): array => $this->productRepo->listPublished($filters, $page, $perPage);
        $cacheKey = empty($filters['search']) ? self::listCacheKey($filters, $page, $perPage) : null;
        $result = $cacheKey !== null ? Cache::remember($cacheKey, $load) : $load();
        Response::cachePublic(30, 120);

        Response::success(
            $result['items'],
            'Products retrieved successfully.',
            200,
            [
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total_pages' => $result['total_pages'],
            ]
        );
    }

    /**
     * GET /api/v1/products/{idOrSlug}
     * Full product details including images, variants, and public availability.
     */
    public function show(array $params): void
    {
        $idOrSlug = $params['idOrSlug'] ?? '';
        if (trim($idOrSlug) === '') {
            Response::error('Product identifier is required.', 400, 'BAD_REQUEST');
        }

        $product = $this->productRepo->findDetail($idOrSlug);
        if (!$product) {
            Response::error(
                sprintf("Product '%s' not found or is no longer published.", $idOrSlug),
                404,
                'RESOURCE_NOT_FOUND'
            );
        }

        $product['reviews'] = $this->productRepo->approvedReviews((int) $product['id']);

        // Stock changes often: browsers re-check every time, but get "not modified" when it hasn't.
        Response::cachePublic(0);

        Response::success($product, 'Product details retrieved successfully.');
    }

    /** Listing pages deeper than this are rare enough to be read from the database every time. */
    private const CACHE_MAX_PAGE = 20;

    /**
     * Cache key for a listing request, built from normalised values only (the same filters in a
     * different order or spelling share one entry), or null when the request should not be
     * cached: a deep page or an unusual filter value. Anyone can send any query string, so
     * keying on the raw values would let made-up filters fill the cache folder.
     */
    private static function listCacheKey(array $filters, int $page, int $perPage): ?string
    {
        $page = max(1, $page);
        if ($page > self::CACHE_MAX_PAGE) {
            return null;
        }
        $token = static fn ($v): bool => is_string($v) && preg_match('/^[\p{L}\p{N} #._-]{0,64}$/u', $v) === 1;
        $key = ['p' => $page, 'n' => min(max(1, $perPage), 100)];
        foreach ($filters as $name => $value) {
            if (is_bool($value)) {
                $key[$name] = $value;
            } elseif ($value === null || $value === '') {
                continue;
            } elseif ($name === 'min_price' || $name === 'max_price') {
                if (!is_numeric($value)) {
                    return null;
                }
                $key[$name] = (float) $value;
            } elseif ($name === 'colors' || $name === 'sizes') {
                $items = is_array($value) ? $value : explode(',', (string) $value);
                if (count($items) > 20) {
                    return null;
                }
                foreach ($items as $item) {
                    if (!$token($item)) {
                        return null;
                    }
                }
                $items = array_values(array_unique(array_filter(array_map(static fn (string $i): string => strtolower(trim($i)), $items), 'strlen')));
                sort($items);
                $key[$name] = $items;
            } elseif ($token($value)) {
                $key[$name] = $value;
            } else {
                return null;
            }
        }
        return 'products:' . json_encode($key);
    }

    /**
     * GET /api/v1/catalog/facets[?category=slug]
     * Filter options (categories, colours, sizes, styles, price range) that exist in the catalogue.
     * With a category, colours, sizes, styles and prices are those of that category; the
     * category list is always the full one.
     */
    public function facets(): void
    {
        $category = trim((string) ($_GET['category'] ?? $_GET['cat'] ?? ''));
        $category = preg_match('/^[a-z0-9-]{1,100}$/', $category) ? $category : '';
        $facets = Cache::remember('facets:' . $category, function () use ($category): array {
            $facets = $this->productRepo->facets($category);
            $facets['categories'] = $this->categoryRepo->getAllActive();
            return $facets;
        }, 10); // Only admin changes affect these (and they clear the cache).
        Response::cachePublic(60, 300);

        Response::success($facets, 'Catalogue filter options retrieved.');
    }

    /**
     * GET /api/v1/categories
     * List all active categories with product counts.
     */
    public function categories(): void
    {
        $categories = Cache::remember('categories', fn (): array => $this->categoryRepo->getAllActive(), 10);
        Response::cachePublic(60, 300);
        Response::success($categories, 'Categories retrieved successfully.');
    }

    /**
     * GET /api/v1/storefront/homepage
     * Single aggregated payload for fast homepage hydration.
     */
    public function homepage(): void
    {
        $payload = Cache::remember('homepage', fn (): array => $this->homepagePayload());
        Response::cachePublic(60, 300);
        Response::success($payload, 'Storefront homepage payload retrieved successfully.');
    }

    private function homepagePayload(): array
    {
        // A product appears once on the page, in the first row that has it, so the rows don't
        // repeat each other: the same order the shopper sees them in.
        $flashDeals = $this->productRepo->getFlashDeals(self::HOME_ROW_LIMIT);
        $shown = array_column($flashDeals, 'id');
        $featured = $this->productRepo->getFeatured(self::HOME_ROW_LIMIT, $shown);
        $shown = array_merge($shown, array_column($featured, 'id'));
        $newArrivals = $this->productRepo->getNewArrivals(self::HOME_ROW_LIMIT, $shown);
        $shown = array_merge($shown, array_column($newArrivals, 'id'));
        $topSelling = $this->productRepo->getTopSelling(self::HOME_ROW_LIMIT, $shown);
        $categories = $this->categoryRepo->getAllActive();

        // Only the code the owner chose to advertise: others may be private. Then the banners.
        $db = Database::getInstance();
        $promotions = $db->fetchAll(
            "SELECT code, description, discount_type, discount_percent, discount_amount, min_order_amount, max_discount_amount FROM promotions
             WHERE " . \App\Services\CouponRules::ADVERTISED_SQL
        );
        $banners = $db->fetchAll(
            "SELECT id, title, subtitle, cta_text, cta_url, image_url, display_order FROM banners 
             WHERE is_active = 1 ORDER BY display_order ASC, id ASC LIMIT 5"
        );

        // Approved customer testimonials
        $reviews = $db->fetchAll(
            "SELECT r.id, r.product_id, r.rating, r.title, r.comment, r.created_at, 
                    r.author_name as author, r.is_verified_purchase as verified, 
                    p.name as product_name
             FROM reviews r
             LEFT JOIN products p ON p.id = r.product_id
             WHERE r.status = 'approved'
             ORDER BY r.created_at DESC LIMIT 10"
        );

        // Real counts only (shown on the homepage instead of invented marketing numbers).
        $zones = $db->fetchAll("SELECT `states_covered` FROM `shipping_zones` WHERE `is_active` = 1");
        $served = [];
        foreach ($zones as $z) {
            foreach ((array) json_decode((string) $z['states_covered'], true) as $state) {
                $served[$state === '*' ? '*' : (CheckoutService::canonicalState((string) $state) ?? '')] = true;
            }
        }
        unset($served['']);
        $stats = [
            'published_products' => (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM `products` WHERE `status` = 'published' AND `product_type` = 'physical'")['c'] ?? 0),
            'categories' => count($categories),
            'states_served' => isset($served['*']) ? count(CheckoutService::NIGERIAN_STATES) : count($served),
        ];

        $brandSetting = $db->fetchOne("SELECT `value` FROM `store_settings` WHERE `key_name` = 'storefront_brands'");

        // The soonest real end time among the flash deals shown (null = no countdown).
        $flashEnds = array_filter(array_column($flashDeals, 'flash_deal_ends_at'));
        sort($flashEnds);

        return [
            'stats' => $stats,
            'flash_deals_end_at' => $flashEnds[0] ?? null,
            'featured_products' => $featured,
            'flash_deals' => $flashDeals,
            'new_arrivals' => $newArrivals,
            'top_selling' => $topSelling,
            'categories' => $categories,
            'promotions' => array_map(
                static fn (array $p): array => ['code' => $p['code'], 'description' => $p['description']] + \App\Services\CouponRules::publicFields($p),
                $promotions
            ),
            'banners' => $banners,
            'reviews' => $reviews,
            'brands' => StoreBrands::parse((string) ($brandSetting['value'] ?? '')),
        ];
    }
}
