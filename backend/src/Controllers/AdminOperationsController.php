<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Database\Database;
use App\Middleware\JsonBodyMiddleware;
use App\Repositories\ProductRepository;
use App\Repositories\SessionRepository;
use App\Services\AuditService;
use App\Services\FulfillmentService;
use App\Services\MailService;
use App\Services\PaymentMethods;
use App\Support\DevSwitches;
use App\Support\Input;
use App\Support\PhoneNumber;
use App\Support\RateLimiter;
use App\Support\RequestContext;
use App\Support\Response;
use App\Support\SessionCookie;
use App\Support\SessionGuard;
use App\Support\StoreBrands;
use App\Storage\Storage;
use App\Support\Redis;
use App\Support\ProductRatings;
use App\Support\StorefrontRefresh;
use App\Support\UploadFiles;

/**
 * Administrative operations: inventory, customers, payments, refunds, promotions, storefront
 * content, email outbox, audit logs, settings, system status, marketing and the admin profile.
 * Every list endpoint is paginated (?page=&per_page=) and returns {items, pagination}.
 */
class AdminOperationsController
{
    private Database $db;

    /** Store settings the admin can edit, with what each one controls. */
    private const EDITABLE_SETTINGS = [
        'store_name' => 'Store name shown in emails and receipts',
        'store_contact_email' => 'Customer support email (shown at checkout and in emails)',
        'admin_alert_email' => 'Where new-order, payment and storage alerts go. One or more addresses separated by commas. Leave empty to send them to every active admin account.',
        'store_contact_phone' => 'Shown to customers at checkout, in emails and in the footer',
        'store_whatsapp_phone' => 'WhatsApp number for payments and support. 080…, 070… and 090… numbers are saved as +234…',
        'store_origin_address' => 'Dispatch address (where parcels ship from)',
        'store_origin_city' => 'City parcels ship from (shown on receipts)',
        'store_origin_state' => 'State parcels ship from',
        'storefront_brands' => 'Brands in the scrolling strip under the homepage banner, separated by commas (only brands you sell). Leave empty to hide the strip.',
        'max_unpaid_orders' => 'How many unpaid orders one customer can hold at once (counted by account, email, phone and device). Their items are reserved, so this stops stock being held by orders nobody pays for. 0 means no limit.',
        'default_product_rating' => 'Star rating a product starts with when you leave its own starting rating empty, from 1 to 5 (e.g. 4.5). It counts like 5 reviews, so customer reviews take over as they come in. Leave empty to show stars only from customer reviews.',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @return array{0: int, 1: int, 2: int} page, per_page, offset
     */
    private function paging(int $defaultPerPage = 20): array
    {
        $page = Input::page($_GET['page'] ?? 1);
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? $defaultPerPage)));
        return [$page, $perPage, ($page - 1) * $perPage];
    }

    /**
     * Run a filtered list query with LIMIT/OFFSET plus a matching COUNT(*).
     */
    private function paginate(string $selectSql, string $countSql, string $types, array $params, string $orderBy, string $message, int $defaultPerPage = 20): void
    {
        [$page, $perPage, $offset] = $this->paging($defaultPerPage);
        $total = (int) ($this->db->fetchOne($countSql, $types, $params)['total'] ?? 0);
        $items = $this->db->fetchAll(
            "{$selectSql} ORDER BY {$orderBy} LIMIT ? OFFSET ?",
            $types . 'ii',
            array_merge($params, [$perPage, $offset])
        );
        Response::paginated($items, $total, $page, $perPage, $message);
    }

    private function adminId(): ?int
    {
        $admin = RequestContext::getUser();
        return $admin ? (int) $admin['id'] : null;
    }

    // =========================================================================
    // 1. INVENTORY
    // =========================================================================

    /**
     * GET /api/v1/admin/inventory?filter=all|low_stock|out_of_stock|low_or_out&search=
     * The stock-level filters count like the overview: products on sale only (not archived).
     */
    public function getInventory(): void
    {
        $filter = $_GET['filter'] ?? 'all';
        $search = Input::singleSpaced($_GET['search'] ?? '');

        $where = " WHERE v.is_active = 1";
        $types = '';
        $params = [];

        if ($filter === 'low_stock') {
            $where .= " AND p.status <> 'archived' AND v.stock_quantity <= v.low_stock_threshold AND v.stock_quantity > 0";
        } elseif ($filter === 'out_of_stock') {
            $where .= " AND p.status <> 'archived' AND v.stock_quantity = 0";
        } elseif ($filter === 'low_or_out') {
            $where .= " AND p.status <> 'archived' AND v.stock_quantity <= v.low_stock_threshold";
        }
        if ($search !== '') {
            $where .= " AND (p.name LIKE ? OR v.sku LIKE ?)";
            $types .= 'ss';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $from = " FROM `product_variants` v JOIN `products` p ON p.id = v.product_id";
        $this->paginate(
            "SELECT v.id, v.product_id, p.name AS product_name, p.slug AS product_slug, p.status AS product_status,
                    v.sku, v.size, v.color_name, v.color_hex, v.stock_quantity, v.low_stock_threshold,
                    v.price_override, p.base_price, v.updated_at" . $from . $where,
            "SELECT COUNT(*) AS total" . $from . $where,
            $types,
            $params,
            "v.stock_quantity ASC, p.name ASC",
            'Inventory stock list retrieved.',
            25
        );
    }

    /**
     * GET /api/v1/admin/inventory/logs?variant_id=
     */
    public function getInventoryLogs(): void
    {
        $where = " WHERE 1=1";
        $types = '';
        $params = [];
        if (!empty($_GET['variant_id'])) {
            $where .= " AND l.variant_id = ?";
            $types .= 'i';
            $params[] = (int) $_GET['variant_id'];
        }

        $from = " FROM `inventory_logs` l
                  JOIN `products` p ON p.id = l.product_id
                  JOIN `product_variants` v ON v.id = l.variant_id
                  LEFT JOIN `users` u ON u.id = l.adjusted_by_user_id";
        $this->paginate(
            "SELECT l.*, p.name AS product_name, v.sku AS variant_sku, v.size, v.color_name, u.full_name AS adjusted_by_name" . $from . $where,
            "SELECT COUNT(*) AS total" . $from . $where,
            $types,
            $params,
            "l.created_at DESC, l.id DESC",
            'Inventory movement logs retrieved.',
            25
        );
    }

    // =========================================================================
    // 2. CUSTOMERS
    // =========================================================================

    /** Money actually received from a customer (verified payments, before refunds). */
    private const PAID_ORDER_CONDITION = "o.payment_status IN ('successful', 'partially_refunded') AND o.order_status NOT IN ('cancelled', 'refunded')";

    /**
     * GET /api/v1/admin/customers?search=&status=
     */
    public function listCustomers(): void
    {
        $search = trim((string) ($_GET['search'] ?? ''));
        $status = $_GET['status'] ?? 'all';

        $where = " WHERE u.role = 'customer'";
        $types = '';
        $params = [];
        if ($status === 'unverified') {
            // Sign-up saves customers as active; "not verified" is whether they confirmed their email.
            $where .= " AND u.email_verified_at IS NULL";
        } elseif (in_array($status, ['active', 'suspended'], true)) {
            $where .= " AND u.status = ?";
            $types .= 's';
            $params[] = $status;
        }
        if ($search !== '') {
            // A phone number is found however it was typed; otherwise this repeats the plain search.
            $phone = \App\Support\PhoneNumber::searchDigits($search) ?? $search;
            $where .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.phone LIKE ?)";
            $types .= 'ssss';
            array_push($params, "%{$search}%", "%{$search}%", "%{$search}%", "%{$phone}%");
        }

        $this->paginate(
            "SELECT u.id, u.uuid, u.full_name, u.email, u.phone, u.status, u.email_verified_at, u.created_at,
                    (SELECT COUNT(*) FROM `orders` o WHERE o.user_id = u.id) AS total_orders,
                    (SELECT COALESCE(SUM(o.grand_total), 0) FROM `orders` o WHERE o.user_id = u.id AND " . self::PAID_ORDER_CONDITION . ") AS lifetime_spend
             FROM `users` u" . $where,
            "SELECT COUNT(*) AS total FROM `users` u" . $where,
            $types,
            $params,
            "u.created_at DESC",
            'Customers list retrieved.'
        );
    }

    /**
     * GET /api/v1/admin/customers/{id}
     */
    public function getCustomerDetail(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $customer = $this->db->fetchOne(
            "SELECT id, uuid, full_name, email, phone, status, email_verified_at, created_at, updated_at
             FROM `users` WHERE `id` = ? AND `role` = 'customer'",
            'i',
            [$id]
        );
        if (!$customer) {
            Response::error('Customer not found.', 404, 'NOT_FOUND');
        }

        $addresses = $this->db->fetchAll("SELECT * FROM `addresses` WHERE `user_id` = ? ORDER BY is_default DESC, id ASC", 'i', [$id]);
        $orders = $this->db->fetchAll(
            "SELECT id, order_number, grand_total, currency, order_status, payment_status, created_at
             FROM `orders` WHERE `user_id` = ? ORDER BY created_at DESC LIMIT 50",
            'i',
            [$id]
        );
        $summary = $this->db->fetchOne(
            "SELECT COUNT(*) AS total_orders,
                    COALESCE(SUM(CASE WHEN " . self::PAID_ORDER_CONDITION . " THEN o.grand_total ELSE 0 END), 0) AS total_spend
             FROM `orders` o WHERE o.user_id = ?",
            'i',
            [$id]
        );

        Response::success([
            'customer' => $customer,
            'summary' => $summary,
            'addresses' => $addresses,
            'orders' => $orders,
        ], 'Customer details retrieved.');
    }

    /**
     * PATCH /api/v1/admin/customers/{id}/status  {status: active|suspended}
     * Suspending also signs the customer out everywhere.
     */
    public function updateCustomerStatus(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $status = (string) (JsonBodyMiddleware::getBody()['status'] ?? '');

        if (!in_array($status, ['active', 'suspended'], true)) {
            Response::error("Status must be 'active' or 'suspended'.", 422, 'INVALID_STATUS');
        }

        $customer = $this->db->fetchOne("SELECT id, status FROM `users` WHERE `id` = ? AND `role` = 'customer'", 'i', [$id]);
        if (!$customer) {
            Response::error('Customer not found.', 404, 'NOT_FOUND');
        }

        $this->db->executeUpdate("UPDATE `users` SET `status` = ? WHERE `id` = ?", 'si', [$status, $id]);
        if ($status === 'suspended') {
            (new SessionRepository())->destroyUserSessions($id);
        }

        AuditService::record($this->adminId(), 'customer.status_update', 'customer', (string) $id, ['status' => $customer['status']], ['status' => $status]);
        Response::success(['id' => $id, 'status' => $status], $status === 'suspended' ? 'Customer suspended and signed out.' : 'Customer reactivated.');
    }

    // =========================================================================
    // 3. PAYMENTS
    // =========================================================================

    /**
     * GET /api/v1/admin/payments?gateway=&status=&search=
     */
    public function listPayments(): void
    {
        $gateway = $_GET['gateway'] ?? 'all';
        $status = $_GET['status'] ?? 'all';
        $search = trim((string) ($_GET['search'] ?? ''));

        $where = " WHERE 1=1";
        $types = '';
        $params = [];
        if ($gateway !== 'all' && $gateway !== '') {
            $where .= " AND p.provider = ?";
            $types .= 's';
            $params[] = $gateway;
        }
        if ($status !== 'all' && $status !== '') {
            $where .= " AND p.status = ?";
            $types .= 's';
            $params[] = $status;
        }
        if ($search !== '') {
            $where .= " AND (p.provider_reference LIKE ? OR o.order_number LIKE ? OR o.guest_email LIKE ? OR u.email LIKE ?)";
            $types .= 'ssss';
            array_push($params, "%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%");
        }

        $from = " FROM `payments` p
                  LEFT JOIN `orders` o ON o.id = p.order_id
                  LEFT JOIN `users` u ON u.id = o.user_id";
        $this->paginate(
            "SELECT p.id, p.provider_reference AS payment_reference, p.provider AS gateway, p.amount, p.amount_received,
                    p.currency, p.payment_method, p.status, p.verified_at, p.created_at, p.updated_at,
                    o.order_number, o.order_status, o.payment_status AS order_payment_status, o.requires_attention,
                    COALESCE(u.full_name, JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address_snapshot, '$.full_name'))) AS customer_name,
                    COALESCE(u.email, o.guest_email) AS customer_email" . $from . $where,
            "SELECT COUNT(*) AS total" . $from . $where,
            $types,
            $params,
            "p.created_at DESC, p.id DESC",
            'Payment transactions retrieved.'
        );
    }

    // =========================================================================
    // 4. REFUNDS
    // =========================================================================

    /**
     * GET /api/v1/admin/refunds?status=
     */
    public function listRefunds(): void
    {
        $status = $_GET['status'] ?? 'all';
        $where = " WHERE 1=1";
        $types = '';
        $params = [];
        if (in_array($status, ['pending', 'completed', 'rejected'], true)) {
            $where .= " AND r.status = ?";
            $types .= 's';
            $params[] = $status;
        }

        $from = " FROM `refunds` r
                  JOIN `orders` o ON o.id = r.order_id
                  JOIN `payments` p ON p.id = r.payment_id
                  LEFT JOIN `users` u ON u.id = o.user_id
                  LEFT JOIN `users` admin_u ON admin_u.id = r.processed_by_user_id";
        $this->paginate(
            "SELECT r.*, o.order_number,
                    COALESCE(u.full_name, JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address_snapshot, '$.full_name'))) AS customer_name,
                    COALESCE(u.email, o.guest_email) AS customer_email,
                    p.provider_reference AS payment_reference, p.provider AS gateway,
                    admin_u.full_name AS processed_by_name" . $from . $where,
            "SELECT COUNT(*) AS total" . $from . $where,
            $types,
            $params,
            "r.created_at DESC, r.id DESC",
            'Refund records retrieved.'
        );
    }

    /**
     * GET /api/v1/admin/refunds/eligibility?order_number=
     * What can still be refunded on an order (for the refund form).
     */
    public function refundEligibility(): void
    {
        $orderNumber = trim((string) ($_GET['order_number'] ?? ''));
        $order = $this->db->fetchOne("SELECT id, order_number, order_status, payment_status, grand_total, currency FROM `orders` WHERE `order_number` = ?", 's', [$orderNumber]);
        if (!$order) {
            Response::error('Order not found.', 404, 'ORDER_NOT_FOUND');
        }

        // All the money on the order, including payments already refunded. A refund takes one
        // payment at a time, so refundable_amount is the most the next one can be (and
        // payment_reference the payment it goes against); refundable_total is everything left.
        $money = (new FulfillmentService($this->db))->moneyOnOrder($this->db, (int) $order['id']);
        $sum = static fn (string $key): float => round(array_sum(array_column($money, $key)), 2);
        $withBalance = array_values(array_filter($money, static fn (array $p): bool => $p['status'] !== 'refunded' && $p['left'] > 0.005));
        $payment = $withBalance[0] ?? ($money[0] ?? null);

        Response::success([
            'order_number' => $order['order_number'],
            'order_status' => $order['order_status'],
            'payment_status' => $order['payment_status'],
            'currency' => $order['currency'],
            'order_total' => (float) $order['grand_total'],
            'paid_amount' => $sum('received'),
            'refunded_amount' => $sum('refunded'),
            'pending_refund_amount' => $sum('pending_refunds'),
            'refundable_amount' => $withBalance === [] ? 0.0 : (float) $withBalance[0]['left'],
            'refundable_total' => round(array_sum(array_column($withBalance, 'left')), 2),
            'payment_reference' => $payment['provider_reference'] ?? null,
            'gateway' => $payment['provider'] ?? null,
            'can_restock' => in_array($order['order_status'], ['paid', 'processing', 'returned', 'refund_pending'], true),
        ], 'Refund eligibility retrieved.');
    }

    /**
     * POST /api/v1/admin/refunds
     * Record a refund against the order's money, the newest payment first. Capped at what that
     * payment has left. status 'completed' = money already returned via the gateway
     * dashboard or bank; 'pending' = approved, to be completed later.
     */
    public function createRefund(): void
    {
        $body = JsonBodyMiddleware::getBody();
        $orderNumber = Input::text($body['order_number'] ?? '');
        $amount = is_numeric($body['amount'] ?? null) ? (float) $body['amount'] : 0.0;
        $reason = Input::text($body['reason'] ?? '');
        $status = strtolower(Input::text($body['status'] ?? 'completed'));
        $restock = filter_var($body['restock'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $providerRef = isset($body['provider_refund_reference']) ? Input::text($body['provider_refund_reference']) : null;

        if ($orderNumber === '' || $amount <= 0 || $reason === '') {
            Response::error('Order number, amount, and reason are required.', 422, 'VALIDATION_ERROR');
        }

        $order = $this->db->fetchOne("SELECT `id` FROM `orders` WHERE `order_number` = ?", 's', [$orderNumber]);
        if (!$order) {
            Response::error('Order not found.', 404, 'ORDER_NOT_FOUND');
        }

        try {
            $refund = (new FulfillmentService($this->db))->recordRefund(
                (int) $order['id'], $amount, $reason, $status, $restock, $this->adminId(), $providerRef
            );
            Response::success($refund, $refund['status'] === 'completed'
                ? 'Refund recorded as completed.'
                : 'Refund recorded as pending. Mark it completed once the money has been returned.', 201);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'INVALID_REFUND');
        }
    }

    /**
     * PATCH /api/v1/admin/refunds/{id}  {status: completed|rejected}
     */
    public function resolveRefund(array $params): void
    {
        $body = JsonBodyMiddleware::getBody();
        try {
            $refund = (new FulfillmentService($this->db))->resolveRefund(
                (int) ($params['id'] ?? 0),
                strtolower(trim((string) ($body['status'] ?? ''))),
                $this->adminId()
            );
            Response::success($refund, "Refund marked {$refund['status']}.");
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'INVALID_REFUND');
        }
    }

    // =========================================================================
    // 5. PROMOTIONS
    // =========================================================================

    public function listPromotions(): void
    {
        [$page, $perPage, $offset] = $this->paging(20);
        $total = (int) ($this->db->fetchOne("SELECT COUNT(*) AS total FROM `promotions`")['total'] ?? 0);
        $promotions = $this->db->fetchAll(
            "SELECT * FROM `promotions` ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
            'ii',
            [$perPage, $offset]
        );
        $advertisedId = (int) ($this->db->fetchOne('SELECT `id` FROM `promotions` WHERE ' . \App\Services\CouponRules::ADVERTISED_SQL)['id'] ?? 0);
        foreach ($promotions as &$p) {
            $p['is_active'] = (bool) $p['is_active'];
            $p['show_on_homepage'] = (bool) $p['show_on_homepage'];
            $p['on_homepage_now'] = (int) $p['id'] === $advertisedId;
            $p['discount_percent'] = (int) $p['discount_percent'];
            $p['discount_type'] = \App\Services\CouponRules::type($p);
            $p['discount_amount'] = $p['discount_amount'] === null ? null : (float) $p['discount_amount'];
            $p['min_order_amount'] = $p['min_order_amount'] === null ? null : (float) $p['min_order_amount'];
            $p['max_discount_amount'] = $p['max_discount_amount'] === null ? null : (float) $p['max_discount_amount'];
            $p['label'] = \App\Services\CouponRules::label($p);
            $p['max_uses'] = (int) $p['max_uses'];
            $p['used_count'] = (int) $p['used_count'];
        }
        unset($p);
        Response::paginated($promotions, $total, $page, $perPage, 'Promotions list retrieved.');
    }

    /**
     * Validate promotion input. Dates are YYYY-MM-DD; a promotion starts at the beginning of
     * its start day and ends at the end of its expiry day.
     *
     * @return array<string, mixed>
     */
    private function promotionInput(array $body, ?array $existing = null): array
    {
        $code = array_key_exists('code', $body) ? strtoupper(Input::text($body['code'])) : ($existing['code'] ?? '');
        $description = array_key_exists('description', $body) ? Input::text($body['description']) : ($existing['description'] ?? '');
        $type = array_key_exists('discount_type', $body) ? Input::text($body['discount_type']) : (string) ($existing['discount_type'] ?? 'percent');
        $percent = array_key_exists('discount_percent', $body) ? (Input::wholeNumber($body['discount_percent'], 0, 100) ?? -1) : (int) ($existing['discount_percent'] ?? 0);
        // Money fields: empty means "none"; kept from the stored code when left out.
        $money = static function (array $body, ?array $existing, string $key): ?float {
            $value = array_key_exists($key, $body) ? $body[$key] : ($existing[$key] ?? null);
            return $value === null || $value === '' ? null : (is_numeric($value) ? round((float) $value, 2) : -1.0);
        };
        $amount = $money($body, $existing, 'discount_amount');
        $minOrder = $money($body, $existing, 'min_order_amount');
        $maxDiscount = $money($body, $existing, 'max_discount_amount');
        // Empty means unlimited, as 0 does.
        $maxUses = array_key_exists('max_uses', $body)
            ? (in_array($body['max_uses'], [null, ''], true) ? 0 : Input::wholeNumber($body['max_uses'], 0, Input::MAX_PROMO_USES))
            : (int) ($existing['max_uses'] ?? 0);
        $isActive = array_key_exists('is_active', $body) ? (int) (bool) $body['is_active'] : (int) ($existing['is_active'] ?? 1);
        // Private unless the owner ticks it.
        $showOnHomepage = array_key_exists('show_on_homepage', $body) ? (int) (bool) $body['show_on_homepage'] : (int) ($existing['show_on_homepage'] ?? 0);

        $date = static function ($value, string $time): ?string {
            if ($value === null || $value === '') {
                return null;
            }
            $ts = strtotime(substr(Input::text($value), 0, 10) . ' ' . $time);
            return $ts === false ? 'invalid' : date('Y-m-d H:i:s', $ts);
        };
        $startsAt = array_key_exists('starts_at', $body) ? $date($body['starts_at'], '00:00:00') : ($existing['starts_at'] ?? null);
        $expiresAt = array_key_exists('expires_at', $body) ? $date($body['expires_at'], '23:59:59') : ($existing['expires_at'] ?? null);

        $errors = [];
        if (!preg_match('/^[A-Z0-9_-]{3,30}$/', $code)) {
            $errors['code'] = 'Use 3-30 letters, numbers, dashes or underscores.';
        }
        if (mb_strlen((string) $description) > 255) {
            $errors['description'] = 'Keep the description to 255 characters.';
        }
        if (!in_array($type, ['percent', 'fixed'], true)) {
            $errors['discount_type'] = 'Choose a percentage or a fixed amount.';
        } elseif ($type === 'percent') {
            if ($percent < 1 || $percent > 90) {
                $errors['discount_percent'] = 'Discount must be between 1% and 90%.';
            }
            $amount = null;
            if ($maxDiscount !== null && $maxDiscount <= 0) {
                $errors['max_discount_amount'] = 'Leave empty for no cap, or enter an amount above 0.';
            }
        } else {
            if ($amount === null || $amount <= 0) {
                $errors['discount_amount'] = 'Enter the amount to take off (above 0).';
            }
            $percent = 0;
            $maxDiscount = null; // a fixed amount is its own cap
        }
        if ($minOrder !== null && $minOrder <= 0) {
            $errors['min_order_amount'] = 'Leave empty for no minimum, or enter an amount above 0.';
        }
        // A fixed amount needs a minimum order above it, so an order is never brought to ₦0
        // (the payment gateways cannot charge nothing).
        if ($type === 'fixed' && !isset($errors['discount_amount']) && !isset($errors['min_order_amount'])) {
            if ($minOrder === null) {
                $errors['min_order_amount'] = 'A fixed-amount code needs a minimum order above the amount off.';
            } elseif ($amount !== null && $amount >= $minOrder) {
                $errors['discount_amount'] = 'The amount off must be less than the minimum order.';
            }
        }
        foreach (['discount_amount' => $amount, 'min_order_amount' => $minOrder, 'max_discount_amount' => $maxDiscount] as $field => $value) {
            if ($value !== null && $value > Input::MAX_MONEY) {
                $errors[$field] = 'Enter at most ' . Input::maxMoneyLabel() . '.';
            }
        }
        if ($maxUses === null) {
            $errors['max_uses'] = 'Use 0 for unlimited, or a whole number up to ' . number_format(Input::MAX_PROMO_USES) . '.';
        }
        if ($startsAt === 'invalid') {
            $errors['starts_at'] = 'Invalid start date.';
        }
        if ($expiresAt === 'invalid') {
            $errors['expires_at'] = 'Invalid end date.';
        }
        if (empty($errors) && $startsAt && $expiresAt && strtotime($expiresAt) < strtotime($startsAt)) {
            $errors['expires_at'] = 'The end date must be after the start date.';
        }
        if (!empty($errors)) {
            Response::error('Please fix the highlighted fields.', 422, 'VALIDATION_ERROR', $errors);
        }

        return compact('code', 'description', 'type', 'percent', 'amount', 'minOrder', 'maxDiscount', 'maxUses', 'isActive', 'showOnHomepage', 'startsAt', 'expiresAt');
    }

    public function createPromotion(): void
    {
        $in = $this->promotionInput(JsonBodyMiddleware::getBody());

        if ($this->db->fetchOne("SELECT id FROM `promotions` WHERE `code` = ?", 's', [$in['code']])) {
            Response::error("Promotion code '{$in['code']}' already exists.", 422, 'CODE_EXISTS');
        }

        $id = (int) $this->db->insert(
            "INSERT INTO `promotions` (`code`, `description`, `discount_type`, `discount_percent`, `discount_amount`, `min_order_amount`, `max_discount_amount`, `max_uses`, `is_active`, `show_on_homepage`, `starts_at`, `expires_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'sssidddiiiss',
            [$in['code'], $in['description'], $in['type'], $in['percent'], $in['amount'], $in['minOrder'], $in['maxDiscount'], $in['maxUses'], $in['isActive'], $in['showOnHomepage'], $in['startsAt'], $in['expiresAt']]
        );

        AuditService::record($this->adminId(), 'promotion.create', 'promotion', (string) $id, null, $in);
        Response::success(['id' => $id, 'code' => $in['code']], 'Promotion created.', 201);
    }

    public function updatePromotion(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $promo = $this->db->fetchOne("SELECT * FROM `promotions` WHERE `id` = ?", 'i', [$id]);
        if (!$promo) {
            Response::error('Promotion not found.', 404, 'NOT_FOUND');
        }

        $in = $this->promotionInput(JsonBodyMiddleware::getBody(), $promo);
        // Orders refer to a code by its text: renaming a used one would lose its use count when
        // those orders are later cancelled.
        if (strcasecmp((string) $in['code'], (string) $promo['code']) !== 0 && (int) $promo['used_count'] > 0) {
            $message = "Code {$promo['code']} has already been used, so it can't be renamed. Create a new code instead.";
            Response::error($message, 422, 'CODE_IN_USE', ['code' => $message]);
        }
        if ($in['code'] !== $promo['code'] && $this->db->fetchOne("SELECT id FROM `promotions` WHERE `code` = ? AND id <> ?", 'si', [$in['code'], $id])) {
            Response::error("Promotion code '{$in['code']}' already exists.", 422, 'CODE_EXISTS');
        }

        $this->db->executeUpdate(
            "UPDATE `promotions`
             SET `code` = ?, `description` = ?, `discount_type` = ?, `discount_percent` = ?, `discount_amount` = ?, `min_order_amount` = ?,
                 `max_discount_amount` = ?, `max_uses` = ?, `is_active` = ?, `show_on_homepage` = ?, `starts_at` = ?, `expires_at` = ?
             WHERE `id` = ?",
            'sssidddiiissi',
            [$in['code'], $in['description'], $in['type'], $in['percent'], $in['amount'], $in['minOrder'], $in['maxDiscount'], $in['maxUses'], $in['isActive'], $in['showOnHomepage'], $in['startsAt'], $in['expiresAt'], $id]
        );

        AuditService::record($this->adminId(), 'promotion.update', 'promotion', (string) $id, $promo, $in);
        Response::success(['id' => $id], 'Promotion updated.');
    }

    /**
     * DELETE /api/v1/admin/promotions/{id}
     * A code that has been used is deactivated (kept for order history); an unused one is deleted.
     */
    public function deletePromotion(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $promo = $this->db->fetchOne("SELECT * FROM `promotions` WHERE `id` = ?", 'i', [$id]);
        if (!$promo) {
            Response::error('Promotion not found.', 404, 'NOT_FOUND');
        }

        $used = (int) $promo['used_count'] > 0
            || $this->db->fetchOne("SELECT 1 AS x FROM `orders` WHERE `applied_promo_code` = ? LIMIT 1", 's', [$promo['code']]) !== null;

        if ($used) {
            $this->db->executeUpdate("UPDATE `promotions` SET `is_active` = 0 WHERE `id` = ?", 'i', [$id]);
            AuditService::record($this->adminId(), 'promotion.deactivate', 'promotion', (string) $id, $promo, ['is_active' => 0]);
            Response::success(['id' => $id, 'deleted' => false], 'This code has been used on orders, so it was deactivated instead of deleted.');
        }

        $this->db->executeUpdate("DELETE FROM `promotions` WHERE `id` = ?", 'i', [$id]);
        AuditService::record($this->adminId(), 'promotion.delete', 'promotion', (string) $id, $promo, null);
        Response::success(['id' => $id, 'deleted' => true], 'Promotion deleted.');
    }

    // =========================================================================
    // 6. STOREFRONT BANNERS & REVIEWS
    // =========================================================================

    public function listBanners(): void
    {
        Response::success($this->db->fetchAll("SELECT * FROM `banners` ORDER BY display_order ASC, id ASC"), 'Banners retrieved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function bannerInput(array $body, ?array $existing = null): array
    {
        $get = static fn (string $k, $default = '') => array_key_exists($k, $body) ? Input::text($body[$k]) : ($existing[$k] ?? $default);
        $order = array_key_exists('display_order', $body)
            ? (in_array($body['display_order'], [null, ''], true) ? 0 : Input::wholeNumber($body['display_order'], 0, Input::MAX_DISPLAY_ORDER))
            : (int) ($existing['display_order'] ?? 1);
        $in = [
            'title' => $get('title'),
            'subtitle' => $get('subtitle'),
            'cta_text' => $get('cta_text', 'Shop Now'),
            'cta_url' => $get('cta_url', '/category'),
            'image_url' => $get('image_url'),
            'is_active' => array_key_exists('is_active', $body) ? (int) (bool) $body['is_active'] : (int) ($existing['is_active'] ?? 1),
            'display_order' => $order,
        ];

        $errors = [];
        if ($in['title'] === '') {
            $errors['title'] = 'Title is required.';
        }
        if ($in['image_url'] === '' || !preg_match('#^(/|https://)#', $in['image_url'])) {
            $errors['image_url'] = 'Upload an image or use an https:// image URL.';
        }
        if ($in['cta_url'] !== '' && !preg_match('#^(/(?!/)|https://)#', $in['cta_url'])) {
            $errors['cta_url'] = 'Link must be a store path like /category or an https:// URL.';
        }
        foreach (['title' => 255, 'subtitle' => 255, 'cta_text' => 50, 'cta_url' => 255, 'image_url' => 500] as $field => $max) {
            if (Input::tooLong($in[$field], $max)) {
                $errors[$field] = "Keep this to {$max} characters.";
            }
        }
        if ($order === null) {
            $errors['display_order'] = 'Display order must be a whole number from 0 to ' . number_format(Input::MAX_DISPLAY_ORDER) . '.';
        }
        if (!empty($errors)) {
            Response::error('Please fix the highlighted fields.', 422, 'VALIDATION_ERROR', $errors);
        }
        return $in;
    }

    public function createBanner(): void
    {
        $in = $this->bannerInput(JsonBodyMiddleware::getBody());
        $id = (int) $this->db->insert(
            "INSERT INTO `banners` (`title`, `subtitle`, `cta_text`, `cta_url`, `image_url`, `is_active`, `display_order`)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            'sssssii',
            [$in['title'], $in['subtitle'], $in['cta_text'], $in['cta_url'], $in['image_url'], $in['is_active'], $in['display_order']]
        );
        AuditService::record($this->adminId(), 'banner.create', 'banner', (string) $id, null, $in);
        Response::success(['id' => $id], 'Banner created.', 201);
    }

    public function updateBanner(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $banner = $this->db->fetchOne("SELECT * FROM `banners` WHERE `id` = ?", 'i', [$id]);
        if (!$banner) {
            Response::error('Banner not found.', 404, 'NOT_FOUND');
        }
        $in = $this->bannerInput(JsonBodyMiddleware::getBody(), $banner);
        $this->db->executeUpdate(
            "UPDATE `banners` SET `title` = ?, `subtitle` = ?, `cta_text` = ?, `cta_url` = ?, `image_url` = ?, `is_active` = ?, `display_order` = ? WHERE `id` = ?",
            'sssssiii',
            [$in['title'], $in['subtitle'], $in['cta_text'], $in['cta_url'], $in['image_url'], $in['is_active'], $in['display_order'], $id]
        );
        AuditService::record($this->adminId(), 'banner.update', 'banner', (string) $id, $banner, $in);
        Response::success(['id' => $id], 'Banner updated.');
    }

    public function deleteBanner(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $banner = $this->db->fetchOne("SELECT * FROM `banners` WHERE `id` = ?", 'i', [$id]);
        if ($banner === null) {
            Response::error('Banner not found.', 404, 'NOT_FOUND');
        }
        $this->db->executeUpdate("DELETE FROM `banners` WHERE `id` = ?", 'i', [$id]);
        AuditService::record($this->adminId(), 'banner.delete', 'banner', (string) $id, $banner, null);
        Response::success(['id' => $id], 'Banner deleted.');
    }

    public function listReviews(): void
    {
        $status = $_GET['status'] ?? 'all';
        $where = " WHERE 1=1";
        $types = '';
        $params = [];
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $where .= " AND r.status = ?";
            $types .= 's';
            $params[] = $status;
        }
        $from = " FROM `reviews` r JOIN `products` p ON p.id = r.product_id";
        $this->paginate(
            "SELECT r.*, p.name AS product_name, p.slug AS product_slug" . $from . $where,
            "SELECT COUNT(*) AS total" . $from . $where,
            $types,
            $params,
            "r.created_at DESC",
            'Reviews list retrieved.'
        );
    }

    public function updateReviewStatus(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $status = (string) (JsonBodyMiddleware::getBody()['status'] ?? '');
        if (!in_array($status, ['approved', 'rejected', 'pending'], true)) {
            Response::error('Invalid review status.', 422, 'VALIDATION_ERROR');
        }

        $review = $this->db->fetchOne("SELECT `product_id`, `status` FROM `reviews` WHERE `id` = ?", 'i', [$id]);
        if ($review === null) {
            Response::error('Review not found.', 404, 'NOT_FOUND');
        }

        $this->db->executeUpdate("UPDATE `reviews` SET `status` = ? WHERE `id` = ?", 'si', [$status, $id]);
        // Keep the product's displayed rating in line with its approved reviews.
        (new ProductRepository())->refreshRating((int) $review['product_id']);
        AuditService::record($this->adminId(), 'review.moderate', 'review', (string) $id, ['status' => $review['status']], ['status' => $status]);

        Response::success(['id' => $id, 'status' => $status], "Review {$status}.");
    }

    // =========================================================================
    // 7. EMAIL OUTBOX
    // =========================================================================

    /** Email outbox health: used by the Email outbox page (always open) and System status. */
    private function mailStatus(): array
    {
        $outbox = $this->db->fetchOne(
            "SELECT COALESCE(SUM(status = 'pending'), 0) AS pending, COALESCE(SUM(status = 'failed'), 0) AS failed FROM `email_outbox`"
        );
        return [
            'mode' => self::mailMode(),
            'outbox_pending' => (int) ($outbox['pending'] ?? 0),
            'outbox_failed' => (int) ($outbox['failed'] ?? 0),
            // A due email older than ~15 minutes means process_outbox.php is not scheduled.
            'oldest_due_minutes' => ($oldest = $this->db->fetchOne("SELECT TIMESTAMPDIFF(MINUTE, MIN(`created_at`), NOW()) AS m FROM `email_outbox` WHERE `status` = 'pending' AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= NOW())")) && $oldest['m'] !== null ? (int) $oldest['m'] : null,
        ];
    }

    public function outboxStatus(): void
    {
        Response::success($this->mailStatus(), 'Email outbox status retrieved.');
    }

    public function listOutbox(): void
    {
        $status = $_GET['status'] ?? 'all';
        $where = " WHERE 1=1";
        $types = '';
        $params = [];
        if (in_array($status, ['pending', 'sent', 'failed'], true)) {
            $where .= " AND `status` = ?";
            $types .= 's';
            $params[] = $status;
        }
        // Payloads can contain customer data; the list shows metadata only.
        $this->paginate(
            "SELECT `id`, `recipient_email`, `subject`, `template`, `status`, `transport`, `attempt_count`, `last_error`, `next_attempt_at`, `created_at`, `sent_at` FROM `email_outbox`" . $where,
            "SELECT COUNT(*) AS total FROM `email_outbox`" . $where,
            $types,
            $params,
            "`created_at` DESC, `id` DESC",
            'Email outbox retrieved.',
            25
        );
    }

    public function retryOutbox(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $result = MailService::getInstance()->retryOutboxItem($id);
        AuditService::record($this->adminId(), 'email.retry', 'email_outbox', (string) $id, null, ['ok' => $result['ok']]);
        if ($result['ok']) {
            Response::success(['id' => $id], $result['message']);
        }
        Response::error($result['message'], 422, 'RETRY_FAILED');
    }

    /**
     * POST /api/v1/admin/notifications/outbox/process
     * Deliver queued emails now (the same work as scripts/process_outbox.php, one batch).
     */
    public function processOutbox(): void
    {
        $summary = MailService::getInstance()->processQueue(50);
        AuditService::record($this->adminId(), 'email.process_queue', 'email_outbox', null, null, $summary);
        $remaining = (int) ($this->db->fetchOne("SELECT COUNT(*) AS c FROM `email_outbox` WHERE `status` = 'pending'")['c'] ?? 0);
        $slowDown = $summary['rate_limited'] > 0 ? ' The email service asked to slow down; the rest will be sent shortly.' : '';
        Response::success($summary + ['still_pending' => $remaining], ($summary['processed'] === 0 && $slowDown === ''
            ? 'Nothing was due to send.'
            : "Processed {$summary['processed']}: {$summary['sent']} sent, {$summary['retrying']} will retry, {$summary['failed']} failed.") . $slowDown);
    }

    // =========================================================================
    // 8. AUDIT LOGS
    // =========================================================================

    public function listAuditLogs(): void
    {
        $action = trim((string) ($_GET['action'] ?? ''));
        $entityType = trim((string) ($_GET['entity_type'] ?? ''));

        $where = " WHERE 1=1";
        $types = '';
        $params = [];
        if ($action !== '' && $action !== 'all') {
            $where .= " AND a.action = ?";
            $types .= 's';
            $params[] = $action;
        }
        if ($entityType !== '' && $entityType !== 'all') {
            $where .= " AND a.entity_type = ?";
            $types .= 's';
            $params[] = $entityType;
        }

        [$page, $perPage, $offset] = $this->paging(25);
        $from = " FROM `admin_audit_logs` a LEFT JOIN `users` u ON u.id = a.admin_id";
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) AS total" . $from . $where, $types, $params)['total'];
        $items = $this->db->fetchAll(
            "SELECT a.*, COALESCE(u.full_name, a.admin_label) AS admin_name, u.email AS admin_email" . $from . $where . " ORDER BY a.created_at DESC, a.id DESC LIMIT ? OFFSET ?",
            $types . 'ii',
            array_merge($params, [$perPage, $offset])
        );

        Response::success([
            'items' => $items,
            'pagination' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => (int) ceil($total / $perPage)],
            'filters' => [
                'actions' => array_column($this->db->fetchAll("SELECT DISTINCT `action` FROM `admin_audit_logs` ORDER BY `action`"), 'action'),
                'entity_types' => array_column($this->db->fetchAll("SELECT DISTINCT `entity_type` FROM `admin_audit_logs` ORDER BY `entity_type`"), 'entity_type'),
            ],
        ], 'Audit logs retrieved.', 200, ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => (int) ceil($total / $perPage)]);
    }

    // =========================================================================
    // 9. STORE SETTINGS
    // =========================================================================

    /**
     * GET /api/v1/admin/settings
     * Editable store details plus read-only configuration (set in backend/.env, never secrets).
     */
    public function getSettings(): void
    {
        $rows = $this->db->fetchAll("SELECT `key_name`, `value` FROM `store_settings`");
        $stored = array_column($rows, 'value', 'key_name');

        $editable = [];
        foreach (self::EDITABLE_SETTINGS as $key => $help) {
            $value = $stored[$key] ?? match ($key) {
                ProductRatings::SETTING => (string) ProductRatings::DEFAULT,
                \App\Services\CheckoutService::UNPAID_CAP_SETTING => (string) \App\Services\CheckoutService::UNPAID_CAP_DEFAULT,
                default => '',
            };
            $editable[] = ['key' => $key, 'value' => $value, 'help' => $help];
        }

        Response::success([
            'editable' => $editable,
            'readonly' => [
                'currency' => 'NGN',
                'timezone' => Env::get('APP_TIMEZONE', 'Africa/Lagos'),
                'kora_mode' => self::koraMode(),
                'monnify_mode' => self::monnifyMode(),
                'mail_mode' => self::mailMode(),
                'payment_methods' => PaymentMethods::status($this->db),
                'primary_gateway' => PaymentMethods::primaryGateway($this->db),
                'pay_now_gateway' => PaymentMethods::payNowGateway($this->db),
                'order_payment_window_minutes' => \App\Services\PaymentAttempts::gatewayWindowMinutes(),
                'manual_payment_window_hours' => (int) (\App\Services\PaymentAttempts::manualWindowMinutes() / 60),
                // Who gets new-order, payment and storage alerts right now.
                'admin_alert_recipients' => MailService::getInstance()->adminRecipients(),
            ],
        ], 'Store settings retrieved.');
    }

    /**
     * PUT /api/v1/admin/settings  {key: value, ...}  (only the editable keys)
     */
    public function updateSettings(): void
    {
        $body = JsonBodyMiddleware::getBody();
        $errors = [];
        $changes = [];

        foreach ($body as $key => $value) {
            if (!array_key_exists($key, self::EDITABLE_SETTINGS)) {
                $errors[$key] = 'This setting cannot be changed here.';
                continue;
            }
            if (is_array($value) || is_bool($value)) {
                $errors[$key] = 'Enter this as plain text.';
                continue;
            }
            $value = Input::text($value);
            if (mb_strlen($value) > 255) {
                $errors[$key] = 'Too long (255 characters maximum).';
            } elseif ($key === 'store_contact_email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$key] = 'Enter a valid email address.';
            } elseif ($key === MailService::ALERT_EMAIL_SETTING) {
                $alertEmails = MailService::parseAlertEmails($value);
                if ($alertEmails === null) {
                    $errors[$key] = 'Enter valid email addresses separated by commas (at most ' . MailService::MAX_ALERT_EMAILS . ').';
                } else {
                    $changes[$key] = implode(', ', $alertEmails);
                }
            } elseif (in_array($key, ['store_contact_phone', 'store_whatsapp_phone'], true) && $value !== '' && PhoneNumber::toInternational($value) === null) {
                $errors[$key] = 'Enter a valid phone number, e.g. 0803 123 4567 or +234 803 123 4567.';
            } elseif ($key === 'store_whatsapp_phone' && $value !== '') {
                // Saved as +234... so wa.me links work whether the admin typed 080..., 070... or 090...
                $changes[$key] = PhoneNumber::toInternational($value);
            } elseif ($key === \App\Services\CheckoutService::UNPAID_CAP_SETTING) {
                $cap = Input::wholeNumber($value, 0, \App\Services\CheckoutService::UNPAID_CAP_MAX);
                if ($cap === null) {
                    $errors[$key] = 'Enter a whole number from 0 to ' . \App\Services\CheckoutService::UNPAID_CAP_MAX . ' (0 means no limit).';
                } else {
                    $changes[$key] = (string) $cap;
                }
            } elseif ($key === ProductRatings::SETTING) {
                $rating = ProductRatings::parse($value);
                if ($rating === false) {
                    $errors[$key] = 'Enter a rating from 1 to 5 (for example 4.5), or leave it empty.';
                } else {
                    $changes[$key] = $rating === null ? '' : (string) $rating;
                }
            } elseif ($key === 'store_name' && $value === '') {
                $errors[$key] = 'Store name cannot be empty.';
            } elseif ($key === 'storefront_brands') {
                $brands = StoreBrands::parse($value);
                if (count($brands) > 20) {
                    $errors[$key] = 'List at most 20 brands.';
                } elseif (array_filter($brands, static fn (string $b): bool => mb_strlen($b) > 40)) {
                    $errors[$key] = 'Each brand name can be at most 40 characters.';
                } else {
                    $changes[$key] = implode(', ', $brands);
                }
            } else {
                $changes[$key] = $value;
            }
        }
        if (!empty($errors)) {
            Response::error('Please fix the highlighted settings.', 422, 'VALIDATION_ERROR', $errors);
        }

        $before = array_column(
            $this->db->fetchAll("SELECT `key_name`, `value` FROM `store_settings`"),
            'value',
            'key_name'
        );
        foreach ($changes as $key => $value) {
            $this->db->execute(
                "INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                'ss',
                [$key, $value]
            );
        }
        // A new default starting rating changes the shown rating of every product without its own.
        if (array_key_exists(ProductRatings::SETTING, $changes) && ($before[ProductRatings::SETTING] ?? null) !== $changes[ProductRatings::SETTING]) {
            ProductRatings::refresh($this->db);
        }

        AuditService::record(
            $this->adminId(),
            'settings.update',
            'store_settings',
            'global',
            array_intersect_key($before, $changes),
            $changes
        );
        Response::success($changes, 'Settings saved.');
    }

    /**
     * PUT /api/v1/admin/settings/payment-methods  {monnify: bool, kora: bool, whatsapp: bool}
     * Switch checkout payment methods on or off. At least one method must stay available.
     */
    public function updatePaymentMethods(): void
    {
        $body = JsonBodyMiddleware::getBody();
        $before = PaymentMethods::adminSwitches($this->db);
        $after = $before;
        foreach (PaymentMethods::ALL as $method) {
            if (array_key_exists($method, $body)) {
                $after[$method] = filter_var($body[$method], FILTER_VALIDATE_BOOLEAN);
            }
        }

        // "Pay now" uses this gateway (Monnify or Kora).
        $primaryBefore = PaymentMethods::primaryGateway($this->db);
        $primary = $primaryBefore;
        if (array_key_exists('primary_gateway', $body)) {
            $primary = (string) $body['primary_gateway'];
            if (!in_array($primary, PaymentMethods::GATEWAYS, true)) {
                Response::error('Choose Monnify or Kora for "Pay now".', 422, 'VALIDATION_ERROR', ['primary_gateway' => 'Choose Monnify or Kora.']);
            }
        }

        $available = array_filter(PaymentMethods::ALL, static fn (string $m): bool => $after[$m] && PaymentMethods::serverAllows($m));
        if (count($available) === 0) {
            Response::error('Keep at least one payment method on, or customers cannot pay for orders.', 422, 'VALIDATION_ERROR');
        }

        foreach (PaymentMethods::ALL as $method) {
            if ($after[$method] === $before[$method]) {
                continue;
            }
            $this->db->execute(
                "INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                'ss',
                [PaymentMethods::settingKey($method), $after[$method] ? '1' : '0']
            );
        }

        if ($primary !== $primaryBefore) {
            $this->db->execute(
                "INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                'ss',
                [PaymentMethods::PRIMARY_KEY, $primary]
            );
        }
        if ($after !== $before || $primary !== $primaryBefore) {
            AuditService::record($this->adminId(), 'settings.payment_methods', 'store_settings', 'payment_methods', $before + ['primary_gateway' => $primaryBefore], $after + ['primary_gateway' => $primary]);
        }
        Response::success(PaymentMethods::status($this->db) + ['primary_gateway' => $primary, 'pay_now_gateway' => PaymentMethods::payNowGateway($this->db)], 'Payment methods saved.');
    }

    /**
     * Whether OPcache is on for the API. opcache_get_status() answers best, but many shared hosts
     * forbid it (opcache.restrict_api), and then it says false even though OPcache works; PHP's
     * own settings tell the truth in that case.
     */
    public static function opcacheOn(): bool
    {
        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            if (is_array($status)) {
                return (bool) ($status['opcache_enabled'] ?? false);
            }
        }
        return extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function koraMode(): string
    {
        $key = trim((string) Env::get('KORA_SECRET_KEY', ''));
        return $key === '' ? 'not_configured' : (str_starts_with($key, 'sk_live') ? 'live' : 'sandbox');
    }

    public static function monnifyMode(): string
    {
        if (trim((string) Env::get('MONNIFY_API_KEY', '')) === '' || trim((string) Env::get('MONNIFY_CONTRACT_CODE', '')) === '') {
            return 'not_configured';
        }
        return str_contains((string) Env::get('MONNIFY_API_BASE_URL', 'https://sandbox.monnify.com'), 'sandbox') ? 'sandbox' : 'live';
    }

    public static function mailMode(): string
    {
        return MailService::mode();
    }

    /**
     * Settings that work on a development PC but break a live shop, in plain words for System
     * status. Only the settings are read; nothing is changed.
     *
     * @return list<string>
     */
    public static function productionConfigWarnings(): array
    {
        $warnings = [];
        $isPublicHttps = static function (string $url): bool {
            $host = (string) parse_url($url, PHP_URL_HOST);
            if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || $host === '' || $host === 'localhost') {
                return false;
            }
            $ip = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP);
            return $ip === false || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        };

        $appUrl = (string) Env::get('APP_URL', '');
        if (self::koraMode() !== 'not_configured' && !$isPublicHttps($appUrl)) {
            $warnings[] = "APP_URL ({$appUrl}) is not a public https:// address, so Kora cannot send payment notifications to it. Set it to the shop's public address (usually the same as FRONTEND_URL).";
        }
        $frontend = Env::frontendUrl();
        if (!$isPublicHttps($frontend)) {
            $warnings[] = "FRONTEND_URL ({$frontend}) is not a public https:// address: links in emails and payment return pages would not work for customers.";
        }
        $origins = (string) Env::get('CORS_ALLOWED_ORIGINS', '');
        if (preg_match('#//(localhost|127\.0\.0\.1)\b#i', $origins)) {
            $warnings[] = 'CORS_ALLOWED_ORIGINS still lists a localhost address; list only the shop\'s https:// address.';
        }
        if (self::koraMode() === 'sandbox' || self::monnifyMode() === 'sandbox') {
            $warnings[] = 'A payment gateway still uses test (sandbox) keys: customers cannot really pay with it.';
        }
        return $warnings;
    }

    /**
     * php -S answers a missing .webp or .jpg with a 404 itself, before the shop's code runs. With
     * pictures in a bucket none are on the disk, so without the router every /uploads link fails.
     *
     * @return list<string>
     */
    private static function testServerWarnings(string $storage): array
    {
        $firstFile = str_replace('\\', '/', (string) (get_included_files()[0] ?? ''));
        if (PHP_SAPI !== 'cli-server' || $storage !== 's3' || str_ends_with($firstFile, '/dev-router.php')) {
            return [];
        }
        return ['Pictures will not show: this API was started without backend/dev-router.php, so pictures in the bucket answer 404. Stop it and start it with: npm run api'];
    }

    // =========================================================================
    // 10. SYSTEM STATUS
    // =========================================================================

    public function getSystemStatus(): void
    {
        DevSwitches::requireDevMode();
        $dbPing = $this->db->ping();
        $storage = Storage::uploads();

        $count = fn (string $sql): int => (int) ($this->db->fetchOne($sql)['c'] ?? 0);
        $migrations = $this->db->fetchOne("SELECT COUNT(*) AS c, MAX(filename) AS latest FROM `schema_migrations`");

        $checks = [
            'database' => $dbPing['connected'],
            'uploads_writable' => $storage->writable(),
            'kora' => self::koraMode() !== 'not_configured',
            'monnify' => self::monnifyMode() !== 'not_configured',
        ];
        $missing = static fn (array $extensions): array => array_values(array_filter($extensions, static fn (string $e): bool => !extension_loaded($e)));
        // The address rate limits and sign-in protection use for this admin. A private or local
        // address on a live server means every visitor looks the same (proxy not set up; see
        // TRUSTED_PROXIES in docs/DEPLOYMENT.md), so they would all share one set of limits.
        $clientIp = RequestContext::clientIp();
        $publicIp = filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        Response::success([
            'system_health' => $checks['database'] && $checks['uploads_writable'] ? 'healthy' : 'degraded',
            'server_time' => date('Y-m-d H:i:s T'),
            'environment' => Env::isProduction() ? 'production' : 'development',
            'runtime' => [
                'php' => PHP_VERSION,
                'missing_extensions' => $missing(['mysqli', 'curl', 'mbstring', 'openssl', 'json', 'simplexml', 'ctype']),
                // Only scripts/backup.php needs these.
                'missing_backup_extensions' => $missing(['zlib', 'phar']),
                'opcache' => self::opcacheOn(),
                // Pictures up to 5 MB need at least that from upload_max_filesize and post_max_size.
                'upload_limit_bytes' => UploadFiles::serverLimitBytes(),
            ],
            'network' => ['client_ip' => $clientIp, 'public_address' => $publicIp],
            'config_warnings' => array_merge(Env::isProduction() ? self::productionConfigWarnings() : [], self::testServerWarnings($storage->name())),
            'database' => ['connected' => $dbPing['connected'], 'latency_ms' => $dbPing['latency_ms'] ?? null],
            'migrations' => ['applied' => (int) ($migrations['c'] ?? 0), 'latest' => $migrations['latest'] ?? null],
            'storage' => ['uploads_writable' => $checks['uploads_writable'], 'driver' => $storage->name()],
            'payments' => ['kora' => self::koraMode(), 'monnify' => self::monnifyMode()],
            'mail' => $this->mailStatus(),
            'storefront_refresh' => StorefrontRefresh::status($this->db),
            // Optional (REDIS_URL): shared catalogue cache; the file cache takes over when it is down.
            'redis' => Redis::status(),
            'orders' => [
                'needs_attention' => $count("SELECT COUNT(*) AS c FROM `orders` WHERE `requires_attention` = 1"),
                'unpaid_overdue' => $count("SELECT COUNT(*) AS c FROM `orders` WHERE `order_status` = 'pending_payment' AND `expires_at` <= NOW()"),
                'awaiting_fulfilment' => $count("SELECT COUNT(*) AS c FROM `orders` WHERE `order_status` IN ('paid', 'processing')"),
            ],
        ], 'System diagnostics retrieved.');
    }

    // =========================================================================
    // 11. EMAIL MARKETING (newsletter subscribers + registered customers)
    // =========================================================================

    /** newsletter_subscribers.source for a customer put on the list because they have an account. */
    private const CUSTOMER_SOURCE = 'registered_customer';

    /**
     * The newsletter audience, one row per email:
     *  - everyone who subscribed (footer form), active or unsubscribed;
     *  - every confirmed customer account (confirmedCustomer()). A customer gets a
     *    newsletter_subscribers row (with their own unsubscribe token) the first time a newsletter
     *    goes to them; before that they are listed straight from `users`.
     * An unsubscribed row always wins: someone who unsubscribed is never put back by having an
     * account. Customer rows whose account was suspended or removed drop out of the audience.
     * Columns: email, status, origin (subscriber|customer), has_account, subscribed_at,
     * unsubscribed_at, unsubscribe_token.
     */
    private function audienceSql(): string
    {
        $c = self::CUSTOMER_SOURCE;
        return "SELECT n.`email`, n.`status`,
                       CASE WHEN n.`source` = '{$c}' THEN 'customer' ELSE 'subscriber' END AS origin,
                       (acc.`id` IS NOT NULL) AS has_account,
                       n.`subscribed_at`, n.`unsubscribed_at`, n.`unsubscribe_token`
                FROM `newsletter_subscribers` n
                LEFT JOIN `users` acc ON acc.`email` = n.`email` AND " . self::confirmedCustomer('acc') . "
                WHERE n.`source` <> '{$c}' OR acc.`id` IS NOT NULL OR n.`status` = 'unsubscribed'
                UNION ALL
                SELECT u.`email`, 'active', 'customer', 1, u.`created_at`, NULL, NULL
                FROM `users` u
                WHERE " . self::confirmedCustomer('u') . "
                  AND NOT EXISTS (SELECT 1 FROM `newsletter_subscribers` n2 WHERE n2.`email` = u.`email`)";
    }

    /**
     * SQL condition for a customer account the shop may send newsletters to: active, and the
     * address is known to be theirs (they confirmed it, or signed in by an emailed link) or they
     * have paid for an order. Signing up alone proves nothing: anyone can type someone else's
     * address into the sign-up form, and those people must never get the shop's newsletters.
     */
    private static function confirmedCustomer(string $alias): string
    {
        return "{$alias}.`role` = 'customer' AND {$alias}.`status` = 'active'
                AND ({$alias}.`email_verified_at` IS NOT NULL
                     OR EXISTS (SELECT 1 FROM `orders` po WHERE po.`user_id` = {$alias}.`id`
                                AND po.`order_status` IN ('paid', 'processing', 'shipped', 'delivered', 'completed')))";
    }

    /**
     * GET /api/v1/admin/marketing/subscribers?status=active|unsubscribed|any&origin=subscriber|customer
     */
    public function listMarketingSubscribers(): void
    {
        $status = Input::text($_GET['status'] ?? 'active');
        $origin = Input::text($_GET['origin'] ?? '');
        $where = " WHERE 1=1";
        $types = '';
        $params = [];
        if (in_array($status, ['active', 'unsubscribed'], true)) {
            $where .= " AND a.`status` = ?";
            $types .= 's';
            $params[] = $status;
        }
        if (in_array($origin, ['subscriber', 'customer'], true)) {
            $where .= " AND a.`origin` = ?";
            $types .= 's';
            $params[] = $origin;
        }

        $from = " FROM (" . $this->audienceSql() . ") a";
        [$page, $perPage, $offset] = $this->paging(25);
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) AS total" . $from . $where, $types, $params)['total'];
        $items = $this->db->fetchAll(
            "SELECT a.`email`, a.`status`, a.`origin`, a.`has_account`, a.`subscribed_at`, a.`unsubscribed_at`"
            . $from . $where . " ORDER BY a.`subscribed_at` DESC, a.`email` ASC LIMIT ? OFFSET ?",
            $types . 'ii',
            array_merge($params, [$perPage, $offset])
        );
        foreach ($items as &$item) {
            $item['has_account'] = (bool) $item['has_account'];
        }
        unset($item);
        $counts = $this->db->fetchOne(
            "SELECT COALESCE(SUM(a.`status` = 'active'), 0) AS active,
                    COALESCE(SUM(a.`status` = 'unsubscribed'), 0) AS unsubscribed,
                    COALESCE(SUM(a.`status` = 'active' AND a.`origin` = 'subscriber'), 0) AS active_subscribers,
                    COALESCE(SUM(a.`status` = 'active' AND a.`origin` = 'customer'), 0) AS active_customers" . $from
        );

        $pagination = ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => (int) ceil($total / $perPage)];
        Response::success([
            'items' => $items,
            'pagination' => $pagination,
            'counts' => [
                'active' => (int) $counts['active'],
                'unsubscribed' => (int) $counts['unsubscribed'],
                'active_subscribers' => (int) $counts['active_subscribers'],
                'active_customers' => (int) $counts['active_customers'],
            ],
        ], 'Newsletter audience retrieved.', 200, $pagination);
    }

    /**
     * POST /api/v1/admin/marketing/broadcast  {subject, content, confirm_recipients}
     * Queues one email per active address in the audience (subscribers and customers), each with
     * its own unsubscribe link. confirm_recipients must equal the current active count (the admin
     * confirmed it).
     */
    public function sendMarketingBroadcast(): void
    {
        $body = JsonBodyMiddleware::getBody();
        $subject = Input::text($body['subject'] ?? null);
        $content = Input::text($body['content'] ?? null);

        if ($subject === '' || $content === '') {
            Response::error('Subject and message are required.', 422, 'VALIDATION_ERROR');
        }
        if (mb_strlen($subject) > 150) {
            Response::error('Subject is too long (150 characters maximum).', 422, 'VALIDATION_ERROR');
        }

        // Customers who have never had a newsletter get their own row and unsubscribe token now.
        // INSERT IGNORE: an existing row (above all an unsubscribed one) is never changed.
        $newCustomers = $this->db->fetchAll(
            "SELECT u.`email`, u.`created_at` FROM `users` u
             WHERE " . self::confirmedCustomer('u') . "
               AND NOT EXISTS (SELECT 1 FROM `newsletter_subscribers` n WHERE n.`email` = u.`email`)"
        );
        // One transaction: thousands of single commits would be slow enough to time out on shared hosting.
        $this->db->transaction(function (Database $db) use ($newCustomers): void {
            foreach ($newCustomers as $customer) {
                $db->execute(
                    "INSERT IGNORE INTO `newsletter_subscribers` (`email`, `status`, `source`, `unsubscribe_token`, `subscribed_at`)
                     VALUES (?, 'active', ?, ?, ?)",
                    'ssss',
                    [strtolower((string) $customer['email']), self::CUSTOMER_SOURCE, bin2hex(random_bytes(16)), (string) $customer['created_at']]
                );
            }
        });

        $recipients = array_values(array_filter(
            $this->db->fetchAll("SELECT a.`email`, a.`origin`, a.`unsubscribe_token` FROM (" . $this->audienceSql() . ") a WHERE a.`status` = 'active'"),
            static fn (array $r): bool => (string) $r['unsubscribe_token'] !== ''
        ));
        if (Input::wholeNumber($body['confirm_recipients'] ?? null, 0, PHP_INT_MAX) !== count($recipients)) {
            Response::error('The number of recipients changed. Review the recipients and confirm again.', 409, 'RECIPIENTS_CHANGED', [
                'active_subscribers' => count($recipients),
            ]);
        }

        $frontend = Env::frontendUrl();
        $mail = MailService::getInstance();
        // The same newsletter (subject and message) goes to each address at most once a day: if
        // the admin sends again after a timeout or a double click, nobody gets it twice.
        $campaign = date('Y-m-d') . "\n" . $subject . "\n" . $content;
        $broadcasts = fn (): int => (int) ($this->db->fetchOne("SELECT COUNT(*) AS c FROM `email_outbox` WHERE `template` = 'marketing_broadcast'")['c'] ?? 0);
        $before = $broadcasts();
        $this->db->transaction(function () use ($mail, $recipients, $subject, $content, $frontend, $campaign): void {
            foreach ($recipients as $r) {
                $key = 'broadcast:' . sha1($campaign . "\n" . strtolower((string) $r['email']));
                $mail->queueBroadcast($r['email'], $subject, $content, $frontend . '/unsubscribe?token=' . $r['unsubscribe_token'], $key, $r['origin'] === 'customer');
            }
        });
        $queued = max(0, $broadcasts() - $before);
        $skipped = count($recipients) - $queued;
        $customers = count(array_filter($recipients, static fn (array $r): bool => $r['origin'] === 'customer'));

        AuditService::record($this->adminId(), 'marketing.broadcast', 'email_campaign', null, null, [
            'subject' => $subject,
            'recipient_count' => count($recipients),
            'customer_count' => $customers,
            'queued_count' => $queued,
        ]);

        Response::success(
            ['queued_count' => $queued, 'already_queued_today' => $skipped, 'mail_mode' => self::mailMode()],
            ($queued > 0 ? 'Queued for ' . $queued . ' people. The outbox worker sends them in batches.' : 'Nothing new to send.')
            . ($skipped > 0 ? " {$skipped} already had this newsletter queued today, so they will not get it twice." : '')
        );
    }

    /**
     * POST /api/v1/newsletter/subscribe (public, storefront footer)
     */
    public function subscribeNewsletter(): void
    {
        RateLimiter::attempt('newsletter', RequestContext::clientIp(), 10, 3600);
        $email = strtolower(trim((string) (JsonBodyMiddleware::getBody()['email'] ?? '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Please enter a valid email address.', 422, 'INVALID_EMAIL');
        }

        // A customer's unsubscribe always wins: the form needs no proof of who is typing, so it
        // must not put a customer who asked to be left alone back on the list (having an account
        // is what keeps them in the audience). Only that customer, signed in, can subscribe the
        // address again. An address with no account simply subscribes again, as it first did.
        $existing = $this->db->fetchOne("SELECT `status` FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$email]);
        $hasAccount = $existing !== null && $existing['status'] === 'unsubscribed'
            && $this->db->fetchOne("SELECT `id` FROM `users` WHERE `email` = ? AND `role` = 'customer' LIMIT 1", 's', [$email]) !== null;
        if ($hasAccount) {
            $session = SessionGuard::resolve(SessionCookie::SCOPE_CUSTOMER);
            if (strtolower((string) ($session['user']['email'] ?? '')) !== $email) {
                Response::error(
                    'This address unsubscribed from our emails before. To get them again, sign in to the account with this email and subscribe from there.',
                    409,
                    'UNSUBSCRIBED_BEFORE'
                );
            }
        }

        $this->db->execute(
            "INSERT INTO `newsletter_subscribers` (`email`, `status`, `source`, `unsubscribe_token`)
             VALUES (?, 'active', 'storefront_footer', ?)
             ON DUPLICATE KEY UPDATE `status` = 'active', `unsubscribed_at` = NULL,
                                     `unsubscribe_token` = COALESCE(`unsubscribe_token`, VALUES(`unsubscribe_token`))",
            'ss',
            [$email, bin2hex(random_bytes(16))]
        );

        Response::success(['email' => $email], "You're subscribed. Thanks!");
    }

    /**
     * POST /api/v1/newsletter/unsubscribe  {token} (public, from the link in every newsletter)
     */
    public function unsubscribeNewsletter(): void
    {
        RateLimiter::attempt('newsletter_unsub', RequestContext::clientIp(), 30, 3600);
        $token = trim((string) (JsonBodyMiddleware::getBody()['token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            Response::error('This unsubscribe link is not valid.', 422, 'INVALID_TOKEN');
        }

        $sub = $this->db->fetchOne("SELECT `id`, `email`, `status` FROM `newsletter_subscribers` WHERE `unsubscribe_token` = ?", 's', [$token]);
        if ($sub === null) {
            Response::error('This unsubscribe link is not valid.', 404, 'INVALID_TOKEN');
        }
        if ($sub['status'] !== 'unsubscribed') {
            $this->db->executeUpdate(
                "UPDATE `newsletter_subscribers` SET `status` = 'unsubscribed', `unsubscribed_at` = NOW() WHERE `id` = ?",
                'i',
                [(int) $sub['id']]
            );
        }

        Response::success(['email' => $sub['email']], "You've been unsubscribed.");
    }

    // =========================================================================
    // 12. ADMIN ACCOUNT
    // =========================================================================

    public function getAdminAccount(): void
    {
        $user = $this->db->fetchOne(
            "SELECT id, uuid, email, full_name, phone, role, created_at, updated_at FROM `users` WHERE `id` = ?",
            'i',
            [(int) $this->adminId()]
        );
        Response::success($user, 'Admin account details retrieved.');
    }

    /**
     * PUT /api/v1/admin/account  {full_name, phone, email?, current_password?, new_password?}
     * Changing the sign-in email or the password needs the current password. Changing the
     * password signs out every other admin session. Admin alerts follow the new email unless
     * "Send admin alerts to" (Settings) names other addresses.
     */
    public function updateAdminAccount(): void
    {
        $adminId = (int) $this->adminId();
        $body = JsonBodyMiddleware::getBody();
        $user = $this->db->fetchOne("SELECT * FROM `users` WHERE `id` = ?", 'i', [$adminId]);

        $fullName = array_key_exists('full_name', $body) ? Input::text($body['full_name']) : (string) $user['full_name'];
        $phone = array_key_exists('phone', $body) ? Input::text($body['phone']) : (string) $user['phone'];
        $email = array_key_exists('email', $body) ? strtolower(Input::text($body['email'])) : strtolower((string) $user['email']);
        $currentPassword = is_string($body['current_password'] ?? null) ? $body['current_password'] : '';
        $newPassword = is_string($body['new_password'] ?? null) ? $body['new_password'] : '';
        $emailChanged = $email !== strtolower((string) $user['email']);

        if ($fullName === '') {
            Response::error('Name cannot be empty.', 422, 'VALIDATION_ERROR', ['full_name' => 'Name cannot be empty.']);
        }
        if (Input::tooLong($fullName, 255)) {
            Response::error('Name can be at most 255 characters.', 422, 'VALIDATION_ERROR', ['full_name' => 'At most 255 characters.']);
        }
        if (Input::tooLong($phone, 50)) {
            Response::error('Phone can be at most 50 characters.', 422, 'VALIDATION_ERROR', ['phone' => 'At most 50 characters.']);
        }
        if ($emailChanged) {
            if ($email === '' || mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::error('Enter a valid email address.', 422, 'VALIDATION_ERROR', ['email' => 'Enter a valid email address.']);
            }
            $taken = $this->db->fetchOne("SELECT `id` FROM `users` WHERE `email` = ? AND `id` <> ? LIMIT 1", 'si', [$email, $adminId]);
            if ($taken !== null) {
                Response::error('Another account already uses this email.', 409, 'EMAIL_ALREADY_EXISTS', ['email' => 'Another account already uses this email.']);
            }
        }

        // Validate the password change before writing anything.
        if ($newPassword !== '') {
            $policy = self::adminPasswordProblem($newPassword);
            if ($policy !== null) {
                Response::error('New password: ' . lcfirst($policy), 422, 'VALIDATION_ERROR', ['new_password' => $policy]);
            }
        }
        if ($newPassword !== '' || $emailChanged) {
            self::requireCurrentPassword($adminId, $currentPassword, 'current_password', 'Your current password is incorrect.', 'INVALID_CURRENT_PASSWORD');
        }

        $this->db->executeUpdate("UPDATE `users` SET `full_name` = ?, `phone` = ? WHERE `id` = ?", 'ssi', [$fullName, $phone ?: null, $adminId]);
        if ($emailChanged) {
            $this->db->executeUpdate("UPDATE `users` SET `email` = ? WHERE `id` = ?", 'si', [$email, $adminId]);
        }

        $passwordChanged = false;
        if ($newPassword !== '') {
            $this->db->executeUpdate(
                "UPDATE `users` SET `password_hash` = ? WHERE `id` = ?",
                'si',
                [password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]), $adminId]
            );

            // Sign out other admin sessions; keep this one.
            $current = SessionCookie::read(SessionCookie::SCOPE_ADMIN);
            $this->db->execute(
                "DELETE FROM `sessions` WHERE `user_id` = ? AND `id` <> ?",
                'is',
                [$adminId, hash('sha256', (string) $current)]
            );
            $passwordChanged = true;
        }

        AuditService::record(
            $adminId,
            $passwordChanged ? 'admin.password_change' : ($emailChanged ? 'admin.email_change' : 'admin.profile_update'),
            'admin',
            (string) $adminId,
            ['full_name' => $user['full_name'], 'phone' => $user['phone'], 'email' => $user['email']],
            ['full_name' => $fullName, 'phone' => $phone, 'email' => $email]
        );

        $message = $passwordChanged ? 'Profile and password updated. Other sessions were signed out.' : 'Profile updated.';
        if ($emailChanged) {
            $message .= ' Sign in with ' . $email . ' from now on.';
        }
        Response::success(['id' => $adminId, 'full_name' => $fullName, 'phone' => $phone, 'email' => $email], $message);
    }

    /** The admin password rule (first-run setup uses the same): at least 10 characters. */
    public const ADMIN_MIN_PASSWORD = 10;

    /** What is wrong with a new admin password, or null when it is fine. */
    public static function adminPasswordProblem(string $password): ?string
    {
        if (str_contains($password, "\0")) {
            return 'Contains a character that cannot be used.';
        }
        if (strlen($password) < self::ADMIN_MIN_PASSWORD) {
            return 'At least ' . self::ADMIN_MIN_PASSWORD . ' characters.';
        }
        return null;
    }

    /** One limit for every "type your password again" check (team, account, dev mode, clean-up). */
    public const REAUTH_BUCKET = 'admin_reauth';
    public const REAUTH_TRIES = 5;
    public const REAUTH_WINDOW = 900;

    /**
     * True when $password is the admin's own. Five wrong tries in 15 minutes lock every such
     * check for the rest of the window (answers 429 itself). Each try is counted before the
     * password is compared, so many requests sent at once cannot slip extra guesses past the
     * limit; a right password clears the count.
     */
    public static function passwordConfirmed(int $adminId, #[\SensitiveParameter] string $password): bool
    {
        RateLimiter::attempt(self::REAUTH_BUCKET, (string) $adminId, self::REAUTH_TRIES, self::REAUTH_WINDOW, 'Too many wrong passwords. Wait 15 minutes and try again.');
        $row = Database::getInstance()->fetchOne('SELECT `password_hash` FROM `users` WHERE `id` = ?', 'i', [$adminId]);
        $hash = (string) ($row['password_hash'] ?? '');
        if ($password === '' || $hash === '' || !password_verify($password, $hash)) {
            return false;
        }
        RateLimiter::clear(self::REAUTH_BUCKET, (string) $adminId);
        return true;
    }

    /** Stop the request unless $password is the signed-in admin's own password (see passwordConfirmed). */
    public static function requireCurrentPassword(int $adminId, #[\SensitiveParameter] string $password, string $field, string $message, string $code = 'WRONG_PASSWORD'): void
    {
        if (!self::passwordConfirmed($adminId, $password)) {
            Response::error($message, 422, $code, [$field => 'Incorrect password.']);
        }
    }
}
