<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Input;
use App\Support\PhoneNumber;
use App\Support\ValidationFailed;
use App\Config\Env;
use App\Config\Shop;
use App\Database\Database;
use RuntimeException;
use InvalidArgumentException;

/**
 * Service orchestrating server-side checkout quotation, shipping zone resolution,
 * inventory reservation, safe financial calculation, and transactional order creation.
 */
class CheckoutService
{
    private const MAX_LINES = 30;
    private const MAX_QUANTITY_PER_LINE = 20;
    private const MAX_NOTES = 2000;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Compute a server-side verified checkout quote.
     * Evaluates real-time product prices, variant stock, active shipping zone fees,
     * and valid promotional discounts.
     *
     * @param array $input Payload containing items, shipping_address, shipping_zone_id, coupon_code
     * @return array Calculated breakdown with verified items, subtotal, discount, shipping, grand total
     */
    public function getQuote(array $input): array
    {
        $items = $input['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            throw new InvalidArgumentException('Cart is empty. At least one item is required to generate a checkout quote.');
        }

        // 1. Verify and price all items from MySQL
        $verifiedItems = $this->verifyAndPriceItems($items);

        // 2. Compute subtotal
        $subtotal = 0.0;
        foreach ($verifiedItems as $item) {
            $subtotal += $item['line_total'];
        }
        $subtotal = round($subtotal, 2);

        // 3. Resolve shipping destination zone and fee
        $shippingAddress = $input['shipping_address'] ?? null;
        $requestedZoneId = isset($input['shipping_zone_id']) ? (int) $input['shipping_zone_id'] : null;

        $shippingResolution = null;
        $shippingFee = 0.0;

        $heavyItems = self::heavyQuantity($verifiedItems);
        if (is_array($shippingAddress) && !empty($shippingAddress['state'])) {
            $shippingResolution = $this->priceZones(
                $this->resolveShippingZone($shippingAddress, $requestedZoneId),
                (int) array_sum(array_column($verifiedItems, 'quantity')),
                $heavyItems
            );
            $shippingFee = (float) $shippingResolution['selected_zone']['fee'];
        }

        // 4. Validate and apply coupon if provided
        $couponCode = isset($input['coupon_code']) ? trim((string) $input['coupon_code']) : '';
        $promoResult = null;
        $discountAmount = 0.0;

        if ($couponCode !== '') {
            $promoResult = $this->evaluateCoupon($couponCode, $subtotal);
            $discountAmount = $promoResult['discount_amount'];
        }

        // 5. Safe Grand Total Calculation
        $grandTotal = round(max(0.0, $subtotal - $discountAmount + $shippingFee), 2);
        if ($grandTotal <= 0) {
            // Nothing to charge: the gateways refuse a zero amount, so such an order could never be paid.
            throw new InvalidArgumentException('This order would cost ₦0. A promo code cannot cover the whole order: remove the code or add more items.');
        }

        // 6. Fetch store origin for reference
        $origin = $this->getStoreOrigin();

        return [
            'currency' => 'NGN',
            'items' => $verifiedItems,
            'items_count' => count($verifiedItems),
            'total_quantity' => array_sum(array_column($verifiedItems, 'quantity')),
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'shipping_fee' => $shippingFee,
            'grand_total' => $grandTotal,
            'applied_coupon' => $promoResult ? $promoResult['coupon'] : null,
            'shipping_zone' => $shippingResolution ? $shippingResolution['selected_zone'] : null,
            'eligible_zones' => $shippingResolution ? $shippingResolution['eligible_zones'] : [],
            'origin' => $origin,
            // How many of the items are heavy ones that add to the delivery fee (0 unless the shop
            // charges for them), and whether the buyer must confirm their age to place this order.
            'heavy_items' => $heavyItems,
            'age_check_required' => self::needsAgeCheck($verifiedItems),
            'minimum_age' => Shop::minimumAge(),
        ];
    }

    /**
     * The total quantity of the heavy items among these verified lines. Always 0 in a shop that
     * does not charge for them (config/shop.json, features.heavyDelivery).
     *
     * @param array<int, array<string, mixed>> $verifiedItems
     */
    private static function heavyQuantity(array $verifiedItems): int
    {
        if (!Shop::feature('heavyDelivery')) {
            return 0;
        }
        $heavy = 0;
        foreach ($verifiedItems as $item) {
            $heavy += !empty($item['is_heavy']) ? (int) $item['quantity'] : 0;
        }
        return $heavy;
    }

    /**
     * True when one of these verified lines is from a category only adults may buy from.
     *
     * @param array<int, array<string, mixed>> $verifiedItems
     */
    private static function needsAgeCheck(array $verifiedItems): bool
    {
        foreach ($verifiedItems as $item) {
            if (!empty($item['age_restricted'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create an order in MySQL inside an atomic database transaction.
     * Enforces the documented Immediate Atomic Reservation Policy for inventory,
     * validates all customer/guest details, shipping address, and persists snapshots.
     *
     * @param array $input Order payload
     * @param int|null $userId Authenticated user ID (or null for guest)
     * @return array Created order details including public order number and guest token
     */
    /** Store setting: how many unpaid orders one customer can hold at once (0 = no cap). */
    public const UNPAID_CAP_SETTING = 'max_unpaid_orders';
    public const UNPAID_CAP_DEFAULT = 2;
    public const UNPAID_CAP_MAX = 20;

    /** The cap in force: the owner's setting, or the default when it was never saved. */
    public function unpaidOrderCap(): int
    {
        $row = $this->db->fetchOne("SELECT `value` FROM `store_settings` WHERE `key_name` = ? LIMIT 1", 's', [self::UNPAID_CAP_SETTING]);
        $value = $row === null ? null : Input::wholeNumber($row['value'], 0, self::UNPAID_CAP_MAX);
        return $value ?? self::UNPAID_CAP_DEFAULT;
    }

    /**
     * Stop one customer holding stock with order after order they do not pay for. Counts their
     * orders still awaiting payment (and still inside their payment window) by account, email,
     * phone or device, and refuses a new one at the cap. Paying for an order, cancelling it, or
     * its window closing frees a place.
     */
    private function assertUnderUnpaidCap(?int $userId, string $email, string $phone, ?string $deviceHash): void
    {
        $cap = $this->unpaidOrderCap();
        if ($cap <= 0) {
            return;
        }
        $who = ['o.`guest_email` = ?'];
        $types = 's';
        $params = [$email];
        if ($userId !== null) {
            $who[] = 'o.`user_id` = ?';
            $types .= 'i';
            $params[] = $userId;
        }
        if ($phone !== '') {
            $who[] = 'o.`contact_phone` = ?';
            $types .= 's';
            $params[] = $phone;
        }
        if ($deviceHash !== null) {
            $who[] = 'o.`device_hash` = ?';
            $types .= 's';
            $params[] = $deviceHash;
        }
        $held = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM `orders` o
             WHERE o.`order_status` = 'pending_payment' AND (o.`expires_at` IS NULL OR o.`expires_at` > NOW())
               AND (" . implode(' OR ', $who) . ")",
            $types,
            $params
        )['c'] ?? 0);
        if ($held >= $cap) {
            throw new RuntimeException(
                "You already have {$held} " . ($held === 1 ? 'order' : 'orders') . ' waiting for payment. Pay for '
                . ($held === 1 ? 'it' : 'one of them') . ' from Track order, or wait for it to expire, then place this order.'
            );
        }
    }

    /** SHA-256 of the browser's device id (X-Device-Id), or null when it is missing or malformed. */
    public static function deviceHash(?string $deviceId): ?string
    {
        $deviceId = trim((string) $deviceId);
        return preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $deviceId) === 1 ? hash('sha256', $deviceId) : null;
    }

    public function createOrder(array $input, ?int $userId = null, ?string $idempotencyKey = null, ?string $deviceId = null): array
    {
        // Release stock held by abandoned orders before reserving more (cheap, bounded).
        try {
            (new FulfillmentService($this->db))->expireOverdueOrders(5);
        } catch (\Throwable $e) {
            error_log('[Order Expiry Sweep] ' . $e->getMessage());
        }

        // 1. Basic validation of contact & customer identity
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email address is required for order confirmation and receipts.');
        }

        // A repeated submission (double click, retry after a dropped response) returns the same order.
        $idempotencyKey = $idempotencyKey !== null ? trim($idempotencyKey) : null;
        if ($idempotencyKey !== null && !preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $idempotencyKey)) {
            $idempotencyKey = null;
        }
        if ($idempotencyKey !== null) {
            $replay = $this->replayIdempotentOrder($idempotencyKey, $email);
            if ($replay !== null) {
                return $replay;
            }
        }

        $items = $input['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            throw new InvalidArgumentException('Cart is empty. Order cannot be placed without items.');
        }

        // 2. Physical delivery address is mandatory for all orders
        $shippingAddress = $input['shipping_address'] ?? null;
        if (!is_array($shippingAddress)) {
            throw new InvalidArgumentException('Delivery address is required for all orders.');
        }
        $this->validateShippingAddressFields($shippingAddress);
        if (Input::tooLong($input['notes'] ?? null, self::MAX_NOTES)) {
            $message = 'Order notes can be at most ' . number_format(self::MAX_NOTES) . ' characters.';
            throw new ValidationFailed($message, ['notes' => $message]);
        }

        // A customer who already holds the allowed number of unpaid orders pays for one first.
        $contactPhone = PhoneNumber::stored(Input::text($shippingAddress['phone'] ?? null));
        $deviceHash = self::deviceHash($deviceId);
        $this->assertUnderUnpaidCap($userId, $email, $contactPhone, $deviceHash);

        // 3. Resolve and revalidate destination shipping zone. Its fee is worked out inside the
        // transaction, from the lines as the database knows them (how many items, and how many
        // of them are heavy), never from what the request says about them.
        $requestedZoneId = isset($input['shipping_zone_id']) ? (int) $input['shipping_zone_id'] : null;
        $zoneResolution = $this->resolveShippingZone($shippingAddress, $requestedZoneId);

        // 4. Execute transactional order creation with atomic inventory decrement
        try {
            $createdOrder = $this->db->transaction(function (Database $db) use (
                $input,
                $userId,
                $email,
                $items,
                $shippingAddress,
                $zoneResolution,
                $idempotencyKey,
                $contactPhone,
                $deviceHash
            ): array {
                // A. Re-verify items and load lock-safe current pricing
                $verifiedItems = $this->verifyAndPriceItems($items);

                // Items only adults may buy: the buyer has to say they are old enough, on every order.
                if (self::needsAgeCheck($verifiedItems) && ($input['age_confirmed'] ?? null) !== true) {
                    $message = 'Please confirm that you are ' . Shop::minimumAge() . ' or older to order these items.';
                    throw new ValidationFailed($message, ['age_confirmed' => $message]);
                }

                // The fee grows with the number of items, and with each heavy one where the shop charges for those.
                $heavyItems = self::heavyQuantity($verifiedItems);
                $selectedZone = $this->priceZones(
                    $zoneResolution,
                    (int) array_sum(array_column($verifiedItems, 'quantity')),
                    $heavyItems
                )['selected_zone'];
                $shippingFee = (float) $selectedZone['fee'];

                $subtotal = 0.0;
                foreach ($verifiedItems as $item) {
                    $subtotal += $item['line_total'];
                }
                $subtotal = round($subtotal, 2);

                // B. Validate and apply coupon inside transaction
                $couponCode = isset($input['coupon_code']) ? trim((string) $input['coupon_code']) : '';
                $promoId = null;
                $appliedPromoCode = null;
                $discountAmount = 0.0;

                if ($couponCode !== '') {
                    $promoResult = $this->evaluateCoupon($couponCode, $subtotal);
                    $promoId = (int) $promoResult['coupon']['id'];
                    $appliedPromoCode = $promoResult['coupon']['code'];
                    $discountAmount = $promoResult['discount_amount'];

                    // Atomically count this order's promotion use (once; released again if the order
                    // is cancelled or expires). max_uses = 0 means unlimited.
                    $promoUpdated = $db->executeUpdate(
                        "UPDATE `promotions` SET `used_count` = `used_count` + 1
                         WHERE `id` = ? AND (`max_uses` = 0 OR `used_count` < `max_uses`)",
                        'i',
                        [$promoId]
                    );
                    if ($promoUpdated === 0) {
                        throw new RuntimeException("Promotion code '{$appliedPromoCode}' reached its maximum usage limit during checkout.");
                    }
                }

                // C. Calculate final grand total
                $grandTotal = round(max(0.0, $subtotal - $discountAmount + $shippingFee), 2);
                if ($grandTotal <= 0) {
                    throw new InvalidArgumentException('This order would cost ₦0. A promo code cannot cover the whole order: remove the code or add more items.');
                }

                // D. ATOMIC INVENTORY RESERVATION POLICY
                // We decrement variant stock atomically using conditional WHERE stock_quantity >= requested.
                // Under MySQL InnoDB, this acquires row-level exclusive locks and guarantees
                // no negative stock and zero concurrent overselling.
                foreach ($verifiedItems as $item) {
                    $variantId = $item['variant_id'];
                    $qty = $item['quantity'];

                    $affectedRows = $db->executeUpdate(
                        "UPDATE `product_variants` 
                         SET `stock_quantity` = `stock_quantity` - ? 
                         WHERE `id` = ? AND `stock_quantity` >= ?",
                        'iii',
                        [$qty, $variantId, $qty]
                    );

                    if ($affectedRows === 0) {
                        // Fetch latest actual stock for error message. A locking read: a plain SELECT
                        // here returns this transaction's snapshot, which can still show stock that
                        // other orders took while this one waited (the failed UPDATE already holds the row).
                        $latestStock = $db->fetchOne("SELECT `stock_quantity`, `sku` FROM `product_variants` WHERE `id` = ? FOR UPDATE", 'i', [$variantId]);
                        $sku = $latestStock['sku'] ?? $item['sku'];
                        $available = $latestStock['stock_quantity'] ?? 0;
                        throw new RuntimeException("Insufficient stock for item '{$item['name']}' ({$sku}). Requested: {$qty}, Available: {$available}.");
                    }
                }

                // E. Generate unique public order number (ORD-YYYYMMDD-XXXXXX)
                $orderNumber = $this->generateUniqueOrderNumber($db);

                // F. Generate high-entropy guest access token (32 random bytes = 256 bits)
                $guestAccessToken = bin2hex(random_bytes(32));
                $guestAccessTokenHash = hash('sha256', $guestAccessToken);

                // G. Prepare snapshots
                $origin = $this->getStoreOrigin();
                $zoneSnapshot = [
                    'id' => (int) $selectedZone['id'],
                    'name' => $selectedZone['name'],
                    'code' => $selectedZone['code'],
                    'fee' => (float) $selectedZone['fee'],
                    'base_fee' => (float) $selectedZone['base_fee'],
                    'items_included' => (int) $selectedZone['items_included'],
                    'extra_item_fee' => (float) $selectedZone['extra_item_fee'],
                    'heavy_item_fee' => (float) $selectedZone['heavy_item_fee'],
                    'heavy_items' => $heavyItems,
                    'estimated_days' => $selectedZone['estimated_days'],
                    'origin' => $origin,
                ];

                $addressSnapshot = [
                    'full_name' => Input::text($shippingAddress['full_name'] ?? null),
                    'phone' => PhoneNumber::stored(Input::text($shippingAddress['phone'] ?? null)),
                    'address_line1' => Input::text($shippingAddress['address_line1'] ?? null),
                    'address_line2' => Input::text($shippingAddress['address_line2'] ?? null),
                    'city' => Input::text($shippingAddress['city'] ?? null),
                    'state' => Input::text($shippingAddress['state'] ?? null),
                    'postal_code' => Input::text($shippingAddress['postal_code'] ?? null),
                    'country' => Input::text($shippingAddress['country'] ?? null, 'Nigeria'),
                ];
                // Optional delivery pin the customer shared from their phone ("Use my current
                // location"): kept only when it is a real coordinate, for the rider's map.
                $pin = self::deliveryPin($shippingAddress['location'] ?? null);
                if ($pin !== null) {
                    $addressSnapshot['location'] = $pin;
                }

                $notes = isset($input['notes']) ? Input::text($input['notes']) : null;

                // H. Insert Order (stock stays reserved until expires_at unless the order is paid)
                $orderId = (int) $db->insert(
                    "INSERT INTO `orders` (
                        `order_number`, `user_id`, `guest_email`, `contact_phone`, `device_hash`, `guest_access_token_hash`,
                        `shipping_zone_id`, `shipping_zone_snapshot`, `shipping_address_snapshot`,
                        `order_status`, `payment_status`, `currency`, `subtotal`, `discount_amount`,
                        `shipping_fee`, `grand_total`, `applied_promo_code`, `notes`, `expires_at`, `idempotency_key`
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_payment', 'pending', 'NGN', ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)",
                    'sissssissddddssis',
                    [
                        $orderNumber,
                        $userId,
                        $email,
                        $contactPhone !== '' ? $contactPhone : null,
                        $deviceHash,
                        $guestAccessTokenHash,
                        (int) $selectedZone['id'],
                        json_encode($zoneSnapshot),
                        json_encode($addressSnapshot),
                        $subtotal,
                        $discountAmount,
                        $shippingFee,
                        $grandTotal,
                        $appliedPromoCode,
                        $notes,
                        PaymentAttempts::gatewayWindowMinutes(),
                        $idempotencyKey,
                    ]
                );

                // I. Insert Order Items snapshots
                foreach ($verifiedItems as $item) {
                    $db->insert(
                        "INSERT INTO `order_items` (
                            `order_id`, `product_id`, `variant_id`, `product_type`,
                            `item_name`, `sku`, `variant_options`, `unit_price`, `quantity`, `line_total`
                         ) VALUES (?, ?, ?, 'physical', ?, ?, ?, ?, ?, ?)",
                        'iiisssdid',
                        [
                            $orderId,
                            $item['product_id'],
                            $item['variant_id'],
                            $item['name'],
                            $item['sku'],
                            json_encode($item['variant_options']),
                            $item['unit_price'],
                            $item['quantity'],
                            $item['line_total'],
                        ]
                    );
                }

                // J. Insert initial Order Status History record
                $db->insert(
                    "INSERT INTO `order_status_history` (`order_id`, `previous_status`, `new_status`, `comment`, `changed_by_user_id`)
                     VALUES (?, '', 'pending_payment', 'Order created. Inventory reserved. Pending payment verification.', ?)",
                    'ii',
                    [$orderId, $userId]
                );

                // K. No payment row yet: each gateway attempt creates its own when it starts
                //    (POST /checkout/orders/{n}/initialize-payment).
                $expiresRow = $db->fetchOne("SELECT `expires_at`, `created_at` FROM `orders` WHERE `id` = ?", 'i', [$orderId]);

                $createdOrder = [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'guest_access_token' => $guestAccessToken,
                    'guest_email' => $email,
                    'status' => 'pending_payment',
                    'payment_status' => 'pending',
                    'currency' => 'NGN',
                    'subtotal' => $subtotal,
                    'discount_amount' => $discountAmount,
                    'shipping_fee' => $shippingFee,
                    'grand_total' => $grandTotal,
                    'shipping_zone' => $zoneSnapshot,
                    'shipping_address' => $addressSnapshot,
                    'items_count' => count($verifiedItems),
                    'items' => $verifiedItems,
                    'expires_at' => $expiresRow['expires_at'] ?? null,
                    'created_at' => $expiresRow['created_at'] ?? date('Y-m-d H:i:s'),
                ];

                // Order-received email goes out only once the order is committed.
                $db->afterCommit(static function () use ($createdOrder): void {
                    MailService::getInstance()->sendOrderReceived($createdOrder);
                    MailService::getInstance()->sendNewOrderToAdmins((string) $createdOrder['order_number']);
                });

                return $createdOrder;
            });
        } catch (RuntimeException $e) {
            // Two concurrent submissions with the same idempotency key: the loser replays the winner.
            // It may fail on the key itself, or sooner: when the winner took the last unit, the loser
            // sees "Insufficient stock" for an order that was in fact placed.
            if ($idempotencyKey !== null) {
                $replay = $this->replayIdempotentOrder($idempotencyKey, $email);
                if ($replay !== null) {
                    return $replay;
                }
            }
            throw $e;
        }

        return $createdOrder;
    }

    /**
     * Return the order previously created with this idempotency key, issuing a fresh guest
     * access token (only the hash is stored, so the original token cannot be re-sent).
     */
    private function replayIdempotentOrder(string $idempotencyKey, string $email): ?array
    {
        $order = $this->db->fetchOne("SELECT * FROM `orders` WHERE `idempotency_key` = ?", 's', [$idempotencyKey]);
        if ($order === null) {
            return null;
        }
        if (strtolower((string) $order['guest_email']) !== $email) {
            throw new InvalidArgumentException('This checkout session was already used for a different order. Please refresh and try again.');
        }

        $guestAccessToken = bin2hex(random_bytes(32));
        $this->db->executeUpdate(
            "UPDATE `orders` SET `guest_access_token_hash` = ? WHERE `id` = ?",
            'si',
            [hash('sha256', $guestAccessToken), (int) $order['id']]
        );

        $items = $this->db->fetchAll(
            "SELECT `product_id`, `variant_id`, `item_name` AS `name`, `sku`, `variant_options`, `unit_price`, `quantity`, `line_total`
             FROM `order_items` WHERE `order_id` = ?",
            'i',
            [(int) $order['id']]
        );
        foreach ($items as &$item) {
            $item['variant_options'] = json_decode((string) $item['variant_options'], true);
            $item['unit_price'] = (float) $item['unit_price'];
            $item['line_total'] = (float) $item['line_total'];
            $item['quantity'] = (int) $item['quantity'];
        }
        unset($item);

        return [
            'order_id' => (int) $order['id'],
            'order_number' => $order['order_number'],
            'guest_access_token' => $guestAccessToken,
            'guest_email' => $order['guest_email'],
            'status' => $order['order_status'],
            'payment_status' => $order['payment_status'],
            'currency' => $order['currency'],
            'subtotal' => (float) $order['subtotal'],
            'discount_amount' => (float) $order['discount_amount'],
            'shipping_fee' => (float) $order['shipping_fee'],
            'grand_total' => (float) $order['grand_total'],
            'shipping_zone' => json_decode((string) $order['shipping_zone_snapshot'], true),
            'shipping_address' => json_decode((string) $order['shipping_address_snapshot'], true),
            'items_count' => count($items),
            'items' => $items,
            'expires_at' => $order['expires_at'],
            'created_at' => $order['created_at'],
            'replayed' => true,
        ];
    }

    /**
     * Verify items against MySQL products and variants.
     * Enforces published status, loads real database prices, verifies variants,
     * and calculates line totals.
     *
     * @param array $items Raw items from request
     * @return array Array of validated item details
     */
    private function verifyAndPriceItems(array $items): array
    {
        $verified = [];

        if (count($items) > self::MAX_LINES) {
            throw new InvalidArgumentException('Too many different items in one order (maximum ' . self::MAX_LINES . ').');
        }

        foreach ($items as $index => $item) {
            $productId = isset($item['product_id']) ? (int) $item['product_id'] : (isset($item['productId']) ? (int) $item['productId'] : 0);
            $variantId = isset($item['variant_id']) ? (int) $item['variant_id'] : (isset($item['variantId']) ? (int) $item['variantId'] : null);
            $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 0;

            if ($productId <= 0) {
                throw new InvalidArgumentException("Item at index {$index} must specify a valid product_id.");
            }

            if ($quantity <= 0 || $quantity > self::MAX_QUANTITY_PER_LINE) {
                throw new InvalidArgumentException("Invalid quantity '{$quantity}' for product ID {$productId}. Quantity must be between 1 and " . self::MAX_QUANTITY_PER_LINE . '.');
            }

            // 1. Fetch product from database
            $product = $this->db->fetchOne(
                "SELECT p.`id`, p.`name`, p.`slug`, p.`status`, p.`product_type`, p.`base_price`, p.`currency`, p.`sku`,
                        p.`warranty`, p.`is_heavy`, c.`slug` AS `category_slug`
                 FROM `products` p LEFT JOIN `categories` c ON c.`id` = p.`category_id` WHERE p.`id` = ?",
                'i',
                [$productId]
            );

            // The same answer for all of these: a product's name and status stay private until it
            // is published, and an ID can't be tested to see if a product exists.
            if ($product === null || $product['status'] !== 'published') {
                throw new InvalidArgumentException('An item in your cart is no longer available. Please remove it and try again.');
            }

            // 2. Resolve the exact variant the customer chose. Never substitute a different one.
            $variantColumns = "`id`, `product_id`, `sku`, `size`, `color_name`, `color_hex`, `price_override`, `stock_quantity`";
            $variant = null;
            if ($variantId !== null && $variantId > 0) {
                $variant = $this->db->fetchOne(
                    "SELECT {$variantColumns} FROM `product_variants` WHERE `id` = ? AND `product_id` = ? AND `is_active` = 1",
                    'ii',
                    [$variantId, $productId]
                );
                if ($variant === null) {
                    throw new InvalidArgumentException("The selected option for '{$product['name']}' is no longer available. Please choose another " . Shop::option1Word() . ' or ' . Shop::option2Word() . '.');
                }
            } else {
                // Legacy carts without a variant_id: accept only an exact size + colour match, or a
                // product that has a single option.
                $size = isset($item['size']) ? trim((string) $item['size']) : '';
                $color = isset($item['color']) ? (is_array($item['color']) ? trim((string) ($item['color']['name'] ?? '')) : trim((string) $item['color'])) : '';

                if ($size !== '' && $color !== '') {
                    $variant = $this->db->fetchOne(
                        "SELECT {$variantColumns} FROM `product_variants`
                         WHERE `product_id` = ? AND `is_active` = 1 AND LOWER(`size`) = LOWER(?) AND LOWER(`color_name`) = LOWER(?)
                         LIMIT 1",
                        'iss',
                        [$productId, $size, $color]
                    );
                }

                if ($variant === null) {
                    $activeVariants = $this->db->fetchAll(
                        "SELECT {$variantColumns} FROM `product_variants` WHERE `product_id` = ? AND `is_active` = 1 LIMIT 2",
                        'i',
                        [$productId]
                    );
                    if (count($activeVariants) === 1) {
                        $variant = $activeVariants[0];
                    }
                }

                if ($variant === null) {
                    $chosen = trim($size . ' / ' . $color, ' /');
                    throw new InvalidArgumentException(
                        'Please choose an available ' . Shop::option1Word() . ' and ' . Shop::option2Word() . " for '{$product['name']}'"
                        . ($chosen !== '' ? " ('{$chosen}' is not offered)." : '.')
                    );
                }
            }

            // 3. Check current available inventory
            $availableStock = (int) $variant['stock_quantity'];
            if ($availableStock < $quantity) {
                throw new RuntimeException("Insufficient stock for '{$product['name']}' ({$variant['sku']}). Only {$availableStock} available, {$quantity} requested.");
            }

            // 4. Safe price resolution: only positive (> 0) price_override overrides the base_price
            $hasOverride = $variant['price_override'] !== null && (float) $variant['price_override'] > 0;
            $unitPrice = $hasOverride ? (float) $variant['price_override'] : (float) $product['base_price'];
            $lineTotal = round($unitPrice * $quantity, 2);

            $variantOptions = [
                'size' => $variant['size'],
                'color' => $variant['color_name'],
                'color_hex' => $variant['color_hex'],
            ];
            // The warranty the item is sold with stays on the order line, so a later change to the
            // product never rewrites what this customer was promised.
            $warranty = Input::text($product['warranty'] ?? null);
            if ($warranty !== '' && Shop::feature('warranty')) {
                $variantOptions['warranty'] = $warranty;
            }

            $verified[] = [
                'product_id' => (int) $product['id'],
                'variant_id' => (int) $variant['id'],
                'name' => $product['name'],
                'slug' => $product['slug'],
                'sku' => $variant['sku'],
                'variant_options' => $variantOptions,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
                'stock_available' => $availableStock,
                'is_heavy' => (bool) ($product['is_heavy'] ?? false),
                'age_restricted' => Shop::isAgeRestricted($product['category_slug'] ?? null),
            ];
        }

        return $verified;
    }

    /**
     * Resolve delivery address location against active database shipping zones.
     * Prevents arbitrary or invented shipping rates. Returns error if location is unserved.
     * Never silently applies free shipping!
     *
     * @param array $address Destination delivery address
     * @param int|null $requestedZoneId Optional zone identifier picked by customer
     * @return array{selected_zone: array, eligible_zones: array}
     */
    /**
     * A delivery pin from the storefront ({latitude, longitude, accuracy_m}) when it is a real
     * coordinate; anything else is dropped (the address itself is what the order relies on).
     *
     * @return array{latitude: float, longitude: float, accuracy_m: int|null}|null
     */
    public static function deliveryPin(mixed $location): ?array
    {
        if (!is_array($location) || !is_numeric($location['latitude'] ?? null) || !is_numeric($location['longitude'] ?? null)) {
            return null;
        }
        $lat = (float) $location['latitude'];
        $lng = (float) $location['longitude'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
            return null;
        }
        $accuracy = is_numeric($location['accuracy_m'] ?? null) ? max(0, min(100000, (int) round((float) $location['accuracy_m']))) : null;
        return ['latitude' => round($lat, 6), 'longitude' => round($lng, 6), 'accuracy_m' => $accuracy];
    }

    /**
     * Delivery fee for this many items: the zone's fee covers the first `items_included` items,
     * and each item after that adds `extra_item_fee`. In a shop that charges for heavy items
     * (config/shop.json, features.heavyDelivery) each of those adds `heavy_item_fee` on top.
     *
     * @param array<string, mixed> $zone
     */
    public static function deliveryFee(array $zone, int $quantity, int $heavyQuantity = 0): float
    {
        $base = (float) ($zone['base_fee'] ?? $zone['fee'] ?? 0);
        $included = max(1, (int) ($zone['items_included'] ?? 1));
        $extra = max(0.0, (float) ($zone['extra_item_fee'] ?? 0));
        $heavy = Shop::feature('heavyDelivery')
            ? max(0, $heavyQuantity) * max(0.0, (float) ($zone['heavy_item_fee'] ?? 0))
            : 0.0;
        return round($base + max(0, $quantity - $included) * $extra + $heavy, 2);
    }

    /**
     * Put this order's delivery fee on the selected and eligible zones (`fee`), keeping the
     * zone's own fee as `base_fee`.
     *
     * @param array{selected_zone: array, eligible_zones: array} $resolution
     */
    private function priceZones(array $resolution, int $quantity, int $heavyQuantity = 0): array
    {
        $price = static function (array $zone) use ($quantity, $heavyQuantity): array {
            $zone['base_fee'] = (float) ($zone['base_fee'] ?? $zone['fee']);
            $zone['items_included'] = max(1, (int) ($zone['items_included'] ?? 1));
            $zone['extra_item_fee'] = (float) ($zone['extra_item_fee'] ?? 0);
            $zone['heavy_item_fee'] = (float) ($zone['heavy_item_fee'] ?? 0);
            $zone['fee'] = self::deliveryFee($zone, $quantity, $heavyQuantity);
            return $zone;
        };
        $resolution['selected_zone'] = $price($resolution['selected_zone']);
        $resolution['eligible_zones'] = array_map($price, $resolution['eligible_zones'] ?? []);
        return $resolution;
    }

    public function resolveShippingZone(array $address, ?int $requestedZoneId = null): array
    {
        $country = Input::text($address['country'] ?? null);
        $state = Input::text($address['state'] ?? null);

        if ($state === '') {
            throw new InvalidArgumentException('Destination state is required to calculate shipping.');
        }

        // Only real states can match a zone; a typo must never fall through to the nationwide fee.
        $canonicalState = self::canonicalState($state);
        if ($canonicalState === null) {
            throw new InvalidArgumentException("'{$state}' is not a Nigerian state. Please choose your state from the list.");
        }
        $state = $canonicalState;

        // Domestic Nigeria store policy check
        $validCountryNames = ['nigeria', 'ng', 'nga'];
        if ($country !== '' && !in_array(strtolower($country), $validCountryNames, true)) {
            throw new InvalidArgumentException("Delivery is currently only supported within Nigeria. Country '{$country}' is unserved.");
        }

        // Fetch all active shipping zones configured by store administration
        $allActiveZones = $this->db->fetchAll("SELECT * FROM `shipping_zones` WHERE `is_active` = 1 ORDER BY `id` ASC");
        if (empty($allActiveZones)) {
            throw new RuntimeException('No active delivery destination zones are currently configured by store administration.');
        }

        // Decode states_covered JSON
        $normalizedState = strtolower($state);
        $directMatches = [];
        $wildcardMatches = [];

        foreach ($allActiveZones as $zone) {
            $statesCovered = is_string($zone['states_covered']) ? json_decode($zone['states_covered'], true) : $zone['states_covered'];
            if (!is_array($statesCovered)) {
                $statesCovered = [];
            }

            $zone['fee'] = (float) $zone['fee'];
            $zone['states_covered'] = $statesCovered;

            $hasDirectMatch = false;
            $hasWildcard = false;

            foreach ($statesCovered as $covered) {
                $coveredTrimmed = strtolower(trim((string) $covered));
                if ($coveredTrimmed === '*') {
                    $hasWildcard = true;
                } elseif (strtolower((string) (self::canonicalState($coveredTrimmed) ?? $coveredTrimmed)) === $normalizedState) {
                    $hasDirectMatch = true;
                    break;
                }
            }

            if ($hasDirectMatch) {
                $directMatches[] = $zone;
            } elseif ($hasWildcard) {
                $wildcardMatches[] = $zone;
            }
        }

        // Specific state zones take priority over nationwide wildcard zones
        $eligibleZones = !empty($directMatches) ? $directMatches : $wildcardMatches;

        if (empty($eligibleZones)) {
            throw new InvalidArgumentException("Destination state '{$state}' is currently unserved by our logistics network. Free delivery is never silently applied.");
        }

        // If client specified a zone ID, revalidate that it is an eligible zone
        $selectedZone = null;
        if ($requestedZoneId !== null && $requestedZoneId > 0) {
            foreach ($eligibleZones as $zone) {
                if ((int) $zone['id'] === $requestedZoneId) {
                    $selectedZone = $zone;
                    break;
                }
            }

            if ($selectedZone === null) {
                throw new InvalidArgumentException("Selected shipping zone ID {$requestedZoneId} is not valid for destination state '{$state}'.");
            }
        } else {
            // Default to the first eligible zone
            $selectedZone = $eligibleZones[0];
        }

        return [
            'selected_zone' => $selectedZone,
            'eligible_zones' => $eligibleZones,
        ];
    }

    /** The 36 states and the Federal Capital Territory. */
    public const NIGERIAN_STATES = [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 'Cross River',
        'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano',
        'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo',
        'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara',
    ];

    /**
     * Map user/admin spellings to one canonical state name, or null if it is not a state.
     * "Abuja", "FCT", "Abuja (FCT)" and "Federal Capital Territory" are the same place.
     */
    public static function canonicalState(string $value): ?string
    {
        $v = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
        $v = preg_replace('/\s+state$/', '', $v) ?? $v;

        if (in_array($v, ['fct', 'abuja', 'abuja (fct)', 'abuja fct', 'federal capital territory', 'abuja federal capital territory'], true)) {
            return 'FCT';
        }
        if ($v === 'nassarawa') {
            return 'Nasarawa';
        }
        if ($v === 'akwa-ibom') {
            return 'Akwa Ibom';
        }

        foreach (self::NIGERIAN_STATES as $state) {
            if (strtolower($state) === $v) {
                return $state;
            }
        }
        return null;
    }

    /**
     * Validate and calculate discount for a promotional coupon code.
     *
     * @param string $couponCode
     * @param float $subtotal
     * @return array{coupon: array, discount_amount: float}
     */
    public function evaluateCoupon(string $couponCode, float $subtotal): array
    {
        $code = strtoupper(trim($couponCode));
        if ($code === '') {
            throw new InvalidArgumentException('Coupon code cannot be empty.');
        }

        $promo = $this->db->fetchOne(
            "SELECT `id`, `code`, `description`, `discount_type`, `discount_percent`, `discount_amount`, `min_order_amount`, `max_discount_amount`,
                    `max_uses`, `used_count`, `is_active`, `starts_at`, `expires_at`
             FROM `promotions` WHERE `code` = ?",
            's',
            [$code]
        );

        if ($promo === null || !(bool) $promo['is_active']) {
            throw new InvalidArgumentException("Coupon code '{$code}' is invalid or has been deactivated.");
        }

        // Check usage limits
        $maxUses = (int) $promo['max_uses'];
        $usedCount = (int) $promo['used_count'];
        if ($maxUses > 0 && $usedCount >= $maxUses) {
            throw new InvalidArgumentException("Coupon code '{$code}' has reached its maximum usage limit.");
        }

        // Check start and expiry dates
        $now = time();
        if (!empty($promo['starts_at']) && strtotime((string) $promo['starts_at']) > $now) {
            throw new InvalidArgumentException("Coupon code '{$code}' is not yet active.");
        }
        if (!empty($promo['expires_at']) && strtotime((string) $promo['expires_at']) < $now) {
            throw new InvalidArgumentException("Coupon code '{$code}' has expired.");
        }

        $minimum = CouponRules::minimum($promo);
        if ($minimum !== null && $subtotal + 0.001 < $minimum) {
            throw new InvalidArgumentException(sprintf(
                "Coupon code '%s' works on orders of at least ₦%s (items, before delivery). Add ₦%s more to use it.",
                $code,
                number_format($minimum, 2),
                number_format($minimum - $subtotal, 2)
            ));
        }

        return [
            'coupon' => [
                'id' => (int) $promo['id'],
                'code' => $promo['code'],
                'description' => $promo['description'],
            ] + CouponRules::publicFields($promo),
            'discount_amount' => CouponRules::discountFor($promo, $subtotal),
        ];
    }

    /**
     * Validate the delivery details: the same rules as the checkout form (lib/checkout-validation.ts).
     * The answer's details name each field as "shipping_address.<field>", which the form maps
     * back onto its own fields.
     */
    private function validateShippingAddressFields(array $address): void
    {
        $requiredFields = [
            'full_name' => 'Full recipient name',
            'phone' => 'Contact phone number',
            'address_line1' => 'Street delivery address',
            'city' => 'City or town',
            'state' => 'Delivery state',
        ];

        $missing = [];
        foreach ($requiredFields as $field => $label) {
            // Input::text: a list here must count as missing, not as the word "Array".
            if (Input::text($address[$field] ?? null) === '') {
                $missing[$field] = "{$label} is required.";
            }
        }

        if (!empty($missing)) {
            $firstMsg = reset($missing);
            throw new ValidationFailed("Incomplete shipping address: {$firstMsg}", self::shippingDetails($missing));
        }

        $errors = [];
        $name = Input::text($address['full_name'] ?? null);
        if (preg_match_all('/\p{L}/u', $name) < 2) {
            $errors['full_name'] = 'Enter your full name (at least 2 letters).';
        } elseif (Input::tooLong($name, 255)) {
            $errors['full_name'] = 'Your name can be at most 255 characters.';
        }
        if (!PhoneNumber::isNigerian(Input::text($address['phone'] ?? null))) {
            $errors['phone'] = PhoneNumber::NIGERIAN_RULE;
        }
        $street = Input::text($address['address_line1'] ?? null);
        // At least 3 characters, and not only punctuation ("1 A" is a real address, "..." isn't).
        if (mb_strlen($street) < 3 || preg_match_all('/[\p{L}\p{N}]/u', $street) < 2) {
            $errors['address_line1'] = 'Enter your street address (at least 3 characters).';
        } elseif (Input::tooLong($street, 500)) {
            $errors['address_line1'] = 'Your street address can be at most 500 characters.';
        }
        $city = Input::text($address['city'] ?? null);
        if (mb_strlen($city) < 3 || preg_match_all('/[\p{L}\p{N}]/u', $city) < 2) {
            $errors['city'] = 'Enter your city or town (at least 3 characters).';
        } elseif (Input::tooLong($city, 100)) {
            $errors['city'] = 'Your city or town can be at most 100 characters.';
        }

        if (!empty($errors)) {
            throw new ValidationFailed(reset($errors), self::shippingDetails($errors));
        }
    }

    /**
     * @param array<string, string> $errors field => message
     * @return array<string, string>
     */
    private static function shippingDetails(array $errors): array
    {
        $details = [];
        foreach ($errors as $field => $message) {
            $details["shipping_address.{$field}"] = $message;
        }
        return $details;
    }

    /**
     * Store details from store_settings (set during setup and in admin Settings).
     * Missing values come back empty rather than invented.
     */
    public function getStoreOrigin(): array
    {
        $settings = array_column($this->db->fetchAll(
            "SELECT `key_name`, `value` FROM `store_settings`
             WHERE `key_name` IN ('store_name', 'store_origin_city', 'store_origin_state', 'store_origin_address',
                                  'store_contact_phone', 'store_contact_email', 'store_whatsapp_phone')"
        ), 'value', 'key_name');

        return [
            'store_name' => ($settings['store_name'] ?? '') !== '' ? $settings['store_name'] : Shop::name(),
            'origin_city' => $settings['store_origin_city'] ?? '',
            'origin_state' => $settings['store_origin_state'] ?? '',
            'origin_address' => $settings['store_origin_address'] ?? '',
            'contact_phone' => $settings['store_contact_phone'] ?? '',
            'contact_email' => $settings['store_contact_email'] ?? '',
            'whatsapp_phone' => self::supportWhatsAppPhone($settings['store_whatsapp_phone'] ?? null),
        ];
    }

    /**
     * WhatsApp number for manual payments and support: the admin setting wins, then STORE_WHATSAPP_PHONE.
     */
    public static function supportWhatsAppPhone(?string $fromSettings = null): string
    {
        if ($fromSettings === null) {
            $row = Database::getInstance()->fetchOne("SELECT `value` FROM `store_settings` WHERE `key_name` = 'store_whatsapp_phone'");
            $fromSettings = $row['value'] ?? null;
        }
        $phone = trim((string) $fromSettings);
        return $phone !== '' ? $phone : trim((string) Env::get('STORE_WHATSAPP_PHONE', ''));
    }

    /**
     * Generate an unambiguous, unique public order number (ORD-YYYYMMDD-XXXXXX).
     */
    private function generateUniqueOrderNumber(Database $db): string
    {
        $datePart = date('Ymd');
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $randomHex = strtoupper(bin2hex(random_bytes(3))); // 6 hex characters
            $candidate = "ORD-{$datePart}-{$randomHex}";

            $existing = $db->fetchOne("SELECT `id` FROM `orders` WHERE `order_number` = ?", 's', [$candidate]);
            if ($existing === null) {
                return $candidate;
            }
        }

        // Extreme fallback with microtime entropy
        return "ORD-{$datePart}-" . strtoupper(substr(uniqid(), -6));
    }
}
