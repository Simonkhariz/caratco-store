<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Database\Database;
use App\Middleware\JsonBodyMiddleware;
use App\Services\CheckoutService;
use App\Services\FulfillmentService;
use App\Services\KoraService;
use App\Services\LiveStatus;
use App\Services\MailService;
use App\Services\MonnifyService;
use App\Services\PaymentAttempts;
use App\Services\PaymentMethods;
use App\Support\RateLimiter;
use App\Support\RequestContext;
use App\Support\Response;
use App\Support\SessionCookie;
use App\Support\SessionGuard;
use App\Support\ValidationFailed;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Controller handling public and authenticated checkout endpoints:
 * - Quote generation with live inventory, promotion and shipping rate validation
 * - Atomic transactional order creation with inventory reservation
 * - Destination shipping zone discovery
 * - Guest/customer order retrieval with token security
 */
class CheckoutController
{
    private CheckoutService $checkoutService;
    private Database $db;

    public function __construct(?CheckoutService $checkoutService = null, ?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->checkoutService = $checkoutService ?? new CheckoutService($this->db);
    }

    /**
     * Compute a verified checkout quote without creating an order.
     * POST /api/v1/checkout/quote
     */
    public function quote(): void
    {
        RateLimiter::attempt('checkout_quote', RequestContext::clientIp(), 150, 600);
        $body = JsonBodyMiddleware::getBody();

        try {
            $quote = $this->checkoutService->getQuote($body);
            Response::success($quote, 'Checkout quotation generated successfully.');
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\mysqli_sql_exception $e) {
            // Never surface raw SQL errors (mysqli_sql_exception extends RuntimeException).
            error_log('[Checkout Quote DB Failure] ' . $e->getMessage());
            Response::error('We could not calculate your total right now. Please try again.', 500, 'INTERNAL_SERVER_ERROR');
        } catch (RuntimeException $e) {
            // Database.php's own failures carry code 500 and setup text; the rest (not enough
            // stock, no delivery zones) are written for the customer.
            if ($e->getCode() === 500) {
                error_log('[Checkout Quote DB Failure] ' . $e->getMessage());
                Response::error('We could not calculate your total right now. Please try again.', 500, 'INTERNAL_SERVER_ERROR');
            }
            Response::error($e->getMessage(), 400, 'QUOTE_ERROR');
        } catch (Throwable $e) {
            error_log('[Checkout Quote Failure] ' . $e->getMessage());
            Response::error('We could not calculate your total right now. Please try again.', 500, 'INTERNAL_SERVER_ERROR');
        }
    }

    /**
     * Current price, stock and availability for each cart line, so the cart can show
     * "price changed" / "only N left" / "no longer available" before checkout.
     * POST /api/v1/checkout/cart-check  {variant_ids: number[]}
     */
    public function cartCheck(): void
    {
        RateLimiter::attempt('cart_check', RequestContext::clientIp(), 300, 600);
        $body = JsonBodyMiddleware::getBody();
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map('intval', is_array($body['variant_ids'] ?? null) ? $body['variant_ids'] : []),
            static fn (int $v): bool => $v > 0
        ))), 0, 50);

        if (empty($ids)) {
            Response::success(['lines' => []], 'Nothing to check.');
        }

        $rows = $this->db->fetchAll(
            "SELECT pv.id AS variant_id, pv.product_id, pv.sku, pv.size, pv.color_name, pv.color_hex,
                    COALESCE(NULLIF(pv.price_override, 0), p.base_price) AS unit_price, p.original_price,
                    pv.stock_quantity, p.name AS product_name, p.slug AS product_slug,
                    (pv.is_active = 1 AND p.status = 'published' AND p.product_type = 'physical') AS available
             FROM `product_variants` pv
             JOIN `products` p ON p.id = pv.product_id
             WHERE pv.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")",
            str_repeat('i', count($ids)),
            $ids
        );

        $lines = [];
        foreach ($rows as $row) {
            // A line that can't be bought says only that: unpublished products stay private.
            if (!$row['available']) {
                $lines[] = ['variant_id' => (int) $row['variant_id'], 'available' => false];
                continue;
            }
            $lines[] = [
                'variant_id' => (int) $row['variant_id'],
                'product_id' => (int) $row['product_id'],
                'sku' => $row['sku'],
                'size' => $row['size'],
                'color_name' => $row['color_name'],
                'color_hex' => $row['color_hex'],
                'unit_price' => (float) $row['unit_price'],
                'original_price' => $row['original_price'] !== null ? (float) $row['original_price'] : null,
                'stock_quantity' => max(0, (int) $row['stock_quantity']),
                'product_name' => $row['product_name'],
                'product_slug' => $row['product_slug'],
                'available' => true,
            ];
        }

        Response::success(['lines' => $lines], 'Cart lines checked.');
    }

    /**
     * Create a pending order and reserve inventory inside a database transaction.
     * POST /api/v1/checkout/orders
     */
    public function createOrder(): void
    {
        // Order creation reserves stock, so it is throttled per client to stop inventory lock-ups.
        RateLimiter::attempt('checkout_order', RequestContext::clientIp(), 10, 600, 'Too many orders placed from this connection. Please wait a few minutes.');
        $body = JsonBodyMiddleware::getBody();

        // 1. Resolve optional authenticated customer (CSRF enforced when a session is used)
        $currentUser = $this->resolveOptionalUser(true);
        $userId = $currentUser ? (int) $currentUser['id'] : null;

        // If email was not explicitly provided in body but user is logged in, use their account email
        if (empty($body['email']) && $currentUser !== null) {
            $body['email'] = $currentUser['email'];
        }

        try {
            $idempotencyKey = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($body['idempotency_key'] ?? ''));
            $deviceId = is_string($_SERVER['HTTP_X_DEVICE_ID'] ?? null) ? $_SERVER['HTTP_X_DEVICE_ID'] : null;
            $order = $this->checkoutService->createOrder($body, $userId, $idempotencyKey !== '' ? $idempotencyKey : null, $deviceId);
            Response::success($order, 'Order placed successfully. Inventory has been reserved.', 201);
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'VALIDATION_ERROR', $e instanceof ValidationFailed ? $e->details : []);
        } catch (\mysqli_sql_exception $e) {
            // Never surface raw SQL errors (mysqli_sql_exception extends RuntimeException).
            error_log('[Order Creation DB Failure] ' . $e->getMessage());
            Response::error('We could not place your order right now. Please try again.', 500, 'INTERNAL_SERVER_ERROR');
        } catch (RuntimeException $e) {
            // Database.php's own failures carry code 500 and setup text.
            if ($e->getCode() === 500) {
                error_log('[Order Creation DB Failure] ' . $e->getMessage());
                Response::error('We could not place your order right now. Please try again.', 500, 'INTERNAL_SERVER_ERROR');
            }
            // Inventory or promotion conflict raised deliberately by CheckoutService
            $code = str_contains($e->getMessage(), 'Insufficient stock')
                ? 'INSUFFICIENT_STOCK'
                : (str_contains($e->getMessage(), 'waiting for payment') ? 'TOO_MANY_UNPAID_ORDERS' : 'ORDER_CREATION_FAILED');
            Response::error($e->getMessage(), 409, $code);
        } catch (Throwable $e) {
            error_log('[Order Creation Failure] ' . $e->getMessage());
            Response::error('We could not place your order right now. Please try again.', 500, 'INTERNAL_SERVER_ERROR');
        }
    }

    /**
     * List active destination shipping zones and fees.
     * GET /api/v1/checkout/shipping-zones
     */
    public function shippingZones(): void
    {
        $zones = $this->db->fetchAll("SELECT * FROM `shipping_zones` WHERE `is_active` = 1 ORDER BY `id` ASC");

        foreach ($zones as &$zone) {
            if (is_string($zone['states_covered'])) {
                $zone['states_covered'] = json_decode($zone['states_covered'], true) ?: [];
            }
            $zone['fee'] = (float) $zone['fee'];
            $zone['items_included'] = max(1, (int) ($zone['items_included'] ?? 1));
            $zone['extra_item_fee'] = (float) ($zone['extra_item_fee'] ?? 0);
            $zone['heavy_item_fee'] = (float) ($zone['heavy_item_fee'] ?? 0);
            $zone['is_active'] = (bool) $zone['is_active'];
        }
        unset($zone);

        $origin = $this->checkoutService->getStoreOrigin();

        // Same for every visitor; the delivery fee itself is recalculated at checkout.
        Response::cachePublic(30, 120);
        Response::success([
            'zones' => $zones,
            'origin' => $origin,
        ], 'Active shipping zones retrieved.');
    }

    /**
     * Track / retrieve single order details.
     * GET /api/v1/checkout/orders/{orderNumber}
     * Accessible by:
     * - Order customer (when signed in)
     * - Guest customer with valid guest_access_token (via ?token= or X-Guest-Access-Token header)
     */
    public function show(array $params): void
    {
        // This route takes the code as sent ("TRK%20123"). Bytes that aren't text can match nothing.
        $lookupCode = trim(rawurldecode($params['orderNumber'] ?? ''));
        if ($lookupCode === '') {
            Response::error('Order number or tracking code is required.', 422, 'VALIDATION_ERROR');
        }
        $searchable = mb_check_encoding($lookupCode, 'UTF-8') && !str_contains($lookupCode, "\0");

        $notFound = 'We could not find an order matching those details. Check the order number and the email used at checkout.';

        // Unauthenticated lookups are throttled (failures only) to stop order-number/email guessing.
        $ip = RequestContext::clientIp();
        RateLimiter::guard('order_lookup_fail', $ip, 20, 900, 'Too many unsuccessful lookups. Please wait 15 minutes and try again.');

        $order = $searchable ? $this->db->fetchOne("SELECT * FROM `orders` WHERE `order_number` = ?", 's', [$lookupCode]) : null;

        if ($order === null && $searchable) {
            // A courier tracking number identifies the order, but never authorizes access on its own.
            $shipment = $this->db->fetchOne(
                "SELECT `order_id` FROM `shipments` WHERE `tracking_number` = ? LIMIT 1",
                's',
                [$lookupCode]
            );
            if ($shipment !== null) {
                $order = $this->db->fetchOne("SELECT * FROM `orders` WHERE `id` = ?", 'i', [(int) $shipment['order_id']]);
            }
        }

        if ($order === null || !$this->canViewOrder($order, true)) {
            RateLimiter::hit('order_lookup_fail', $ip, 900);
            Response::error($notFound, 404, 'ORDER_NOT_FOUND');
        }

        // Fetch Order Items
        $items = $this->db->fetchAll(
            "SELECT `id`, `product_id`, `variant_id`, `product_type`, `item_name`, `sku`, `variant_options`, `unit_price`, `quantity`, `line_total`
             FROM `order_items` WHERE `order_id` = ?",
            'i',
            [(int) $order['id']]
        );

        foreach ($items as &$item) {
            $item['variant_options'] = is_string($item['variant_options']) ? json_decode($item['variant_options'], true) : $item['variant_options'];
            $item['unit_price'] = (float) $item['unit_price'];
            $item['line_total'] = (float) $item['line_total'];
        }
        unset($item);

        // Fetch Status History
        $history = $this->db->fetchAll(
            "SELECT `previous_status`, `new_status`, `created_at`
             FROM `order_status_history` WHERE `order_id` = ? ORDER BY `id` ASC",
            'i',
            [(int) $order['id']]
        );

        // Fetch Payment status (prioritize successful payment over pending/failed attempts)
        $payment = $this->db->fetchOne(
            "SELECT `provider`, `provider_reference`, `amount`, `currency`, `status`, `verified_at`
             FROM `payments` WHERE `order_id` = ? ORDER BY (`status` = 'successful') DESC, `id` DESC LIMIT 1",
            'i',
            [(int) $order['id']]
        );

        if ($payment !== null) {
            $payment['amount'] = (float) (
                (!empty($payment['amount']) && (float) $payment['amount'] > 0)
                    ? $payment['amount']
                    : ($order['grand_total'] ?? 0.0)
            );
            $payment['currency'] = !empty($payment['currency']) ? $payment['currency'] : ($order['currency'] ?? 'NGN');
        }

        // Fetch Shipment status
        $shipment = $this->db->fetchOne(
            "SELECT `id`, `carrier`, `tracking_number`, `tracking_url`, `status`, `shipped_at`, `delivered_at`, `created_at`, `updated_at`
             FROM `shipments` WHERE `order_id` = ? ORDER BY `id` DESC LIMIT 1",
            'i',
            [(int) $order['id']]
        );

        // Redact internal fields from the customer-facing response
        unset($order['guest_access_token_hash'], $order['user_id'], $order['idempotency_key'], $order['requires_attention'], $order['attention_reason']);

        $order['shipping_zone_snapshot'] = is_string($order['shipping_zone_snapshot']) ? json_decode($order['shipping_zone_snapshot'], true) : $order['shipping_zone_snapshot'];
        $order['shipping_address_snapshot'] = is_string($order['shipping_address_snapshot']) ? json_decode($order['shipping_address_snapshot'], true) : $order['shipping_address_snapshot'];
        $order['subtotal'] = (float) $order['subtotal'];
        $order['discount_amount'] = (float) $order['discount_amount'];
        $order['shipping_fee'] = (float) $order['shipping_fee'];
        $order['grand_total'] = (float) $order['grand_total'];
        $order['items'] = $items;
        $order['history'] = $history;
        $order['payment'] = $payment;
        $order['shipment'] = $shipment;
        $order['status'] = $order['order_status'] ?? 'pending_payment';

        Response::success($order, 'Order retrieved successfully.');
    }

    /**
     * Get available checkout payment methods configured for this store.
     * Monnify is first, Kora is second, Request from WhatsApp is last.
     * The admin switches methods on or off in Store settings; backend/.env can also disable one.
     * GET /api/v1/checkout/payment-methods
     */
    public function paymentMethods(): void
    {
        // Switched on and able to work: no keys or no WhatsApp number means it isn't offered.
        $status = PaymentMethods::status($this->db);
        $monnifyEnabled = $status['monnify']['enabled'] && PaymentMethods::configured('monnify');
        $koraEnabled = $status['kora']['enabled'] && PaymentMethods::configured('kora');
        $whatsappEnabled = $status['whatsapp']['enabled'] && PaymentMethods::configured('whatsapp');

        $methods = [];

        // 1. Monnify (First)
        if ($monnifyEnabled) {
            $methods[] = [
                'id' => 'monnify',
                'name' => 'Monnify',
                // Only what MonnifyService opens the payment page with (CARD, ACCOUNT_TRANSFER).
                'description' => 'Pay by card or bank transfer',
                'badge' => 'Instant & Secure',
                'is_default' => true,
            ];
        }

        // 2. Kora (Second)
        if ($koraEnabled) {
            $methods[] = [
                'id' => 'kora',
                'name' => 'Kora',
                // Only what KoraService opens the payment page with (card, bank_transfer).
                'description' => 'Pay securely by card or bank transfer',
                'badge' => 'Secure Checkout',
                'is_default' => empty($methods),
            ];
        }

        // 3. Request from WhatsApp (Last)
        if ($whatsappEnabled) {
            $methods[] = [
                'id' => 'whatsapp',
                'name' => 'Request from WhatsApp',
                'description' => 'Send your invoice directly to our support team on WhatsApp',
                'badge' => 'Manual Support',
                'is_default' => empty($methods),
            ];
        }

        // Same for every visitor; the server still refuses a method that was switched off.
        Response::cachePublic(30, 120);
        Response::success([
            'methods' => $methods,
            // The storefront shows one "Pay now" button for this gateway (null: none is on),
            // plus "Pay via WhatsApp" when that is on.
            'pay_now_gateway' => PaymentMethods::payNowGateway($this->db),
            'whatsapp_enabled' => $whatsappEnabled,
            'support_phone' => CheckoutService::supportWhatsAppPhone(),
            // How long an unpaid order holds its items, so the storefront states the real values.
            'payment_window_minutes' => PaymentAttempts::gatewayWindowMinutes(),
            'manual_payment_window_hours' => intdiv(PaymentAttempts::manualWindowMinutes(), 60),
            // The payment return page may open the live status stream (docs/SSE_CONTRACT.md).
            'live_payment_status' => LiveStatus::enabled(),
        ], 'Payment methods retrieved.');
    }

    /**
     * Initialize payment session (Monnify or Kora) for an order.
     * POST /api/v1/checkout/orders/{orderNumber}/initialize-payment
     */
    public function initializePayment(array $params): void
    {
        $orderNumber = trim($params['orderNumber'] ?? '');
        if ($orderNumber === '') {
            Response::error('Order number is required.', 422, 'VALIDATION_ERROR');
        }

        $order = $this->db->fetchOne("SELECT * FROM `orders` WHERE `order_number` = ?", 's', [$orderNumber]);
        if ($order === null) {
            Response::error('Order not found.', 404, 'ORDER_NOT_FOUND');
        }

        RateLimiter::attempt('payment_init', RequestContext::clientIp(), 20, 600);
        $body = JsonBodyMiddleware::getBody();

        // The checkout email counts here as it does for looking the order up: the order emails
        // link to the tracking page, so a guest on another device has only the order number and
        // email. Starting a payment can't harm the owner (whoever pays, pays for this order) and
        // can't hold the stock past the order's overall limit.
        $this->guardEmailGuesses();
        if (!$this->canViewOrder($order, true, true, (string) ($body['guest_access_token'] ?? ''))) {
            $this->countEmailGuess();
            Response::error('Order not found.', 404, 'ORDER_NOT_FOUND');
        }

        // The return URL is always our own callback page; a client-supplied URL is only accepted
        // when it points back at the storefront (no open redirect). The guest token never goes in it.
        $frontendUrl = Env::frontendUrl();
        $redirectUrl = $frontendUrl . '/checkout/callback?order=' . urlencode($orderNumber);
        $requestedRedirect = trim((string) ($body['redirect_url'] ?? ''));
        if ($requestedRedirect !== '' && str_starts_with($requestedRedirect, $frontendUrl . '/')) {
            $parts = parse_url($requestedRedirect);
            parse_str($parts['query'] ?? '', $query);
            unset($query['token']);
            $query['order'] = $orderNumber;
            $redirectUrl = $frontendUrl . ($parts['path'] ?? '/checkout/callback') . '?' . http_build_query($query);
        }

        $gateway = strtolower(trim((string) ($body['gateway'] ?? '')));
        if (!in_array($gateway, ['kora', 'monnify', 'whatsapp'], true)) {
            Response::error('Choose a payment method: kora, monnify or whatsapp.', 422, 'VALIDATION_ERROR');
        }
        if (!PaymentMethods::isEnabled($gateway, $this->db)) {
            Response::error('That payment method is currently unavailable.', 422, 'PAYMENT_METHOD_DISABLED');
        }

        try {
            if ($gateway === 'monnify') {
                $result = (new MonnifyService($this->db))->initializeCheckout($orderNumber, $redirectUrl);
                Response::success($result, 'Monnify payment session initialized successfully.');
            } elseif ($gateway === 'kora') {
                $result = (new KoraService($this->db))->initializeCheckout($orderNumber, $redirectUrl);
                Response::success($result, 'Kora payment session initialized successfully.');
            } else {
                // Manual transfer arranged over WhatsApp: keep the stock reserved longer while the
                // customer pays; the admin confirms with the real transfer reference.
                $attempts = new PaymentAttempts($this->db);
                $reference = 'WA-' . $orderNumber . '-' . strtoupper(bin2hex(random_bytes(3)));
                $attempts->open($order, 'whatsapp', $reference, PaymentAttempts::manualWindowMinutes());
                $fresh = $this->db->fetchOne("SELECT `expires_at` FROM `orders` WHERE `id` = ?", 'i', [(int) $order['id']]);
                Response::success([
                    'order_number' => $orderNumber,
                    'provider' => 'whatsapp',
                    'provider_reference' => $reference,
                    'amount' => (float) $order['grand_total'],
                    'currency' => $order['currency'],
                    'status' => 'pending',
                    'expires_at' => $fresh['expires_at'] ?? null,
                    'support_phone' => CheckoutService::supportWhatsAppPhone(),
                ], 'Order reserved while you complete payment with our team on WhatsApp.');
            }
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\mysqli_sql_exception $e) {
            // Never surface raw SQL errors (mysqli_sql_exception extends RuntimeException).
            error_log('[Payment Initialization DB Failure] ' . $e->getMessage());
            Response::error('We could not start the payment right now. Please try again.', 500, 'INTERNAL_SERVER_ERROR');
        } catch (\RuntimeException $e) {
            // What went wrong (keys, the gateway's own words) is for the log, not the customer.
            error_log('[Payment Initialization Gateway Error] ' . $e->getMessage());
            Response::error('We could not reach the payment provider. Your order is saved: please try again shortly or choose another payment method.', 502, 'GATEWAY_ERROR');
        } catch (\Throwable $e) {
            error_log('[Payment Initialization Failure] ' . $e->getMessage());
            Response::error('We could not start the payment right now. Please try again.', 500, 'INTERNAL_SERVER_ERROR');
        }
    }

    /**
     * Authoritatively verify payment status for an order across Monnify and Kora.
     * GET /api/v1/checkout/orders/{orderNumber}/verify-payment
     */
    public function verifyPayment(array $params): void
    {
        $orderNumber = trim($params['orderNumber'] ?? '');
        if ($orderNumber === '') {
            Response::error('Order number is required.', 422, 'VALIDATION_ERROR');
        }

        $order = $this->db->fetchOne("SELECT * FROM `orders` WHERE `order_number` = ?", 's', [$orderNumber]);
        if ($order === null) {
            Response::error('Order not found.', 404, 'ORDER_NOT_FOUND');
        }

        RateLimiter::attempt('payment_verify', RequestContext::clientIp(), 240, 600);

        $customRef = $this->returnReference();
        $this->guardEmailGuesses();
        if (!$this->canSeePaymentStatus($order, $customRef)) {
            $this->countEmailGuess();
            Response::error('Order not found.', 404, 'ORDER_NOT_FOUND');
        }

        // Which attempt to check: the exact reference from the gateway redirect, else the
        // successful payment, else the newest attempt.
        $payment = null;
        if ($customRef !== '') {
            $payment = $this->db->fetchOne(
                "SELECT * FROM `payments` WHERE `order_id` = ? AND `provider_reference` = ? LIMIT 1",
                'is',
                [(int) $order['id'], $customRef]
            );
        }
        if ($payment === null) {
            // A confirmed payment first. Then, once the customer said they sent a WhatsApp transfer,
            // that open WhatsApp attempt (a card attempt that failed afterwards must not hide it, or
            // the page would show "didn't go through" instead of waiting for the store). Otherwise
            // the newest attempt.
            $claimed = empty($order['customer_marked_paid_at']) ? 0 : 1;
            $payment = $this->db->fetchOne(
                "SELECT * FROM `payments` WHERE `order_id` = ?
                 ORDER BY FIELD(`status`, 'refunded', 'partially_refunded', 'successful') DESC,
                          (? = 1 AND `provider` = 'whatsapp' AND `status` = 'pending') DESC,
                          `id` DESC
                 LIMIT 1",
                'ii',
                [(int) $order['id'], $claimed]
            );
        }

        if ($payment === null) {
            Response::success([
                'order_number' => $order['order_number'],
                'status' => 'not_started',
                'order_status' => $order['order_status'],
                'amount' => (float) $order['grand_total'],
                'currency' => $order['currency'],
                'expires_at' => $order['expires_at'],
                'source' => 'local_database',
            ], 'No payment has been started for this order yet.');
        }

        try {
            $provider = strtolower((string) $payment['provider']);
            if ($payment['status'] === 'pending' && $provider === 'monnify') {
                $result = (new MonnifyService($this->db))->verifyTransaction($payment['provider_reference']);
            } elseif ($payment['status'] === 'pending' && $provider === 'kora') {
                $result = (new KoraService($this->db))->verifyTransaction($payment['provider_reference']);
            } else {
                $result = [
                    'order_number' => $order['order_number'],
                    'provider_reference' => $payment['provider_reference'],
                    'provider' => $provider,
                    'status' => match ($payment['status']) {
                        'successful', 'partially_refunded', 'refunded' => 'successful',
                        'failed', 'cancelled' => 'failed',
                        default => 'pending',
                    },
                    'amount' => (float) $payment['amount'],
                    'currency' => $payment['currency'] ?: $order['currency'],
                    'verified_at' => $payment['verified_at'],
                    'source' => 'local_database',
                ];
            }

            // Always report the order's real current state from the database.
            $fresh = $this->db->fetchOne("SELECT `order_status`, `payment_status`, `expires_at`, `customer_marked_paid_at` FROM `orders` WHERE `id` = ?", 'i', [(int) $order['id']]);
            $result['order_status'] = $fresh['order_status'];
            $result['order_payment_status'] = $fresh['payment_status'];
            $result['expires_at'] = $fresh['expires_at'];
            // The customer said they sent the transfer: the page waits for the store to confirm.
            $result['customer_marked_paid'] = !empty($fresh['customer_marked_paid_at']);

            Response::success($result, 'Payment status retrieved.');
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\RuntimeException $e) {
            // Gateway unreachable: report pending rather than failing the customer's status page.
            error_log('[Payment Verification Gateway Error] ' . $e->getMessage());
            Response::success([
                'order_number' => $order['order_number'],
                'provider_reference' => $payment['provider_reference'],
                'provider' => $payment['provider'],
                'status' => 'pending',
                'order_status' => $order['order_status'],
                'amount' => (float) $payment['amount'],
                'currency' => $payment['currency'] ?: $order['currency'],
                'message' => 'We could not reach the payment provider. Your payment status will update shortly.',
                'source' => 'gateway_unreachable',
            ], 'Payment status pending.');
        } catch (\Throwable $e) {
            error_log('[Payment Verification Failure] ' . $e->getMessage());
            Response::error('We could not check the payment status right now. Please try again shortly.', 500, 'INTERNAL_SERVER_ERROR');
        }
    }


    /**
     * Public endpoint to validate a promotion code server-side.
     * Enforces dates, usage limits, and active status. Computes discount safely.
     * POST /api/v1/checkout/validate-promo
     */
    public function validatePromo(): void
    {
        RateLimiter::attempt('promo_validate', RequestContext::clientIp(), 30, 600, 'Too many promo code attempts. Please wait a few minutes.');
        $body = JsonBodyMiddleware::getBody();
        $code = trim((string) ($body['code'] ?? $body['coupon_code'] ?? ''));
        $subtotal = isset($body['subtotal']) ? (float) $body['subtotal'] : 0.0;

        if ($code === '') {
            Response::error('Coupon code is required.', 422, 'VALIDATION_ERROR');
        }

        try {
            $result = $this->checkoutService->evaluateCoupon($code, $subtotal);
            Response::success([
                'valid' => true,
                'code' => $result['coupon']['code'],
                'discount_type' => $result['coupon']['discount_type'],
                'discount_percent' => $result['coupon']['discount_percent'],
                'discount_value' => $result['coupon']['discount_value'],
                'min_order_amount' => $result['coupon']['min_order_amount'],
                'max_discount_amount' => $result['coupon']['max_discount_amount'],
                'label' => $result['coupon']['label'],
                'discount_amount' => $result['discount_amount'],
                'description' => $result['coupon']['description'],
            ], "Promo code {$result['coupon']['code']} is valid!");
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'INVALID_PROMOTION');
        } catch (Throwable $e) {
            error_log('[Promo Validation Failure] ' . $e->getMessage());
            Response::error('We could not check that code right now. Please try again.', 500, 'INTERNAL_SERVER_ERROR');
        }
    }

    /**
     * Resolve the optional signed-in customer from the customer session cookie.
     * When a session is used for a state-changing request, its CSRF token must be echoed.
     */
    private function resolveOptionalUser(bool $enforceCsrf = false): ?array
    {
        $user = RequestContext::getUser();
        if ($user !== null) {
            return $user;
        }

        $resolved = SessionGuard::resolve(SessionCookie::SCOPE_CUSTOMER);
        if ($resolved === null) {
            return null;
        }
        if ($enforceCsrf) {
            SessionGuard::enforceCsrf($resolved['session']);
        }
        return $resolved['user'];
    }

    /**
     * Whether the caller may access an order: its signed-in owner, the holder of the
     * guest access token, or (when allowed) someone who knows the full checkout email.
     */
    /**
     * The customer says they sent the WhatsApp transfer ("Mark as paid"). This only alerts the
     * store (orders list, header bell, email); the order is paid once an admin confirms it.
     * POST /api/v1/checkout/orders/{orderNumber}/payment-sent
     */
    /**
     * POST /api/v1/checkout/orders/{orderNumber}/release
     * The customer went back to change their delivery details before paying: the order they
     * leave gives its items back at once instead of holding them until its deadline. Nothing
     * changes once a payment attempt exists (`released: false`); that order keeps its deadline.
     */
    public function releaseOrder(array $params): void
    {
        $orderNumber = trim($params['orderNumber'] ?? '');
        $order = $orderNumber === '' ? null : $this->db->fetchOne("SELECT * FROM `orders` WHERE `order_number` = ?", 's', [$orderNumber]);
        if ($order === null) {
            Response::error('Order not found.', 404, 'ORDER_NOT_FOUND');
        }

        RateLimiter::attempt('order_release', RequestContext::clientIp(), 30, 600);
        $body = JsonBodyMiddleware::getBody();
        if (!$this->canViewOrder($order, false, true, (string) ($body['guest_access_token'] ?? ''))) {
            Response::error('Order not found.', 404, 'ORDER_NOT_FOUND');
        }

        $released = (new FulfillmentService($this->db))->releaseUnstartedOrder((int) $order['id']);
        Response::success(
            ['order_number' => $orderNumber, 'released' => $released],
            $released ? 'The order was released.' : 'This order keeps its reservation until its payment deadline.'
        );
    }

    public function markPaymentSent(array $params): void
    {
        $orderNumber = trim($params['orderNumber'] ?? '');
        $order = $orderNumber === '' ? null : $this->db->fetchOne("SELECT * FROM `orders` WHERE `order_number` = ?", 's', [$orderNumber]);
        if ($order === null) {
            Response::error('Order not found.', 404, 'ORDER_NOT_FOUND');
        }

        RateLimiter::attempt('payment_claim', RequestContext::clientIp(), 30, 600);
        $body = JsonBodyMiddleware::getBody();
        // The checkout email counts here too (a customer who reached the order through "Track
        // order"): a claim only alerts the store once and never marks anything paid.
        $this->guardEmailGuesses();
        if (!$this->canViewOrder($order, true, true, (string) ($body['guest_access_token'] ?? ''))) {
            $this->countEmailGuess();
            Response::error('Order not found.', 404, 'ORDER_NOT_FOUND');
        }

        if ($order['order_status'] !== 'pending_payment') {
            Response::success(['order_number' => $orderNumber, 'recorded' => false, 'order_status' => $order['order_status']], 'This order is no longer awaiting payment.');
        }
        $attempt = $this->db->fetchOne(
            "SELECT `id` FROM `payments` WHERE `order_id` = ? AND `provider` = 'whatsapp' AND `status` = 'pending' LIMIT 1",
            'i',
            [(int) $order['id']]
        );
        if ($attempt === null) {
            Response::error('Send the invoice on WhatsApp first, then mark the order as paid.', 409, 'NO_WHATSAPP_PAYMENT');
        }

        $firstClaim = $order['customer_marked_paid_at'] === null;
        if ($firstClaim) {
            // Keep the items reserved while the store checks the transfer (never shortens it).
            // Only the first tap does this: tapping again can't hold the stock any longer, so an
            // order that is never paid still expires and its items go back on sale.
            $this->db->executeUpdate(
                "UPDATE `orders`
                 SET `customer_marked_paid_at` = NOW(),
                     `expires_at` = GREATEST(COALESCE(`expires_at`, NOW()), DATE_ADD(NOW(), INTERVAL ? MINUTE))
                 WHERE `id` = ? AND `order_status` = 'pending_payment' AND `customer_marked_paid_at` IS NULL",
                'ii',
                [PaymentAttempts::manualWindowMinutes(), (int) $order['id']]
            );
        }
        $fresh = $this->db->fetchOne("SELECT `customer_marked_paid_at`, `expires_at` FROM `orders` WHERE `id` = ?", 'i', [(int) $order['id']]);

        if ($firstClaim) {
            try {
                $this->db->insert(
                    "INSERT INTO `order_status_history` (`order_id`, `previous_status`, `new_status`, `comment`, `changed_by_user_id`)
                     VALUES (?, 'pending_payment', 'pending_payment', ?, NULL)",
                    'is',
                    [(int) $order['id'], 'Customer marked the WhatsApp transfer as paid. Check the bank and confirm the payment.']
                );
                MailService::getInstance()->sendPaymentClaimedToAdmins($orderNumber, (string) $fresh['customer_marked_paid_at']);
            } catch (Throwable $e) {
                error_log('[Payment claim alert] ' . $e->getMessage());
            }
        }

        Response::success([
            'order_number' => $orderNumber,
            'recorded' => true,
            'order_status' => 'pending_payment',
            'customer_marked_paid_at' => $fresh['customer_marked_paid_at'],
            'expires_at' => $fresh['expires_at'],
        ], 'Thanks! The store has been told and will confirm your transfer.');
    }

    /** The gateway reference from the return redirect (query string) or the page (header). */
    private function returnReference(): string
    {
        return trim(urldecode((string) (
            $_GET['reference'] ??
            $_GET['paymentReference'] ??
            $_GET['payment_reference'] ??
            $_GET['transactionReference'] ??
            $_GET['trxref'] ??
            $_SERVER['HTTP_X_PAYMENT_REFERENCE'] ??
            ''
        )));
    }

    /**
     * Who may see an order's payment status (verify-payment and the live status stream): the
     * session owner, the guest access key, or the exact gateway reference returned on redirect.
     * Status only, never addresses or contact details.
     */
    private function canSeePaymentStatus(array $order, string $reference): bool
    {
        // The checkout email counts as for the order lookup (the receipt page opened from
        // "Track order" with order number + email): it only reads the status.
        if ($this->canViewOrder($order, true)) {
            return true;
        }
        return $reference !== '' && $this->db->fetchOne(
            "SELECT `id` FROM `payments` WHERE `order_id` = ? AND `provider_reference` = ? LIMIT 1",
            'is',
            [(int) $order['id'], $reference]
        ) !== null;
    }

    /**
     * GET /api/v1/checkout/orders/{orderNumber}/events  (text/event-stream)
     *
     * Live payment status for the payment return page (docs/SSE_CONTRACT.md). The same access
     * rules as verify-payment. Sends the current state at once, then each change as it is
     * committed, a comment every SSE_HEARTBEAT_SECONDS, and `end` once the state is final or
     * after SSE_STREAM_SECONDS. It never asks the gateway, confirms a payment or changes the
     * order. 404 SSE_DISABLED when off; 503 SSE_BUSY when the order, visitor or server already has
     * as many streams as allowed (the page then checks the usual way).
     */
    public function paymentEvents(array $params): void
    {
        if (!LiveStatus::enabled()) {
            Response::error('Live payment status is not switched on.', 404, 'SSE_DISABLED');
        }
        RateLimiter::attempt('sse_open', RequestContext::clientIp(), 20, 600);
        $orderNumber = trim($params['orderNumber'] ?? '');
        $order = $orderNumber === '' ? null : $this->db->fetchOne("SELECT * FROM `orders` WHERE `order_number` = ?", 's', [$orderNumber]);
        $this->guardEmailGuesses();
        if ($order === null || !$this->canSeePaymentStatus($order, $this->returnReference())) {
            $this->countEmailGuess();
            Response::error('Order not found.', 404, 'ORDER_NOT_FOUND');
        }

        $live = new LiveStatus($this->db);
        $slot = $live->claimSlot((int) $order['id'], RequestContext::clientIp());
        if ($slot === null) {
            header('Retry-After: 10');
            Response::error('Too many live connections right now. The page will check the usual way.', 503, 'SSE_BUSY');
        }
        register_shutdown_function(static fn () => $live->releaseSlot($slot));

        // A plain stream: no buffering, no compression, no time limit shorter than the stream.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        @ini_set('zlib.output_compression', '0');
        set_time_limit(LiveStatus::streamSeconds() + 20);
        ignore_user_abort(false);
        header('Content-Type: text/event-stream; charset=utf-8');
        // no-transform: proxies and the storefront's own compression must pass it through as written
        // (Next.js gzips proxied answers otherwise, which holds messages back for seconds).
        header('Cache-Control: no-store, no-transform');
        header('X-Accel-Buffering: no');
        header('X-Content-Type-Options: nosniff');

        $send = static function (string $text): void {
            echo $text;
            flush();
        };
        $event = static fn (array $state): string => 'id: ' . $state['id'] . "\nevent: payment\ndata: "
            . json_encode(['order_number' => $state['order_number'], 'status' => $state['status'], 'order_status' => $state['order_status']], JSON_UNESCAPED_SLASHES)
            . "\n\n";

        $orderId = (int) $order['id'];
        $state = $live->snapshot($orderId);
        $send("retry: 3000\n\n" . $event($state));

        $started = microtime(true);
        $deadline = $started + LiveStatus::streamSeconds();
        $lastSent = $started;
        $lastRenewed = $started;
        $reason = 'time_limit';
        while (!$state['final']) {
            $elapsed = microtime(true) - $started;
            // Every second while a payment usually settles, then every two.
            usleep($elapsed < 30 ? 1_000_000 : 2_000_000);
            if (microtime(true) >= $deadline || connection_aborted()) {
                break;
            }
            if (microtime(true) - $lastRenewed >= LiveStatus::renewSeconds()) {
                $live->renewSlot($slot);
                $lastRenewed = microtime(true);
            }
            $next = $live->snapshot($orderId);
            if ($next['id'] !== $state['id']) {
                $state = $next;
                $send($event($state));
                $lastSent = microtime(true);
            } elseif (microtime(true) - $lastSent >= LiveStatus::heartbeatSeconds()) {
                $send(": keep-alive\n\n");
                $lastSent = microtime(true);
            }
        }
        if ($state['final']) {
            $reason = 'final';
        }
        $send("event: end\ndata: " . json_encode(['reason' => $reason]) . "\n\n");
        exit;
    }

    /** A checkout email came with this request (X-Order-Email header or ?email=). */
    private function emailOffered(): bool
    {
        return trim((string) ($_GET['email'] ?? $_SERVER['HTTP_X_ORDER_EMAIL'] ?? '')) !== '';
    }

    /**
     * Every endpoint that accepts the checkout email shares the order lookup's limit on failed
     * email checks (20 per 15 minutes per address), so none of them is a faster way to guess
     * which email belongs to an order number.
     */
    private function guardEmailGuesses(): void
    {
        if ($this->emailOffered()) {
            RateLimiter::guard('order_lookup_fail', RequestContext::clientIp(), 20, 900, 'Too many unsuccessful lookups. Please wait 15 minutes and try again.');
        }
    }

    private function countEmailGuess(): void
    {
        if ($this->emailOffered()) {
            RateLimiter::hit('order_lookup_fail', RequestContext::clientIp(), 900);
        }
    }

    private function canViewOrder(array $order, bool $allowEmailMatch, bool $enforceCsrf = false, string $bodyToken = ''): bool
    {
        $currentUser = $this->resolveOptionalUser($enforceCsrf);
        if ($currentUser !== null && !empty($order['user_id']) && (int) $order['user_id'] === (int) $currentUser['id']) {
            return true;
        }

        $token = trim((string) ($_SERVER['HTTP_X_GUEST_ACCESS_TOKEN'] ?? $_GET['token'] ?? $bodyToken));
        if ($token !== '' && !empty($order['guest_access_token_hash'])
            && hash_equals((string) $order['guest_access_token_hash'], hash('sha256', $token))) {
            return true;
        }

        if ($allowEmailMatch) {
            $email = strtolower(trim((string) ($_GET['email'] ?? $_SERVER['HTTP_X_ORDER_EMAIL'] ?? '')));
            $orderEmail = strtolower(trim((string) ($order['guest_email'] ?? '')));
            if ($email !== '' && $orderEmail !== '' && hash_equals($orderEmail, $email)) {
                return true;
            }
        }

        return false;
    }
}
