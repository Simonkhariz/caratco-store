<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Input;
use App\Database\Database;
use App\Middleware\JsonBodyMiddleware;
use App\Repositories\AddressRepository;
use App\Repositories\UserRepository;
use App\Support\PhoneNumber;
use App\Support\RequestContext;
use App\Support\Response;

/**
 * Controller handling authenticated customer operations:
 * Profile management, address book, persistent cart and wishlist merging.
 */
class CustomerController
{
    private UserRepository $userRepo;
    private AddressRepository $addressRepo;
    private Database $db;

    public function __construct()
    {
        $this->userRepo = new UserRepository();
        $this->addressRepo = new AddressRepository();
        $this->db = Database::getInstance();
    }

    /**
     * Get the authenticated customer's profile.
     */
    public function getProfile(): void
    {
        $user = RequestContext::getUser();
        if ($user === null) {
            Response::error('Unauthenticated.', 401, 'UNAUTHENTICATED');
        }

        Response::success(UserRepository::toSafeUser($user), 'Customer profile retrieved.');
    }

    /**
     * Update customer profile (name, phone).
     */
    public function updateProfile(): void
    {
        $user = RequestContext::getUser();
        if ($user === null) {
            Response::error('Unauthenticated.', 401, 'UNAUTHENTICATED');
        }

        $body = JsonBodyMiddleware::getBody();
        $fullName = isset($body['full_name']) ? Input::text($body['full_name'] ?? null) : null;
        $phone = isset($body['phone']) ? Input::text($body['phone'] ?? null) : null;

        $errors = [];
        if ($fullName !== null && Input::tooLong($fullName, 255)) {
            $errors['full_name'] = 'Full name can be at most 255 characters.';
        }
        // The phone is optional, so an empty one clears it; a list is not an empty phone.
        if (is_array($body['phone'] ?? null) || ($phone !== null && $phone !== '' && !PhoneNumber::isNigerian($phone))) {
            $errors['phone'] = PhoneNumber::NIGERIAN_RULE;
        }
        if (!empty($errors)) {
            Response::error('Profile validation failed.', 422, 'VALIDATION_ERROR', $errors);
        }

        $updates = [];
        if ($fullName !== null && $fullName !== '') {
            $updates['full_name'] = $fullName;
        }
        if ($phone !== null) {
            $updates['phone'] = PhoneNumber::stored($phone);
        }

        if (!empty($updates)) {
            $this->userRepo->update((int) $user['id'], $updates);
        }

        $freshUser = $this->userRepo->findById((int) $user['id']);
        Response::success(UserRepository::toSafeUser($freshUser), 'Profile updated successfully.');
    }

    /**
     * List all delivery addresses belonging to the authenticated customer.
     */
    public function getAddresses(): void
    {
        $user = RequestContext::getUser();
        if ($user === null) {
            Response::error('Unauthenticated.', 401, 'UNAUTHENTICATED');
        }

        $addresses = $this->addressRepo->getByUserId((int) $user['id']);
        Response::success($addresses, 'Customer addresses retrieved.');
    }

    /**
     * Add a new delivery address.
     */
    public function createAddress(): void
    {
        $user = RequestContext::getUser();
        if ($user === null) {
            Response::error('Unauthenticated.', 401, 'UNAUTHENTICATED');
        }

        $body = JsonBodyMiddleware::getBody();

        $errors = self::addressErrors($body, false);
        if (!empty($errors)) {
            Response::error('Address validation failed.', 422, 'VALIDATION_ERROR', $errors);
        }

        $addressId = $this->addressRepo->create((int) $user['id'], $body);
        $created = $this->addressRepo->findByIdAndUser($addressId, (int) $user['id']);

        Response::success($created, 'Address added successfully.', 201);
    }

    /**
     * The address book's rules. A new address must have every required field; an update is
     * checked on the fields it sends. Each limit is its database column's size.
     *
     * @return array<string, string> field => message
     */
    private static function addressErrors(array $body, bool $onlySentFields): array
    {
        // field => [label, largest size, required]
        $rules = [
            'label' => ['Label', 50, false],
            'recipient_name' => ['Recipient name', 255, true],
            'phone' => ['Phone number', 50, true],
            'street_address' => ['Street address', 255, true],
            'city' => ['City', 100, true],
            'state' => ['State', 100, true],
            'country' => ['Country', 100, false],
            'postal_code' => ['Postal code', 20, false],
        ];

        $errors = [];
        foreach ($rules as $field => [$label, $max, $required]) {
            if ($onlySentFields && !array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field] ?? null;
            if (is_array($value)) {
                $errors[$field] = "{$label} must be text.";
            } elseif ($required && empty(Input::text($value))) {
                $errors[$field] = "{$label} is required.";
            } elseif (Input::tooLong($value, $max)) {
                $errors[$field] = "{$label} can be at most {$max} characters.";
            } elseif ($field === 'phone' && !PhoneNumber::isNigerian((string) Input::text($value))) {
                $errors[$field] = PhoneNumber::NIGERIAN_RULE;
            }
        }
        return $errors;
    }

    /**
     * Update an address belonging to the authenticated customer.
     */
    public function updateAddress(array $params): void
    {
        $user = RequestContext::getUser();
        if ($user === null) {
            Response::error('Unauthenticated.', 401, 'UNAUTHENTICATED');
        }

        $addressId = (int) ($params['id'] ?? 0);
        $body = JsonBodyMiddleware::getBody();

        $errors = self::addressErrors($body, true);
        if (!empty($errors)) {
            Response::error('Address validation failed.', 422, 'VALIDATION_ERROR', $errors);
        }

        $updated = $this->addressRepo->update($addressId, (int) $user['id'], $body);
        if (!$updated) {
            Response::error('Address not found or access denied.', 404, 'ADDRESS_NOT_FOUND');
        }

        $address = $this->addressRepo->findByIdAndUser($addressId, (int) $user['id']);
        Response::success($address, 'Address updated successfully.');
    }

    /**
     * Delete an address belonging to the customer.
     */
    public function deleteAddress(array $params): void
    {
        $user = RequestContext::getUser();
        if ($user === null) {
            Response::error('Unauthenticated.', 401, 'UNAUTHENTICATED');
        }

        $addressId = (int) ($params['id'] ?? 0);
        $deleted = $this->addressRepo->delete($addressId, (int) $user['id']);
        if (!$deleted) {
            Response::error('Address not found or access denied.', 404, 'ADDRESS_NOT_FOUND');
        }

        Response::success(null, 'Address removed successfully.');
    }

    /**
     * Set a default delivery address.
     */
    public function setDefaultAddress(array $params): void
    {
        $user = RequestContext::getUser();
        if ($user === null) {
            Response::error('Unauthenticated.', 401, 'UNAUTHENTICATED');
        }

        $addressId = (int) ($params['id'] ?? 0);
        $ok = $this->addressRepo->setDefault($addressId, (int) $user['id']);
        if (!$ok) {
            Response::error('Address not found or access denied.', 404, 'ADDRESS_NOT_FOUND');
        }

        Response::success(null, 'Default address updated.');
    }

    private const MAX_CART_LINES = 30;
    private const MAX_CART_QUANTITY = 20;

    /**
     * GET /api/v1/customer/cart
     * The signed-in customer's saved cart, with current prices and stock.
     */
    public function getCart(): void
    {
        $user = RequestContext::getUser();
        Response::success(['items' => $this->loadCartItems($this->cartIdFor((int) $user['id']))], 'Cart retrieved.');
    }

    /**
     * PUT /api/v1/customer/cart
     * Replace the saved cart with the given lines ({variant_id, quantity}). Unknown, archived or
     * unpublished variants are dropped; quantities are capped. Prices are never taken from the client.
     */
    public function replaceCart(): void
    {
        $user = RequestContext::getUser();
        $body = JsonBodyMiddleware::getBody();
        $lines = $body['items'] ?? null;
        if (!is_array($lines)) {
            Response::error('items must be a list of {variant_id, quantity}.', 422, 'VALIDATION_ERROR');
        }

        $wanted = [];
        foreach (array_slice($lines, 0, self::MAX_CART_LINES) as $line) {
            $variantId = (int) ($line['variant_id'] ?? 0);
            $qty = (int) ($line['quantity'] ?? 0);
            if ($variantId > 0 && $qty > 0) {
                $wanted[$variantId] = min(self::MAX_CART_QUANTITY, ($wanted[$variantId] ?? 0) + $qty);
            }
        }

        $valid = [];
        if (!empty($wanted)) {
            $ids = array_keys($wanted);
            $rows = $this->db->fetchAll(
                "SELECT pv.id FROM `product_variants` pv JOIN `products` p ON p.id = pv.product_id
                 WHERE pv.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                   AND pv.is_active = 1 AND p.status = 'published'",
                str_repeat('i', count($ids)),
                $ids
            );
            foreach ($rows as $row) {
                $valid[(int) $row['id']] = $wanted[(int) $row['id']];
            }
        }

        $cartId = $this->cartIdFor((int) $user['id']);
        $this->db->transaction(function (Database $db) use ($cartId, $valid): void {
            $db->execute("DELETE FROM `cart_items` WHERE `cart_id` = ?", 'i', [$cartId]);
            foreach ($valid as $variantId => $qty) {
                $db->execute(
                    "INSERT INTO `cart_items` (`cart_id`, `variant_id`, `quantity`) VALUES (?, ?, ?)",
                    'iii',
                    [$cartId, $variantId, $qty]
                );
            }
        });

        Response::success(['items' => $this->loadCartItems($cartId)], 'Cart saved.');
    }

    /**
     * GET /api/v1/customer/wishlist
     */
    public function getWishlist(): void
    {
        $user = RequestContext::getUser();
        Response::success($this->wishlistPayload($this->wishlistIdFor((int) $user['id'])), 'Wishlist retrieved.');
    }

    /**
     * PUT /api/v1/customer/wishlist
     * Replace the saved wishlist with the given product IDs (published products only).
     */
    public function replaceWishlist(): void
    {
        $user = RequestContext::getUser();
        $body = JsonBodyMiddleware::getBody();
        $productIds = $body['product_ids'] ?? null;
        if (!is_array($productIds)) {
            Response::error('product_ids must be a list.', 422, 'VALIDATION_ERROR');
        }
        $productIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $v): bool => $v > 0))), 0, 100);

        $wishlistId = $this->wishlistIdFor((int) $user['id']);
        $this->db->transaction(function (Database $db) use ($wishlistId, $productIds): void {
            $db->execute("DELETE FROM `wishlist_items` WHERE `wishlist_id` = ?", 'i', [$wishlistId]);
            foreach ($productIds as $productId) {
                $db->execute(
                    "INSERT IGNORE INTO `wishlist_items` (`wishlist_id`, `product_id`)
                     SELECT ?, `id` FROM `products` WHERE `id` = ? AND `status` = 'published'",
                    'ii',
                    [$wishlistId, $productId]
                );
            }
        });

        Response::success($this->wishlistPayload($wishlistId), 'Wishlist saved.');
    }

    private function cartIdFor(int $userId): int
    {
        $cart = $this->db->fetchOne("SELECT `id` FROM `carts` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1", 'i', [$userId]);
        if ($cart !== null) {
            return (int) $cart['id'];
        }
        return (int) $this->db->insert("INSERT INTO `carts` (`user_id`, `currency`) VALUES (?, 'NGN')", 'i', [$userId]);
    }

    private function wishlistIdFor(int $userId): int
    {
        $wishlist = $this->db->fetchOne("SELECT `id` FROM `wishlists` WHERE `user_id` = ? LIMIT 1", 'i', [$userId]);
        if ($wishlist !== null) {
            return (int) $wishlist['id'];
        }
        return (int) $this->db->insert("INSERT INTO `wishlists` (`user_id`) VALUES (?)", 'i', [$userId]);
    }

    private function loadCartItems(int $cartId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT ci.variant_id, ci.quantity, pv.product_id, pv.size, pv.color_name, pv.color_hex, pv.sku,
                    COALESCE(NULLIF(pv.price_override, 0), p.base_price) AS unit_price, p.original_price,
                    pv.stock_quantity, p.name AS product_name, p.slug AS product_slug,
                    (pv.is_active = 1 AND p.status = 'published') AS available,
                    (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.id
                     ORDER BY pi.is_primary DESC, pi.display_order ASC LIMIT 1) AS image_url
             FROM `cart_items` ci
             JOIN `product_variants` pv ON pv.id = ci.variant_id
             JOIN `products` p ON p.id = pv.product_id
             WHERE ci.cart_id = ?
             ORDER BY ci.id ASC",
            'i',
            [$cartId]
        );

        foreach ($rows as &$row) {
            $row['variant_id'] = (int) $row['variant_id'];
            $row['product_id'] = (int) $row['product_id'];
            $row['quantity'] = (int) $row['quantity'];
            $row['stock_quantity'] = (int) $row['stock_quantity'];
            $row['unit_price'] = (float) $row['unit_price'];
            $row['original_price'] = $row['original_price'] !== null ? (float) $row['original_price'] : null;
            $row['available'] = (bool) $row['available'];
        }
        unset($row);

        return $rows;
    }

    private function wishlistPayload(int $wishlistId): array
    {
        $ids = array_map('intval', array_column(
            $this->db->fetchAll("SELECT `product_id` FROM `wishlist_items` WHERE `wishlist_id` = ? ORDER BY `id` ASC", 'i', [$wishlistId]),
            'product_id'
        ));

        $products = empty($ids)
            ? []
            : (new \App\Repositories\ProductRepository())->listPublished(['ids' => $ids], 1, 100)['items'];

        return ['product_ids' => $ids, 'products' => $products];
    }

    /**
     * List all past orders belonging to the authenticated customer.
     * GET /api/v1/customer/orders
     */
    public function getOrders(): void
    {
        $user = RequestContext::getUser();
        if ($user === null) {
            Response::error('Unauthenticated.', 401, 'UNAUTHENTICATED');
        }

        $userId = (int) $user['id'];

        $orders = $this->db->fetchAll(
            "SELECT `id`, `order_number`, `order_status`, `payment_status`, `currency`,
                    `subtotal`, `discount_amount`, `shipping_fee`, `grand_total`,
                    `shipping_address_snapshot`, `expires_at`, `customer_marked_paid_at`, `created_at`, `updated_at`
             FROM `orders`
             WHERE `user_id` = ?
             ORDER BY `id` DESC",
            'i',
            [$userId]
        );

        foreach ($orders as &$order) {
            $orderId = (int) $order['id'];
            $items = $this->db->fetchAll(
                "SELECT `id`, `product_id`, `variant_id`, `item_name`, `sku`, `unit_price`, `quantity`, `line_total`, `variant_options`
                 FROM `order_items` WHERE `order_id` = ?",
                'i',
                [$orderId]
            );

            foreach ($items as &$item) {
                $item['variant_options'] = is_string($item['variant_options'])
                    ? json_decode($item['variant_options'], true)
                    : $item['variant_options'];
                $item['unit_price'] = (float) $item['unit_price'];
                $item['line_total'] = (float) $item['line_total'];
            }
            unset($item);

            $order['shipping_address_snapshot'] = is_string($order['shipping_address_snapshot'])
                ? json_decode($order['shipping_address_snapshot'], true)
                : $order['shipping_address_snapshot'];

            $order['subtotal'] = (float) $order['subtotal'];
            $order['discount_amount'] = (float) $order['discount_amount'];
            $order['shipping_fee'] = (float) $order['shipping_fee'];
            $order['grand_total'] = (float) $order['grand_total'];
            $order['items'] = $items;
            $order['items_count'] = count($items);

            $shipment = $this->db->fetchOne(
                "SELECT `carrier`, `tracking_number`, `tracking_url`, `status`, `shipped_at`, `delivered_at`
                 FROM `shipments` WHERE `order_id` = ? ORDER BY `id` DESC LIMIT 1",
                'i',
                [$orderId]
            );
            $order['shipment'] = $shipment;
        }
        unset($order);

        Response::success($orders, 'Customer orders retrieved.');
    }
}
