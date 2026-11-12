<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Shop;
use App\Support\Input;
use App\Middleware\JsonBodyMiddleware;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use App\Services\AuditService;
use App\Storage\Storage;
use App\Storage\StorageError;
use App\Storage\StorageKey;
use App\Support\ImageMetadata;
use App\Support\RequestContext;
use App\Support\Response;
use App\Support\UploadFiles;
use App\Validators\CategoryValidator;
use App\Validators\ProductValidator;

/**
 * Admin Catalogue Management Controller.
 * Handles product and category CRUD operations, inventory adjustments,
 * status changes, and private digital deliverable metadata behind administrative authorization.
 */
class AdminCatalogController
{
    private ProductRepository $productRepo;
    private CategoryRepository $categoryRepo;

    public function __construct()
    {
        $this->productRepo = new ProductRepository();
        $this->categoryRepo = new CategoryRepository();
    }

    /**
     * GET /api/v1/admin/products
     * List all products with advanced filtering (status, inventory, category, search) and pagination.
     */
    public function index(): void
    {
        $page = Input::page($_GET['page'] ?? 1);
        $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 15;

        $filters = [
            'status' => $_GET['status'] ?? 'all',
            'product_type' => $_GET['product_type'] ?? 'all',
            'inventory' => $_GET['inventory'] ?? 'all',
            'category' => $_GET['category'] ?? 'all',
            'search' => $_GET['search'] ?? '',
            'sort' => $_GET['sort'] ?? 'newest',
        ];

        $result = $this->productRepo->listForAdmin($filters, $page, $perPage);
        Response::success(
            $result['items'],
            'Admin products retrieved successfully.',
            200,
            $result['pagination']
        );
    }

    /**
     * POST /api/v1/admin/products
     * Create a new product with optional variants, images, and private digital metadata.
     */
    public function createProduct(): void
    {
        $body = JsonBodyMiddleware::getBody();
        if (array_key_exists('name', $body)) {
            $body['name'] = Input::singleSpaced($body['name']);
        }

        // No web address given: make one from the name, never just a number ("501" -> "product-501").
        if (Input::text($body['slug'] ?? '') === '' && Input::text($body['name'] ?? '') !== '') {
            $body['slug'] = Input::slugFromName($body['name'], 'product', fn (string $slug): bool => $this->productRepo->findDetail($slug, true) !== null);
        }

        // Auto-generate SKU based on product details if omitted or blank
        if (empty($body['sku'])) {
            $body['sku'] = ProductRepository::generateSku($body);
        }

        // Auto-generate variant SKUs if omitted
        if (!empty($body['variants']) && is_array($body['variants'])) {
            foreach ($body['variants'] as $idx => &$varItem) {
                if (is_array($varItem) && empty($varItem['sku'])) {
                    // An option left empty is named by the shop's "none" word, as it will be stored.
                    $options = ProductRepository::variantOptions($varItem);
                    $varItem['sku'] = ProductRepository::generateVariantSku(
                        Input::text($body['sku']),
                        $options['color_name'],
                        $options['size'],
                        $idx + 1
                    );
                }
            }
            unset($varItem);
        }

        $errors = ProductValidator::validate($body);
        if (!empty($errors)) {
            Response::error('Validation failed for new product.', 422, 'VALIDATION_ERROR', $errors);
        }
        $this->requireCategoryExists($body);

        $variants = $body['variants'] ?? [];
        $images = $body['images'] ?? [];

        try {
            $productId = $this->productRepo->create($body, $variants, $images);
            $created = $this->productRepo->findDetail((string) $productId, true);

            AuditService::record($this->currentAdminId(), 'product.create', 'product', (string) $productId, null, [
                'name' => $body['name'],
                'sku' => $body['sku'],
                'price' => $body['base_price'],
                'status' => $body['status'] ?? 'published',
            ]);

            Response::success(
                $created,
                'Product created successfully.',
                201
            );
        } catch (\Throwable $e) {
            error_log('[Admin Product Create Failed] ' . $e->getMessage());
            $duplicate = $e instanceof \mysqli_sql_exception && (int) $e->getCode() === 1062;
            Response::error(
                $duplicate ? 'Another product already uses this slug or SKU.' : 'The product could not be created. Please try again.',
                $duplicate ? 422 : 500,
                $duplicate ? 'DUPLICATE_PRODUCT' : 'PRODUCT_CREATE_FAILED'
            );
        }
    }

    /**
     * PUT /api/v1/admin/products/{id}
     * Update an existing product, syncing variants, images, and digital metadata.
     */
    public function updateProduct(array $params): void
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            Response::error('Valid product ID required.', 400, 'BAD_REQUEST');
        }

        $existing = $this->productRepo->findDetail((string) $id, true);
        if (!$existing) {
            Response::error('Product not found.', 404, 'RESOURCE_NOT_FOUND');
        }

        $body = JsonBodyMiddleware::getBody();
        if (array_key_exists('name', $body)) {
            $body['name'] = Input::singleSpaced($body['name']);
        }
        $errors = ProductValidator::validate($body, true);

        if (!empty($errors)) {
            Response::error('Validation failed.', 422, 'VALIDATION_ERROR', $errors);
        }
        $this->requireCategoryExists($body);

        $variants = array_key_exists('variants', $body) ? $body['variants'] : null;
        $images = array_key_exists('images', $body) ? $body['images'] : null;

        $mergedData = array_merge($existing, $body);
        try {
            $this->productRepo->update($id, $mergedData, $variants, $images);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'VARIANT_CONFLICT');
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) {
                // Say which value clashes: the product's web address, its SKU, or a variant.
                $catalogDb = \App\Database\Database::getInstance();
                $slugTaken = isset($body['slug']) && $catalogDb->fetchOne("SELECT 1 AS x FROM `products` WHERE `slug` = ? AND `id` <> ?", 'si', [Input::text($body['slug']), $id]) !== null;
                $skuTaken = isset($body['sku']) && $catalogDb->fetchOne("SELECT 1 AS x FROM `products` WHERE `sku` = ? AND `id` <> ?", 'si', [Input::text($body['sku']), $id]) !== null;
                if ($slugTaken) {
                    Response::error('Another product already uses this web address (slug). Choose a different one.', 422, 'DUPLICATE_SLUG', ['slug' => 'Already used by another product.']);
                }
                if ($skuTaken) {
                    Response::error('Another product already uses this SKU.', 422, 'DUPLICATE_SKU', ['sku' => 'Already used by another product.']);
                }
                Response::error('Two variants share the same SKU or the same ' . Shop::option1Word() . ' and ' . Shop::option2Word() . '. Make each variant unique.', 422, 'DUPLICATE_VARIANT');
            }
            throw $e;
        }
        $updated = $this->productRepo->findDetail((string) $id, true);

        AuditService::record($this->currentAdminId(), 'product.update', 'product', (string) $id, [
            'name' => $existing['name'],
            'price' => $existing['base_price'],
            'sku' => $existing['sku'],
        ], [
            'name' => $updated['name'],
            'price' => $updated['base_price'],
            'sku' => $updated['sku'],
        ]);

        Response::success($updated, 'Product updated successfully.');
    }

    /**
     * PATCH /api/v1/admin/products/{id}/status
     * Quick status change (draft, published, archived) with audit log.
     */
    public function updateStatus(array $params): void
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            Response::error('Valid product ID required.', 400, 'BAD_REQUEST');
        }

        $body = JsonBodyMiddleware::getBody();
        $status = trim((string) ($body['status'] ?? ''));

        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            Response::error("Invalid status. Allowed values: 'draft', 'published', 'archived'.", 422, 'VALIDATION_ERROR');
        }

        $existing = $this->productRepo->findDetail((string) $id, true);

        try {
            $result = $this->productRepo->updateStatus($id, $status);

            AuditService::record($this->currentAdminId(), 'product.status_change', 'product', (string) $id, [
                'status' => $existing['status'] ?? null,
            ], [
                'status' => $status,
            ]);

            Response::success($result, "Product status updated to '{$status}'.");
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'UPDATE_STATUS_FAILED');
        } catch (\Throwable $e) {
            error_log('[Admin Product Status Failed] ' . $e->getMessage());
            Response::error('The product status could not be changed. Please try again.', 500, 'UPDATE_STATUS_FAILED');
        }
    }

    /**
     * POST /api/v1/admin/inventory/adjust
     * Adjust stock quantity for a variant with reason and audit record.
     */
    public function adjustInventory(): void
    {
        $body = JsonBodyMiddleware::getBody();
        // The Inventory screen sends `change_amount`; the product screen sends `adjustment`.
        if (!isset($body['adjustment']) && isset($body['change_amount'])) {
            $body['adjustment'] = $body['change_amount'];
        }

        $errors = ProductValidator::validateInventoryAdjustment($body);
        if (!empty($errors)) {
            Response::error('Validation failed for inventory adjustment.', 422, 'VALIDATION_ERROR', $errors);
        }

        $variantId = (int) $body['variant_id'];
        $adjustment = (int) $body['adjustment'];
        $reason = Input::text($body['reason']);
        $notes = !empty($body['notes']) ? Input::text($body['notes']) : null;
        $admin = RequestContext::getUser();

        try {
            $result = $this->productRepo->adjustVariantStock($variantId, $adjustment, $reason, $notes, $admin ? (int) $admin['id'] : null);
            Response::success($result, 'Inventory adjusted successfully.');
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'INVENTORY_ADJUSTMENT_FAILED');
        }
    }

    /**
     * POST /api/v1/admin/products/{id}/digital (Disabled)
     */
    public function attachDigitalDeliverable(array $params): void
    {
        Response::error('Digital products are disabled. The store operates exclusively with physical products.', 400, 'DIGITAL_PRODUCTS_DISABLED');
    }

    /**
     * DELETE /api/v1/admin/products/{id}
     * Archive an existing product.
     */
    public function deleteProduct(array $params): void
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            Response::error('Valid product ID required.', 400, 'BAD_REQUEST');
        }

        $this->productRepo->delete($id);

        AuditService::record($this->currentAdminId(), 'product.archive', 'product', (string) $id, null, [
            'status' => 'archived',
        ]);

        Response::success(['id' => $id, 'status' => 'archived'], 'Product archived successfully.');
    }

    /**
     * GET /api/v1/admin/categories
     * All categories including inactive ones, with product counts.
     */
    public function listCategories(): void
    {
        Response::success($this->categoryRepo->getAllForAdmin(), 'Categories retrieved.');
    }

    /**
     * POST /api/v1/admin/categories
     */
    public function createCategory(): void
    {
        $body = JsonBodyMiddleware::getBody();
        if (Input::text($body['slug'] ?? '') === '' && Input::text($body['name'] ?? '') !== '') {
            $body['slug'] = Input::slugFromName(Input::text($body['name']), 'category', fn (string $slug): bool => $this->categoryRepo->findBySlug($slug) !== null);
        }
        $errors = CategoryValidator::validate($body);
        if (!empty($errors)) {
            Response::error('Please fix the highlighted fields.', 422, 'VALIDATION_ERROR', $errors);
        }
        if ($this->categoryRepo->findBySlug(trim((string) $body['slug'])) !== null) {
            Response::error('Another category already uses this slug.', 422, 'VALIDATION_ERROR', ['slug' => 'Slug already in use.']);
        }

        $catId = $this->categoryRepo->create([
            'name' => trim((string) $body['name']),
            'slug' => trim((string) $body['slug']),
            'description' => isset($body['description']) ? trim((string) $body['description']) : null,
            'image_url' => isset($body['image_url']) && $body['image_url'] !== '' ? (string) $body['image_url'] : null,
            'display_order' => (int) ($body['display_order'] ?? 0),
            'is_active' => array_key_exists('is_active', $body) ? (int) (bool) $body['is_active'] : 1,
        ]);
        $created = $this->categoryRepo->findById($catId);

        AuditService::record($this->currentAdminId(), 'category.create', 'category', (string) $catId, null, $created);
        Response::success($created, 'Category created.', 201);
    }

    /**
     * PUT /api/v1/admin/categories/{id}
     * Also used to restore a deactivated category ({is_active: true}).
     */
    public function updateCategory(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $existing = $this->categoryRepo->findById($id);
        if (!$existing) {
            Response::error('Category not found.', 404, 'RESOURCE_NOT_FOUND');
        }

        $body = JsonBodyMiddleware::getBody();
        $errors = CategoryValidator::validate($body, true);
        if (!empty($errors)) {
            Response::error('Please fix the highlighted fields.', 422, 'VALIDATION_ERROR', $errors);
        }
        if (isset($body['slug'])) {
            $other = $this->categoryRepo->findBySlug(trim((string) $body['slug']));
            if ($other !== null && (int) $other['id'] !== $id) {
                Response::error('Another category already uses this slug.', 422, 'VALIDATION_ERROR', ['slug' => 'Slug already in use.']);
            }
        }

        $merged = $existing;
        foreach (['name', 'slug', 'description', 'image_url'] as $key) {
            if (array_key_exists($key, $body)) {
                $merged[$key] = $body[$key] === null || $body[$key] === '' ? ($key === 'name' || $key === 'slug' ? $existing[$key] : null) : trim((string) $body[$key]);
            }
        }
        if (array_key_exists('display_order', $body)) {
            $merged['display_order'] = (int) $body['display_order'];
        }
        if (array_key_exists('is_active', $body)) {
            $merged['is_active'] = (int) (bool) $body['is_active'];
        }

        $this->categoryRepo->update($id, $merged);
        $updated = $this->categoryRepo->findById($id);

        $action = (int) $existing['is_active'] === 0 && (int) $updated['is_active'] === 1 ? 'category.restore' : 'category.update';
        AuditService::record($this->currentAdminId(), $action, 'category', (string) $id, $existing, $updated);
        Response::success($updated, $action === 'category.restore' ? 'Category restored.' : 'Category updated.');
    }

    /**
     * DELETE /api/v1/admin/categories/{id}
     * Deactivates the category (hidden from the storefront). Products keep their category.
     */
    public function deleteCategory(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $existing = $this->categoryRepo->findById($id);
        if (!$existing) {
            Response::error('Category not found.', 404, 'RESOURCE_NOT_FOUND');
        }

        $this->categoryRepo->delete($id);
        AuditService::record($this->currentAdminId(), 'category.deactivate', 'category', (string) $id, $existing, ['is_active' => 0]);
        Response::success(['id' => $id, 'is_active' => false], 'Category hidden from the store. You can restore it any time.');
    }

    /** A category that does not exist answers 422 (the database would refuse it with a 500). */
    private function requireCategoryExists(array $body): void
    {
        $categoryId = (int) ($body['category_id'] ?? 0);
        if ($categoryId > 0 && $this->categoryRepo->findById($categoryId) === null) {
            $message = 'That category does not exist. Choose one from the list.';
            Response::error($message, 422, 'VALIDATION_ERROR', ['category_id' => $message]);
        }
    }

    private function currentAdminId(): ?int
    {
        $admin = RequestContext::getUser();
        return $admin ? (int) $admin['id'] : null;
    }

    /**
     * POST /api/v1/admin/upload-image
     * Upload an image file for product photography.
     */
    public function uploadImage(): void
    {
        // When PHP refuses an upload for its own size limits, say so plainly: otherwise the admin
        // sees "no file" (whole request over post_max_size) or a bare error number.
        $serverLimit = UploadFiles::serverLimitBytes();
        $tooBigForServer = static fn () => Response::error(
            'This picture is larger than this server accepts (' . UploadFiles::megabytes($serverLimit) . '). Use a smaller picture, or ask your host to raise upload_max_filesize and post_max_size to at least 6M.',
            413,
            'FILE_TOO_LARGE_FOR_SERVER'
        );
        if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
            if ($serverLimit > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > UploadFiles::iniBytes((string) ini_get('post_max_size'))) {
                $tooBigForServer();
            }
            Response::error('No image file provided in request. Expected form field "image".', 400, 'NO_FILE_UPLOADED');
        }

        $file = $_FILES['image'];

        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $tooBigForServer();
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $reason = match ($file['error']) {
                UPLOAD_ERR_PARTIAL => 'the upload was interrupted; please try again',
                UPLOAD_ERR_NO_FILE => 'no file was received',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'the server could not save the file (temporary folder missing or full); tell your host',
                default => 'error code ' . $file['error'],
            };
            Response::error('The picture could not be uploaded: ' . $reason . '.', 400, 'UPLOAD_ERROR');
        }

        // Raster formats only: SVG can carry script and is served from our own origin.
        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        // getimagesize() reads the file's own bytes and is always in PHP. Some shared hosts have no
        // fileinfo extension, so uploads must not depend on it.
        $mime = (string) (@getimagesize($file['tmp_name'])['mime'] ?? '');

        if (!isset($allowedMimes[$mime])) {
            Response::error('Invalid image format. Allowed formats: JPEG, PNG, WebP.', 422, 'INVALID_IMAGE_TYPE');
        }

        if ($file['size'] > UploadFiles::MAX_PICTURE_BYTES) {
            Response::error('Image exceeds maximum permitted file size of 5MB.', 422, 'FILE_TOO_LARGE');
        }

        // Product photos are public: never publish the camera's GPS location, device or owner.
        ImageMetadata::strip($file['tmp_name'], $mime);
        clearstatcache(true, $file['tmp_name']);
        $size = (int) (@filesize($file['tmp_name']) ?: $file['size']);

        // A server-made key in whichever storage holds pictures; the shop always links to
        // /uploads/<key> (proxied by Next.js), so new images work without rebuilding the frontend.
        $key = StorageKey::forUpload($allowedMimes[$mime]);
        try {
            $storage = Storage::uploads();
            $storage->put($key, $file['tmp_name'], $mime, StorageKey::cacheControl($key));
        } catch (StorageError $e) {
            error_log('[Upload] ' . $e->getMessage());
            Response::error('Failed to save the uploaded image.', 500, 'FILE_SAVE_FAILED');
        }

        Response::success([
            'image_url' => $storage->url($key),
            'file_name' => $key,
            'mime_type' => $mime,
            'size_bytes' => $size,
        ], 'Image uploaded successfully.', 201);
    }
}
