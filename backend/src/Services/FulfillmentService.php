<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Support\Input;
use InvalidArgumentException;

/**
 * Service managing physical order fulfillment: the order status state machine, stock
 * release on cancellation/expiry, carrier tracking, refunds and their audit trail.
 *
 * Payment confirmation (pending_payment -> paid) is NOT done here; it only happens through
 * PaymentFinalizer so amount checks and idempotency always apply.
 */
class FulfillmentService
{
    private Database $db;

    /**
     * Allowed manual status transitions (current status => permissible targets).
     * 'paid' is set by PaymentFinalizer and 'refunded' by the refund flow, never directly.
     */
    private const ALLOWED_TRANSITIONS = [
        'pending_payment' => ['cancelled'],
        'paid'            => ['processing', 'shipped', 'cancelled', 'refund_pending'],
        'processing'      => ['shipped', 'cancelled', 'refund_pending'],
        'shipped'         => ['delivered', 'returned'],
        'delivered'       => ['completed', 'returned', 'refund_pending'],
        'completed'       => ['returned', 'refund_pending'],
        'returned'        => ['refund_pending'],
        // Refund request declined: back to "processing" if nothing was sent yet (so it can still
        // be shipped), otherwise "delivered" or "completed". allowedFor() picks per order.
        'refund_pending'  => ['processing', 'delivered', 'completed'],
        'cancelled'       => [],
        'refunded'        => [],
    ];

    /** Extra time the quick expiry sweep gives an overdue order with an open gateway attempt. */
    public const GATEWAY_GRACE_MINUTES = 30;

    /** Order statuses for which stock is still reserved and must be released on cancellation. */
    private const STOCK_HOLDING_STATUSES = ['pending_payment', 'paid', 'processing'];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Determine if a manual status transition is permitted by the state machine.
     */
    public function canTransition(string $fromStatus, string $toStatus): bool
    {
        $from = strtolower(trim($fromStatus));
        $to = strtolower(trim($toStatus));

        if ($from === $to) {
            return true;
        }

        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }

    /**
     * The next statuses for this particular order. Same as the table, except a declined refund
     * request: an order that was never sent goes back to "processing", a sent one to
     * "delivered" or "completed".
     *
     * @return string[]
     */
    public function allowedFor(array $order): array
    {
        $status = strtolower(trim((string) $order['order_status']));
        $allowed = self::ALLOWED_TRANSITIONS[$status] ?? [];
        if ($status !== 'refund_pending') {
            return $allowed;
        }
        // A label alone (status label_created, no dispatch date) is not "sent".
        $sent = $this->db->fetchOne(
            "SELECT 1 AS x FROM `shipments` WHERE `order_id` = ? AND (`shipped_at` IS NOT NULL OR `status` <> 'label_created')
             UNION SELECT 1 FROM `order_status_history` WHERE `order_id` = ? AND `new_status` IN ('shipped', 'delivered', 'completed', 'returned')
             LIMIT 1",
            'ii',
            [(int) $order['id'], (int) $order['id']]
        ) !== null;
        return array_values(array_filter($allowed, static fn (string $s): bool => $sent ? $s !== 'processing' : $s === 'processing'));
    }

    /**
     * @return string[]
     */
    public function getAllowedTransitions(string $currentStatus): array
    {
        return self::ALLOWED_TRANSITIONS[strtolower(trim($currentStatus))] ?? [];
    }

    /**
     * Admin-driven status change with state machine validation, history and audit trail.
     */
    public function updateOrderStatus(int $orderId, string $newStatus, ?string $comment = null, ?int $adminId = null): array
    {
        $newStatus = strtolower(trim($newStatus));

        if ($newStatus === 'paid') {
            throw new InvalidArgumentException('Payments are confirmed through the gateway or "Confirm payment" (with a transfer reference), not by changing the status.');
        }
        if ($newStatus === 'refunded') {
            throw new InvalidArgumentException('Record a refund to mark an order as refunded.');
        }

        $this->db->transaction(function (Database $db) use ($orderId, $newStatus, $comment, $adminId): void {
            $order = $this->lockOrder($db, $orderId);
            $this->transition($db, $order, $newStatus, $comment, $this->resolveUserId($adminId, $db));
        });

        return $this->loadOrderSnapshot($orderId);
    }

    /**
     * Release an unpaid order whose payment window has passed. Safe to call repeatedly or
     * concurrently: only the call that actually moves pending_payment -> cancelled releases stock.
     *
     * @return bool True if this call expired the order
     */
    public function expireOrder(int $orderId): bool
    {
        return $this->db->transaction(function (Database $db) use ($orderId): bool {
            $order = $db->fetchOne("SELECT * FROM `orders` WHERE `id` = ? FOR UPDATE", 'i', [$orderId]);
            if ($order === null
                || $order['order_status'] !== 'pending_payment'
                || empty($order['expires_at'])
                || strtotime((string) $order['expires_at']) > time()) {
                return false;
            }

            $this->transition($db, $order, 'cancelled', 'Payment window expired. Reserved stock released automatically.', null, false);
            if (!empty($order['customer_marked_paid_at'])) {
                $this->flagAttention($db, $orderId, 'The customer said they sent the transfer, but the payment window ended and the order was cancelled. Check your bank: if the money arrived, confirm the payment (it is kept as a late payment) and refund it.');
            }
            return true;
        });
    }

    /**
     * Release an unpaid order that the customer replaced before starting any payment (they went
     * back to change the delivery details), so its items go back on sale at once instead of at
     * its deadline. No email: the customer is placing the new order. Once any payment attempt
     * exists money may be on its way, so the order is left alone (it keeps its deadline).
     *
     * @return bool True if this call released the order
     */
    public function releaseUnstartedOrder(int $orderId): bool
    {
        return $this->db->transaction(function (Database $db) use ($orderId): bool {
            // The same lock PaymentAttempts::open takes, so an attempt can't start halfway through.
            $order = $db->fetchOne("SELECT * FROM `orders` WHERE `id` = ? FOR UPDATE", 'i', [$orderId]);
            if ($order === null || $order['order_status'] !== 'pending_payment') {
                return false;
            }
            if ($db->fetchOne("SELECT 1 AS x FROM `payments` WHERE `order_id` = ? LIMIT 1", 'i', [$orderId]) !== null) {
                return false;
            }

            $this->transition($db, $order, 'cancelled', 'Customer went back to change the delivery details before paying. Reserved stock released.', null, false, false);
            return true;
        });
    }

    /**
     * Expire up to $limit overdue unpaid orders.
     *
     * @return string[] Order numbers that were expired
     */
    public function expireOverdueOrders(int $limit = 50): array
    {
        // One sweep at a time: when many shoppers check out together they would otherwise all
        // try to expire the same overdue orders and queue behind each other's row locks
        // (measured: 100 buyers at once took 5.6-9.3 s with 1,700 overdue orders, 0.9-1.5 s
        // without). Whoever finds the sweep busy skips it; the next request or the scheduled
        // job (expire_pending_orders.php) carries on.
        // Named locks are server-wide: the database name keeps two shops (or staging beside live)
        // on one MySQL server from sharing it.
        $lock = $this->db->fetchOne("SELECT GET_LOCK(CONCAT(LEFT(DATABASE(), 40), ':quick_expiry_sweep'), 0) AS got");
        if ((int) ($lock['got'] ?? 0) !== 1) {
            return [];
        }
        try {
            return $this->expireOverdueBatch($limit);
        } finally {
            $this->db->fetchOne("SELECT RELEASE_LOCK(CONCAT(LEFT(DATABASE(), 40), ':quick_expiry_sweep')) AS released");
        }
    }

    /** @return string[] */
    private function expireOverdueBatch(int $limit): array
    {
        // This quick sweep (checkout, admin lists) does not ask the gateways. An order with an
        // open Kora/Monnify attempt gets GATEWAY_GRACE_MINUTES past its deadline, so a payment
        // still clearing is confirmed by its webhook or by expire_pending_orders.php (which asks
        // the gateway first) instead of turning into a late payment that needs a refund.
        $candidates = $this->db->fetchAll(
            "SELECT o.`id`, o.`order_number` FROM `orders` o
             WHERE o.`order_status` = 'pending_payment' AND o.`expires_at` IS NOT NULL AND o.`expires_at` <= NOW()
               AND (o.`expires_at` <= NOW() - INTERVAL " . self::GATEWAY_GRACE_MINUTES . " MINUTE
                    OR NOT EXISTS (SELECT 1 FROM `payments` p WHERE p.`order_id` = o.`id` AND p.`status` = 'pending' AND p.`provider` IN ('kora', 'monnify')))
             ORDER BY o.`expires_at` ASC LIMIT ?",
            'i',
            [$limit]
        );

        $expired = [];
        foreach ($candidates as $row) {
            if ($this->expireOrder((int) $row['id'])) {
                $expired[] = $row['order_number'];
            }
        }
        return $expired;
    }

    /**
     * Create or update physical shipment details and carrier tracking.
     * Dispatch moves paid/processing orders to 'shipped'; a delivered shipment moves them to 'delivered'.
     *
     * @param array{carrier: string, tracking_number?: string, tracking_url?: string, status?: string, shipped_at?: string, delivered_at?: string} $data
     */
    public function assignShipment(int $orderId, array $data, ?int $adminId = null): array
    {
        $carrier = Input::text($data['carrier'] ?? '');
        if ($carrier === '') {
            throw new InvalidArgumentException("Carrier name is required (e.g. GIG Logistics, DHL Express, FedEx, Speedaf).");
        }

        $trackingNumber = isset($data['tracking_number']) ? (Input::text($data['tracking_number']) ?: null) : null;
        $trackingUrl = isset($data['tracking_url']) ? (Input::text($data['tracking_url']) ?: null) : null;
        $shipmentStatus = isset($data['status']) ? strtolower(Input::text($data['status'])) : 'label_created';
        if ($shipmentStatus === 'shipped') {
            $shipmentStatus = 'in_transit';
        }
        $shippedAt = !empty($data['shipped_at']) ? $this->normalizeDateTime(Input::text($data['shipped_at']), 'shipped_at') : null;
        $deliveredAt = !empty($data['delivered_at']) ? $this->normalizeDateTime(Input::text($data['delivered_at']), 'delivered_at') : null;

        if (mb_strlen($carrier) > 100) {
            throw new InvalidArgumentException('Carrier name can be at most 100 characters.');
        }
        if ($trackingNumber !== null && mb_strlen($trackingNumber) > 100) {
            throw new InvalidArgumentException('Tracking number can be at most 100 characters.');
        }
        // Customers look the code up in a web address, and most web servers refuse a slash there.
        if ($trackingNumber !== null && strpbrk($trackingNumber, '/\\') !== false) {
            throw new InvalidArgumentException('Tracking number cannot contain a slash: customers could not look it up. Spaces, dots and dashes are fine.');
        }
        if ($trackingUrl !== null && mb_strlen($trackingUrl) > 500) {
            throw new InvalidArgumentException('Tracking URL can be at most 500 characters.');
        }
        if ($trackingUrl !== null && !preg_match('#^https?://#i', $trackingUrl)) {
            throw new InvalidArgumentException('Tracking URL must start with http:// or https://');
        }

        $validStatuses = ['label_created', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'returned'];
        if (!in_array($shipmentStatus, $validStatuses, true)) {
            throw new InvalidArgumentException("Invalid shipment status '{$shipmentStatus}'. Valid: " . implode(', ', $validStatuses));
        }

        $this->db->transaction(function (Database $db) use (
            $orderId, $carrier, $trackingNumber, $trackingUrl, $shipmentStatus, $shippedAt, $deliveredAt, $adminId
        ): void {
            $adminId = $this->resolveUserId($adminId, $db);
            $order = $this->lockOrder($db, $orderId);

            if (!in_array($order['order_status'], ['paid', 'processing', 'shipped', 'delivered', 'returned'], true)) {
                throw new InvalidArgumentException("Shipments can only be recorded for paid orders (this order is '{$order['order_status']}').");
            }

            $existing = $db->fetchOne("SELECT * FROM `shipments` WHERE `order_id` = ? ORDER BY `id` DESC LIMIT 1", 'i', [$orderId]);

            if ($shippedAt === null && in_array($shipmentStatus, ['picked_up', 'in_transit', 'out_for_delivery', 'delivered'], true)) {
                $shippedAt = date('Y-m-d H:i:s');
            }
            if ($deliveredAt === null && $shipmentStatus === 'delivered') {
                $deliveredAt = date('Y-m-d H:i:s');
            }

            if ($existing !== null) {
                $shipmentId = (int) $existing['id'];
                $db->executeUpdate(
                    "UPDATE `shipments` SET `carrier` = ?, `tracking_number` = ?, `tracking_url` = ?, `status` = ?,
                            `shipped_at` = COALESCE(`shipped_at`, ?), `delivered_at` = COALESCE(?, `delivered_at`), `updated_at` = NOW()
                     WHERE `id` = ?",
                    'ssssssi',
                    [$carrier, $trackingNumber, $trackingUrl, $shipmentStatus, $shippedAt, $deliveredAt, $shipmentId]
                );
            } else {
                $shipmentId = (int) $db->insert(
                    "INSERT INTO `shipments` (`order_id`, `carrier`, `tracking_number`, `tracking_url`, `status`, `shipped_at`, `delivered_at`)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    'issssss',
                    [$orderId, $carrier, $trackingNumber, $trackingUrl, $shipmentStatus, $shippedAt, $deliveredAt]
                );
            }

            $trackingNote = $trackingNumber ? " (Tracking: {$trackingNumber})" : '';
            $dispatched = in_array($shipmentStatus, ['picked_up', 'in_transit', 'out_for_delivery', 'delivered'], true);

            if ($dispatched && in_array($order['order_status'], ['paid', 'processing'], true)) {
                $order = $this->transition($db, $order, 'shipped', "Dispatched with {$carrier}{$trackingNote}.", $adminId);
            }
            if ($shipmentStatus === 'delivered' && $order['order_status'] === 'shipped') {
                $order = $this->transition($db, $order, 'delivered', "Delivered by {$carrier}{$trackingNote}.", $adminId);
            }
            if ($shipmentStatus === 'returned' && in_array($order['order_status'], ['shipped', 'delivered'], true)) {
                $order = $this->transition($db, $order, 'returned', "Parcel returned via {$carrier}{$trackingNote}.", $adminId);
            }

            AuditService::record($adminId, 'shipment.assign', 'order', (string) $orderId, $existing ?: null, [
                'shipment_id' => $shipmentId,
                'carrier' => $carrier,
                'tracking_number' => $trackingNumber,
                'tracking_url' => $trackingUrl,
                'status' => $shipmentStatus,
            ]);

            if ($dispatched) {
                // Queued in this transaction (just before it commits), like every email below.
                $mailOrder = $order;
                $db->beforeCommit(static function () use ($mailOrder, $carrier, $trackingNumber, $trackingUrl, $shipmentStatus): void {
                    MailService::getInstance()->sendShipmentTracking($mailOrder, [
                        'carrier' => $carrier,
                        'tracking_number' => $trackingNumber,
                        'tracking_url' => $trackingUrl,
                        'status' => $shipmentStatus,
                    ]);
                });
            }
        });

        return $this->loadOrderSnapshot($orderId);
    }

    /**
     * Mark an order as delivered. Orders that were never marked shipped pass through 'shipped'
     * first (e.g. local hand delivery), so the history stays truthful.
     */
    public function markDelivered(int $orderId, ?string $comment = null, ?int $adminId = null): array
    {
        $this->db->transaction(function (Database $db) use ($orderId, $comment, $adminId): void {
            $adminId = $this->resolveUserId($adminId, $db);
            $order = $this->lockOrder($db, $orderId);

            if (!in_array($order['order_status'], ['paid', 'processing', 'shipped'], true)) {
                throw new InvalidArgumentException("Only paid or shipped orders can be marked delivered (this order is '{$order['order_status']}').");
            }

            if ($order['order_status'] !== 'shipped') {
                $order = $this->transition($db, $order, 'shipped', 'Handed over for delivery.', $adminId);
            }

            $db->executeUpdate(
                "UPDATE `shipments` SET `status` = 'delivered', `delivered_at` = COALESCE(`delivered_at`, NOW()), `updated_at` = NOW()
                 WHERE `order_id` = ?",
                'i',
                [$orderId]
            );

            $this->transition($db, $order, 'delivered', $comment ?: 'Delivery confirmed.', $adminId);
        });

        return $this->loadOrderSnapshot($orderId);
    }

    /**
     * Record a refund against the money on an order, one payment at a time: the stray money first
     * (see moneyOnOrder), capped at what that payment has left.
     * 'completed' means the money has already been returned (e.g. from the gateway dashboard);
     * 'pending' records an approved refund that will be completed later.
     *
     * @return array<string, mixed> The refund row
     */
    public function recordRefund(int $orderId, float $amount, string $reason, string $status, bool $restock, ?int $adminId, ?string $providerRefundReference = null): array
    {
        $providerRefundReference = $providerRefundReference !== null ? (trim($providerRefundReference) ?: null) : null;
        $amount = round($amount, 2);
        $reason = trim($reason);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Refund amount must be greater than zero.');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('A refund reason is required.');
        }
        if (mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('A refund reason can be at most 1,000 characters.');
        }
        if ($providerRefundReference !== null && mb_strlen($providerRefundReference) > 100) {
            throw new InvalidArgumentException('The refund reference can be at most 100 characters.');
        }
        if (!in_array($status, ['pending', 'completed'], true)) {
            throw new InvalidArgumentException("Refund status must be 'pending' or 'completed'.");
        }

        $refundId = $this->db->transaction(function (Database $db) use ($orderId, $amount, $reason, $status, $restock, $adminId, $providerRefundReference): int {
            $adminId = $this->resolveUserId($adminId, $db);
            $order = $this->lockOrder($db, $orderId);

            $open = array_values(array_filter($this->moneyOnOrder($db, $orderId, true), static fn (array $p): bool => !$p['settled']));
            if ($open === []) {
                throw new InvalidArgumentException('This order has no successful payment to refund.');
            }
            $withBalance = array_values(array_filter($open, static fn (array $p): bool => $p['left'] > 0.005));
            $payment = $withBalance[0] ?? $open[0];

            if ($amount > $payment['left'] + 0.001) {
                throw new InvalidArgumentException(sprintf(
                    'Refund exceeds the refundable balance. Paid %s, already refunded or pending %s, maximum now %s.%s',
                    number_format($payment['received'], 2),
                    number_format($payment['refunded'] + $payment['pending_refunds'], 2),
                    number_format(max(0, $payment['left']), 2),
                    count($withBalance) > 1 ? ' This order has more than one payment: refund them one at a time.' : ''
                ));
            }

            $refundId = (int) $db->insert(
                "INSERT INTO `refunds` (`payment_id`, `order_id`, `provider_refund_reference`, `amount`, `currency`, `reason`, `status`, `restocked`, `processed_by_user_id`)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', 0, ?)",
                'iisdssi',
                [(int) $payment['id'], $orderId, $providerRefundReference, $amount, $payment['currency'], $reason, $adminId]
            );

            AuditService::record($adminId, 'refund.create', 'order', (string) $orderId, null, [
                'refund_id' => $refundId,
                'amount' => $amount,
                'status' => $status,
                'reason' => $reason,
                'restock' => $restock,
            ]);

            if ($status === 'completed') {
                $this->completeRefundLocked($db, $refundId, $restock, $adminId);
            } elseif ($restock) {
                $db->executeUpdate("UPDATE `refunds` SET `restocked` = 2 WHERE `id` = ?", 'i', [$refundId]); // 2 = restock on completion
            }

            return $refundId;
        });

        return $this->db->fetchOne("SELECT * FROM `refunds` WHERE `id` = ?", 'i', [$refundId]);
    }

    /**
     * Complete or reject a pending refund.
     */
    public function resolveRefund(int $refundId, string $newStatus, ?int $adminId): array
    {
        if (!in_array($newStatus, ['completed', 'rejected'], true)) {
            throw new InvalidArgumentException("Refund can only be marked 'completed' or 'rejected'.");
        }

        $this->db->transaction(function (Database $db) use ($refundId, $newStatus, $adminId): void {
            $adminId = $this->resolveUserId($adminId, $db);
            $refund = $db->fetchOne("SELECT * FROM `refunds` WHERE `id` = ? FOR UPDATE", 'i', [$refundId]);
            if ($refund === null) {
                throw new InvalidArgumentException('Refund not found.');
            }
            if ($refund['status'] !== 'pending') {
                throw new InvalidArgumentException("This refund is already {$refund['status']}.");
            }
            $this->lockOrder($db, (int) $refund['order_id']);

            if ($newStatus === 'rejected') {
                $db->executeUpdate("UPDATE `refunds` SET `status` = 'rejected', `restocked` = 0, `processed_by_user_id` = ? WHERE `id` = ?", 'ii', [$adminId, $refundId]);
                AuditService::record($adminId, 'refund.reject', 'order', (string) $refund['order_id'], ['refund_id' => $refundId], ['status' => 'rejected']);
                return;
            }

            $this->completeRefundLocked($db, $refundId, (int) $refund['restocked'] === 2, $adminId);
        });

        return $this->db->fetchOne("SELECT * FROM `refunds` WHERE `id` = ?", 'i', [$refundId]);
    }

    /**
     * Clear an order's attention flag once the admin has dealt with it, for the flags no later
     * action clears by itself (an overpayment refunded, a claim that was never paid). The note and
     * the old reason stay in the order's history and the audit log.
     */
    public function resolveAttention(int $orderId, string $note, ?int $adminId): array
    {
        $this->db->transaction(function (Database $db) use ($orderId, $note, $adminId): void {
            $adminId = $this->resolveUserId($adminId, $db);
            $order = $this->lockOrder($db, $orderId);
            if (!(int) $order['requires_attention']) {
                throw new InvalidArgumentException('This order is not flagged for attention.');
            }

            $db->executeUpdate(
                "UPDATE `orders` SET `requires_attention` = 0, `attention_reason` = NULL, `updated_at` = NOW() WHERE `id` = ?",
                'i',
                [$orderId]
            );
            $db->insert(
                "INSERT INTO `order_status_history` (`order_id`, `previous_status`, `new_status`, `comment`, `changed_by_user_id`) VALUES (?, ?, ?, ?, ?)",
                'isssi',
                [$orderId, $order['order_status'], $order['order_status'], "Attention resolved: {$note} (Was flagged: {$order['attention_reason']})", $adminId]
            );
            AuditService::record($adminId, 'order.attention_resolved', 'order', (string) $orderId, [
                'requires_attention' => true,
                'attention_reason' => $order['attention_reason'],
            ], [
                'requires_attention' => false,
                'note' => $note,
            ]);
        });

        return $this->loadOrderSnapshot($orderId);
    }

    /**
     * Every payment on an order that brought money in, with how much of it is still refundable,
     * in the order refunds take it: underpaid money first (it never paid for the order), then the
     * newest confirmed payment, so a duplicate is refunded before the payment that paid the order.
     *
     * @return list<array<string, mixed>> payment rows plus received, refunded, pending_refunds, left, settled (nothing more to refund)
     */
    public function moneyOnOrder(Database $db, int $orderId, bool $lock = false): array
    {
        $payments = $db->fetchAll(
            "SELECT * FROM `payments`
             WHERE `order_id` = ? AND (`status` IN ('successful', 'partially_refunded', 'refunded')
                   OR (`status` IN ('pending', 'failed', 'cancelled') AND `amount_received` > 0))
             ORDER BY (`verified_at` IS NULL) DESC, `verified_at` DESC, `id` DESC" . ($lock ? ' FOR UPDATE' : ''),
            'i',
            [$orderId]
        );
        foreach ($payments as &$payment) {
            $refunds = $db->fetchOne(
                "SELECT COALESCE(SUM(CASE WHEN `status` = 'completed' THEN `amount` END), 0) AS completed,
                        COALESCE(SUM(CASE WHEN `status` = 'pending' THEN `amount` END), 0) AS pending
                 FROM `refunds` WHERE `payment_id` = ?",
                'i',
                [(int) $payment['id']]
            );
            $payment['received'] = self::receivedOn($payment);
            $payment['refunded'] = (float) $refunds['completed'];
            $payment['pending_refunds'] = (float) $refunds['pending'];
            $payment['left'] = round($payment['received'] - $payment['refunded'] - $payment['pending_refunds'], 2);
            $payment['settled'] = $payment['status'] === 'refunded'
                || (!self::isConfirmed($payment) && $payment['received'] - $payment['refunded'] <= 0.005);
        }
        unset($payment);
        return $payments;
    }

    /** Did this payment pay for the order? Underpaid money on an attempt never did. */
    private static function isConfirmed(array $payment): bool
    {
        return $payment['verified_at'] !== null || in_array($payment['status'], ['successful', 'partially_refunded', 'refunded'], true);
    }

    /**
     * What a refund can return on a payment: all the money that came in, more than the order's
     * amount if the customer overpaid, less if they underpaid. Rows from before amounts were
     * recorded count as the amount charged.
     */
    private static function receivedOn(array $payment): float
    {
        $received = $payment['amount_received'] ?? null;
        return $received !== null ? (float) $received : (float) $payment['amount'];
    }

    /**
     * Load complete order snapshot with items, status history, payments, refunds and shipment.
     */
    public function loadOrderSnapshot(int $orderId): array
    {
        $order = $this->db->fetchOne(
            "SELECT o.*, COALESCE(u.`email`, NULLIF(o.`guest_email`, '')) AS `customer_email`
             FROM `orders` o LEFT JOIN `users` u ON u.`id` = o.`user_id` WHERE o.`id` = ?",
            'i',
            [$orderId]
        );
        if ($order === null) {
            throw new InvalidArgumentException("Order ID {$orderId} not found.");
        }

        unset($order['guest_access_token_hash'], $order['idempotency_key']);

        $order['shipping_zone_snapshot'] = is_string($order['shipping_zone_snapshot'])
            ? json_decode($order['shipping_zone_snapshot'], true)
            : $order['shipping_zone_snapshot'];
        $order['shipping_address_snapshot'] = is_string($order['shipping_address_snapshot'])
            ? json_decode($order['shipping_address_snapshot'], true)
            : $order['shipping_address_snapshot'];

        $order['subtotal'] = (float) $order['subtotal'];
        $order['discount_amount'] = (float) $order['discount_amount'];
        $order['shipping_fee'] = (float) $order['shipping_fee'];
        $order['grand_total'] = (float) $order['grand_total'];
        $order['requires_attention'] = (bool) $order['requires_attention'];
        $order['allowed_transitions'] = $this->allowedFor($order);

        $order['shipment'] = $this->db->fetchOne(
            "SELECT `id`, `carrier`, `tracking_number`, `tracking_url`, `status`, `shipped_at`, `delivered_at`, `created_at`, `updated_at`
             FROM `shipments` WHERE `order_id` = ? ORDER BY `id` DESC LIMIT 1",
            'i',
            [$orderId]
        );

        $items = $this->db->fetchAll(
            "SELECT `id`, `product_id`, `variant_id`, `product_type`, `item_name`, `sku`, `variant_options`, `unit_price`, `quantity`, `line_total`
             FROM `order_items` WHERE `order_id` = ?",
            'i',
            [$orderId]
        );
        foreach ($items as &$it) {
            $it['variant_options'] = is_string($it['variant_options']) ? json_decode($it['variant_options'], true) : $it['variant_options'];
            $it['unit_price'] = (float) $it['unit_price'];
            $it['line_total'] = (float) $it['line_total'];
        }
        unset($it);
        $order['items'] = $items;

        $order['history'] = $this->db->fetchAll(
            "SELECT `previous_status`, `new_status`, `comment`, `changed_by_user_id`, `created_at`
             FROM `order_status_history` WHERE `order_id` = ? ORDER BY `id` ASC",
            'i',
            [$orderId]
        );

        $payments = $this->db->fetchAll(
            "SELECT `id`, `provider`, `provider_reference`, `amount`, `amount_received`, `currency`, `payment_method`, `status`, `verified_at`, `created_at`
             FROM `payments` WHERE `order_id` = ? ORDER BY `id` DESC",
            'i',
            [$orderId]
        );
        $order['payments'] = $payments;
        // Primary payment: the successful one if any, else the latest attempt.
        $order['payment'] = null;
        // A refunded duplicate must not hide the payment that really paid for the order.
        foreach (['successful', 'partially_refunded', 'refunded'] as $wanted) {
            foreach ($payments as $p) {
                if ($p['status'] === $wanted) {
                    $order['payment'] = $p;
                    break 2;
                }
            }
        }
        $order['payment'] = $order['payment'] ?? ($payments[0] ?? null);

        $order['refunds'] = $this->db->fetchAll(
            "SELECT `id`, `amount`, `currency`, `reason`, `status`, `restocked`, `created_at`, `updated_at`
             FROM `refunds` WHERE `order_id` = ? ORDER BY `id` DESC",
            'i',
            [$orderId]
        );

        return $order;
    }

    // -------------------------------------------------------------------------
    // Internals (always called inside a transaction with the order row locked)
    // -------------------------------------------------------------------------

    private function lockOrder(Database $db, int $orderId): array
    {
        $order = $db->fetchOne("SELECT * FROM `orders` WHERE `id` = ? FOR UPDATE", 'i', [$orderId]);
        if ($order === null) {
            throw new InvalidArgumentException("Order ID {$orderId} not found.");
        }
        return $order;
    }

    /**
     * Apply one validated status transition plus its side effects. Returns the updated order row.
     */
    private function transition(Database $db, array $order, string $newStatus, ?string $comment, ?int $adminId, bool $audit = true, bool $notify = true): array
    {
        $orderId = (int) $order['id'];
        $currentStatus = $order['order_status'];

        $nextStatuses = $this->allowedFor($order);
        if ($currentStatus !== $newStatus && !in_array(strtolower(trim($newStatus)), $nextStatuses, true)) {
            $allowed = implode(', ', $nextStatuses);
            throw new InvalidArgumentException(
                "Cannot move this order from '{$currentStatus}' to '{$newStatus}'. "
                . ($allowed !== '' ? "Allowed next statuses: {$allowed}." : "'{$currentStatus}' is a final status.")
            );
        }
        if ($currentStatus === $newStatus) {
            return $order;
        }

        $newPaymentStatus = $order['payment_status'];
        $histComment = $comment ?: "Status updated from '{$currentStatus}' to '{$newStatus}'.";

        if ($newStatus === 'cancelled') {
            if (in_array($currentStatus, self::STOCK_HOLDING_STATUSES, true)) {
                $this->releaseReservation($db, $order);
            }

            if ($order['payment_status'] === 'pending' || $order['payment_status'] === 'failed') {
                $newPaymentStatus = 'cancelled';
                $db->executeUpdate(
                    "UPDATE `payments` SET `status` = 'cancelled', `updated_at` = NOW() WHERE `order_id` = ? AND `status` = 'pending'",
                    'i',
                    [$orderId]
                );
            } elseif (in_array($order['payment_status'], ['successful', 'partially_refunded'], true)) {
                $this->flagAttention($db, $orderId, 'Cancelled after payment: record a refund for the customer.');
            }
        }

        $db->executeUpdate(
            "UPDATE `orders` SET `order_status` = ?, `payment_status` = ?,
                    `expires_at` = IF(? = 'pending_payment', `expires_at`, NULL), `updated_at` = NOW()
             WHERE `id` = ?",
            'sssi',
            [$newStatus, $newPaymentStatus, $newStatus, $orderId]
        );
        ProductSales::refreshForOrder($db, $orderId);

        $db->insert(
            "INSERT INTO `order_status_history` (`order_id`, `previous_status`, `new_status`, `comment`, `changed_by_user_id`)
             VALUES (?, ?, ?, ?, ?)",
            'isssi',
            [$orderId, $currentStatus, $newStatus, $histComment, $adminId]
        );

        if ($audit) {
            AuditService::record(
                $adminId,
                'order.status_update',
                'order',
                (string) $orderId,
                ['order_status' => $currentStatus, 'payment_status' => $order['payment_status']],
                ['order_status' => $newStatus, 'payment_status' => $newPaymentStatus, 'comment' => $histComment]
            );
        }

        $order['order_status'] = $newStatus;
        $order['payment_status'] = $newPaymentStatus;

        // Declining a refund request (refund_pending -> processing / delivered / completed) also
        // declines the refunds still pending for it, so none stays open to be completed later
        // (which would mark a shipping order refunded and could release its stock).
        if ($currentStatus === 'refund_pending' && in_array($newStatus, ['processing', 'delivered', 'completed'], true)) {
            $declined = $db->executeUpdate(
                "UPDATE `refunds` SET `status` = 'rejected', `restocked` = 0, `updated_at` = NOW() WHERE `order_id` = ? AND `status` = 'pending'",
                'i',
                [$orderId]
            );
            if ($declined > 0) {
                $db->insert(
                    "INSERT INTO `order_status_history` (`order_id`, `previous_status`, `new_status`, `comment`, `changed_by_user_id`) VALUES (?, ?, ?, ?, ?)",
                    'isssi',
                    [$orderId, $newStatus, $newStatus, "Refund request declined: {$declined} pending refund(s) closed.", $adminId]
                );
                if ($audit) {
                    AuditService::record($adminId, 'refund.decline', 'order', (string) $orderId, ['pending_refunds' => $declined], ['status' => 'rejected']);
                }
            }
        }

        // Progress emails only for real progress: paid -> processing, shipped -> delivered (not a
        // declined refund moving the order back).
        $progress = ($newStatus === 'processing' && $currentStatus === 'paid')
            || ($newStatus === 'delivered' && $currentStatus === 'shipped');
        if ($progress && $notify) {
            $mailOrder = $order;
            $db->beforeCommit(static function () use ($mailOrder, $newStatus): void {
                MailService::getInstance()->sendOrderStatusUpdate($mailOrder, $newStatus);
            });
        }

        if ($newStatus === 'cancelled' && $notify) {
            $mailOrder = $order;
            $db->beforeCommit(static function () use ($mailOrder, $histComment): void {
                MailService::getInstance()->sendCancellationOrRefund($mailOrder, 'cancellation', ['reason' => $histComment]);
            });
        }

        return $order;
    }

    /**
     * Return an order's reserved stock and its promotion use. Called exactly once per order,
     * from the transition that leaves a stock-holding status.
     */
    private function releaseReservation(Database $db, array $order): void
    {
        $items = $db->fetchAll("SELECT `variant_id`, `quantity` FROM `order_items` WHERE `order_id` = ?", 'i', [(int) $order['id']]);
        foreach ($items as $item) {
            if (!empty($item['variant_id'])) {
                $db->executeUpdate(
                    "UPDATE `product_variants` SET `stock_quantity` = `stock_quantity` + ? WHERE `id` = ?",
                    'ii',
                    [(int) $item['quantity'], (int) $item['variant_id']]
                );
            }
        }

        if (!empty($order['applied_promo_code'])) {
            $db->executeUpdate(
                "UPDATE `promotions` SET `used_count` = GREATEST(`used_count` - 1, 0) WHERE `code` = ?",
                's',
                [$order['applied_promo_code']]
            );
        }
    }

    private function completeRefundLocked(Database $db, int $refundId, bool $restock, ?int $adminId): void
    {
        $refund = $db->fetchOne("SELECT * FROM `refunds` WHERE `id` = ?", 'i', [$refundId]);
        $order = $db->fetchOne("SELECT * FROM `orders` WHERE `id` = ?", 'i', [(int) $refund['order_id']]);
        $payment = $db->fetchOne("SELECT * FROM `payments` WHERE `id` = ? FOR UPDATE", 'i', [(int) $refund['payment_id']]);

        $db->executeUpdate(
            "UPDATE `refunds` SET `status` = 'completed', `processed_by_user_id` = ?, `completed_at` = NOW(), `updated_at` = NOW() WHERE `id` = ?",
            'ii',
            [$adminId, $refundId]
        );

        $completed = $db->fetchOne(
            "SELECT COALESCE(SUM(`amount`), 0) AS total FROM `refunds` WHERE `payment_id` = ? AND `status` = 'completed'",
            'i',
            [(int) $payment['id']]
        );
        $fullyRefunded = (float) $completed['total'] >= self::receivedOn($payment) - 0.01;
        if (self::isConfirmed($payment)) {
            $refundStatus = $fullyRefunded ? 'refunded' : 'partially_refunded';
        } else {
            // Underpaid money never paid for the order. "refunded" means paid to everything that
            // reads a payment (the customer's page would celebrate), so the attempt keeps its
            // status; once all of it has gone back, an open attempt is closed as failed.
            $refundStatus = $fullyRefunded && $payment['status'] === 'pending' ? 'failed' : (string) $payment['status'];
        }

        $db->executeUpdate("UPDATE `payments` SET `status` = ?, `updated_at` = NOW() WHERE `id` = ?", 'si', [$refundStatus, (int) $payment['id']]);

        // The order is refunded only when none of its money is left: refunding a duplicate leaves
        // the good payment paying for it. An order that was never paid (an underpayment returned
        // while it waits for payment or after it was cancelled) keeps its status.
        $moneyLeft = array_filter($this->moneyOnOrder($db, (int) $order['id']), static fn (array $p): bool => !$p['settled']) !== [];
        $previousStatus = $order['order_status'];
        $newOrderStatus = (!$moneyLeft && !in_array($previousStatus, ['cancelled', 'pending_payment'], true)) ? 'refunded' : $previousStatus;
        $orderPaymentStatus = $this->orderPaymentStatus($db, $order);

        // Put goods back on sale only for a full refund where the items are not with the customer
        // (unshipped, returned, or a refund request the admin chose to restock). The admin may tick
        // "return to stock" on any of the order's refunds: the wish is kept (restocked = 2) until
        // the refund that makes the order fully refunded completes, then the stock goes back once,
        // whichever refund carried the tick and in whatever order they complete.
        $asked = $restock || $db->fetchOne(
            "SELECT 1 AS x FROM `refunds` WHERE `order_id` = ? AND `id` <> ? AND `restocked` = 2 AND `status` IN ('pending', 'completed') LIMIT 1",
            'ii',
            [(int) $order['id'], $refundId]
        ) !== null;
        $restocked = 0;
        if ($asked && !$moneyLeft && in_array($previousStatus, ['paid', 'processing', 'returned', 'refund_pending'], true)) {
            $this->releaseReservation($db, ['id' => $order['id'], 'applied_promo_code' => null]);
            $restocked = 1;
            $db->executeUpdate("UPDATE `refunds` SET `restocked` = 0 WHERE `order_id` = ? AND `id` <> ? AND `restocked` = 2", 'ii', [(int) $order['id'], $refundId]);
        } elseif ($restock && $moneyLeft) {
            $restocked = 2; // still wanted, once the rest of the money is refunded
        }
        $db->executeUpdate("UPDATE `refunds` SET `restocked` = ? WHERE `id` = ?", 'ii', [$restocked, $refundId]);

        $db->executeUpdate(
            "UPDATE `orders` SET `order_status` = ?, `payment_status` = ?,
                    `requires_attention` = IF(?, 0, `requires_attention`), `updated_at` = NOW()
             WHERE `id` = ?",
            'ssii',
            [$newOrderStatus, $orderPaymentStatus, $moneyLeft ? 0 : 1, (int) $order['id']]
        );
        ProductSales::refreshForOrder($db, (int) $order['id']);

        $db->insert(
            "INSERT INTO `order_status_history` (`order_id`, `previous_status`, `new_status`, `comment`, `changed_by_user_id`)
             VALUES (?, ?, ?, ?, ?)",
            'isssi',
            [
                (int) $order['id'],
                $previousStatus,
                $newOrderStatus,
                sprintf('Refund of %s %s completed%s. Reason: %s', $refund['currency'], number_format((float) $refund['amount'], 2), $restocked === 1 ? ' and items restocked' : '', $refund['reason']),
                $adminId,
            ]
        );

        AuditService::record($adminId, 'refund.complete', 'order', (string) $order['id'], [
            'order_status' => $previousStatus,
            'payment_status' => $order['payment_status'],
        ], [
            'refund_id' => $refundId,
            'order_status' => $newOrderStatus,
            'payment_status' => $orderPaymentStatus,
            'restocked' => $restocked === 1,
        ]);

        $mailOrder = array_merge($order, ['order_status' => $newOrderStatus, 'payment_status' => $orderPaymentStatus]);
        $mailExtra = [
            'reason' => (string) $refund['reason'],
            'amount' => (float) $refund['amount'],
            'refund_id' => $refundId,
            'reference' => $refund['provider_refund_reference'] ?? null,
        ];
        $db->beforeCommit(static function () use ($mailOrder, $mailExtra): void {
            MailService::getInstance()->sendCancellationOrRefund($mailOrder, 'refund', $mailExtra);
        });
    }

    /**
     * The order's payment status after a refund, from its confirmed payments. With none (only
     * underpaid money, which never paid for the order) it stays as it was.
     */
    private function orderPaymentStatus(Database $db, array $order): string
    {
        $statuses = array_column($db->fetchAll(
            "SELECT `status` FROM `payments` WHERE `order_id` = ? AND (`verified_at` IS NOT NULL OR `status` = 'successful')",
            'i',
            [(int) $order['id']]
        ), 'status');
        if ($statuses === []) {
            return (string) $order['payment_status'];
        }
        if (in_array('partially_refunded', $statuses, true)) {
            return 'partially_refunded';
        }
        return in_array('successful', $statuses, true) ? 'successful' : 'refunded';
    }

    /** Append a note to the order's attention flag, like PaymentFinalizer::flagAttention. */
    private function flagAttention(Database $db, int $orderId, string $reason): void
    {
        $db->executeUpdate(
            "UPDATE `orders` SET `requires_attention` = 1,
                    `attention_reason` = LEFT(CONCAT_WS(' | ', NULLIF(`attention_reason`, ''), ?), 500)
             WHERE `id` = ?",
            'si',
            [$reason, $orderId]
        );
    }

    private function normalizeDateTime(string $value, string $field): string
    {
        $ts = strtotime($value);
        if ($ts === false || $ts > time() + 86400) {
            throw new InvalidArgumentException("Invalid {$field}: use a real date that is not in the future.");
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Resolve admin user ID against database to prevent foreign key constraint violations.
     */
    private function resolveUserId(?int $userId, Database $db): ?int
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }
        $exists = $db->fetchOne("SELECT `id` FROM `users` WHERE `id` = ? LIMIT 1", 'i', [$userId]);
        return $exists !== null ? (int) $exists['id'] : null;
    }
}
