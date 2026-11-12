<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Middleware\JsonBodyMiddleware;
use App\Services\AuditService;
use App\Services\FulfillmentService;
use App\Services\PaymentFinalizer;
use App\Support\Input;
use App\Support\RequestContext;
use App\Support\Response;
use InvalidArgumentException;

/**
 * Administrative controller for order management, physical parcel fulfillment,
 * carrier tracking updates, and delivery lifecycle confirmation.
 */
class AdminOrderController
{
    private Database $db;
    private FulfillmentService $fulfillmentService;

    public function __construct(?Database $db = null, ?FulfillmentService $fulfillmentService = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->fulfillmentService = $fulfillmentService ?? new FulfillmentService($this->db);
    }

    /**
     * List all orders for administrative fulfillment queue with filters and pagination.
     * GET /api/v1/admin/orders
     */
    public function index(): void
    {
        $status = isset($_GET['status']) ? trim(strtolower((string) $_GET['status'])) : '';
        $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
        $page = Input::page($_GET['page'] ?? 1);
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];
        $types = '';

        // Release stock from abandoned orders so the list (and stock levels) are current.
        try {
            $this->fulfillmentService->expireOverdueOrders(50);
        } catch (\Throwable $e) {
            error_log('[Order Expiry Sweep] ' . $e->getMessage());
        }

        if ($status === 'attention') {
            $where[] = "o.requires_attention = 1";
        } elseif ($status === 'awaiting_whatsapp') {
            // Customer chose "Pay via WhatsApp" and the admin has not confirmed the transfer yet.
            $where[] = "o.order_status = 'pending_payment' AND EXISTS (
                SELECT 1 FROM `payments` pw WHERE pw.order_id = o.id AND pw.provider = 'whatsapp' AND pw.status = 'pending')";
        } elseif ($status === 'payment_claimed') {
            // The customer tapped "Mark as paid" for a WhatsApp transfer; the admin has not confirmed it yet.
            $where[] = "o.order_status = 'pending_payment' AND o.customer_marked_paid_at IS NOT NULL";
        } elseif ($status === 'to_ship') {
            // The shipping queue: everything paid for and not yet handed to a carrier.
            $where[] = "o.order_status IN ('paid', 'processing')";
        } elseif ($status !== '' && $status !== 'all') {
            $where[] = "o.order_status = ?";
            $params[] = $status;
            $types .= 's';
        }

        if ($search !== '') {
            $where[] = "(o.order_number LIKE ? OR o.guest_email LIKE ? OR cu.email LIKE ? OR o.shipping_address_snapshot LIKE ? OR s.tracking_number LIKE ?)";
            $term = "%{$search}%";
            array_push($params, $term, $term, $term, $term, $term);
            $types .= 'sssss';
            // A phone number is found however it was typed.
            $phone = \App\Support\PhoneNumber::searchDigits($search);
            if ($phone !== null) {
                array_pop($where);
                $where[] = "(o.order_number LIKE ? OR o.guest_email LIKE ? OR cu.email LIKE ? OR o.shipping_address_snapshot LIKE ? OR s.tracking_number LIKE ? OR o.shipping_address_snapshot LIKE ?)";
                $params[] = "%{$phone}%";
                $types .= 's';
            }
        }

        $whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

        // Total count
        $countSql = "SELECT COUNT(DISTINCT o.id) as total
                     FROM `orders` o
                     LEFT JOIN `users` cu ON cu.id = o.user_id
                     LEFT JOIN `shipments` s ON s.order_id = o.id
                     {$whereSql}";
        $totalRow = $this->db->fetchOne($countSql, $types, $params);
        $total = (int) ($totalRow['total'] ?? 0);

        // Fetch orders
        $sql = "SELECT o.id, o.order_number, o.user_id, o.guest_email, o.order_status, o.payment_status,
                       o.currency, o.subtotal, o.shipping_fee, o.discount_amount, o.grand_total,
                       o.shipping_zone_snapshot, o.shipping_address_snapshot, o.created_at, o.updated_at,
                       o.expires_at, o.requires_attention, o.attention_reason, o.customer_marked_paid_at,
                       COALESCE(cu.email, o.guest_email) AS customer_email,
                       p.provider as payment_provider, p.provider_reference as payment_reference, p.status as payment_record_status,
                       s.carrier, s.tracking_number, s.tracking_url, s.status as shipment_status, s.shipped_at, s.delivered_at,
                       (SELECT COUNT(*) FROM `order_items` oi WHERE oi.order_id = o.id) as items_count,
                       (SELECT SUM(quantity) FROM `order_items` oi WHERE oi.order_id = o.id) as total_quantity
                FROM `orders` o
                LEFT JOIN `payments` p ON p.order_id = o.id AND p.id = (SELECT MAX(p2.id) FROM `payments` p2 WHERE p2.order_id = o.id)
                LEFT JOIN `users` cu ON cu.id = o.user_id
                LEFT JOIN `shipments` s ON s.order_id = o.id AND s.id = (SELECT MAX(s2.id) FROM `shipments` s2 WHERE s2.order_id = o.id)
                {$whereSql}
                ORDER BY o.id DESC
                LIMIT {$perPage} OFFSET {$offset}";

        $orders = $this->db->fetchAll($sql, $types, $params);

        foreach ($orders as &$ord) {
            $ord['id'] = (int) $ord['id'];
            $ord['user_id'] = $ord['user_id'] ? (int) $ord['user_id'] : null;
            $ord['subtotal'] = (float) $ord['subtotal'];
            $ord['shipping_fee'] = (float) $ord['shipping_fee'];
            $ord['discount_amount'] = (float) $ord['discount_amount'];
            $ord['grand_total'] = (float) $ord['grand_total'];
            $ord['items_count'] = (int) $ord['items_count'];
            $ord['total_quantity'] = (int) ($ord['total_quantity'] ?? 0);
            $ord['requires_attention'] = (bool) $ord['requires_attention'];

            $ord['shipping_zone_snapshot'] = is_string($ord['shipping_zone_snapshot'])
                ? json_decode($ord['shipping_zone_snapshot'], true)
                : $ord['shipping_zone_snapshot'];

            $ord['shipping_address_snapshot'] = is_string($ord['shipping_address_snapshot'])
                ? json_decode($ord['shipping_address_snapshot'], true)
                : $ord['shipping_address_snapshot'];
        }
        unset($ord);

        Response::paginated($orders, $total, $page, $perPage, 'Fulfillment order list retrieved.');
    }

    /**
     * Get single order detail with item snapshots, shipments, history, and audit log.
     * GET /api/v1/admin/orders/{idOrNumber}
     */
    public function show(array $params): void
    {
        $idOrNumber = trim((string) ($params['idOrNumber'] ?? $params['id'] ?? ''));
        $order = $this->resolveOrder($idOrNumber);

        Response::success($this->withAuditLogs($this->fulfillmentService->loadOrderSnapshot((int) $order['id'])), 'Order fulfillment detail retrieved.');
    }

    /**
     * The order's admin activity trail, added to every order response so the order page keeps
     * showing it (with the action just taken) after a status change, shipment or payment.
     */
    private function withAuditLogs(array $orderSnapshot): array
    {
        $auditLogs = $this->db->fetchAll(
            "SELECT a.id, a.admin_id, a.action, a.before_state, a.after_state, a.ip_address, a.created_at, COALESCE(u.full_name, a.admin_label) as admin_name, u.email as admin_email
             FROM `admin_audit_logs` a
             LEFT JOIN `users` u ON u.id = a.admin_id
             WHERE a.entity_type = 'order' AND a.entity_id = ?
             ORDER BY a.id DESC",
            's',
            [(string) ($orderSnapshot['id'] ?? 0)]
        );

        foreach ($auditLogs as &$log) {
            $log['before_state'] = is_string($log['before_state']) ? json_decode($log['before_state'], true) : $log['before_state'];
            $log['after_state'] = is_string($log['after_state']) ? json_decode($log['after_state'], true) : $log['after_state'];
        }
        unset($log);
        $orderSnapshot['audit_logs'] = $auditLogs;
        return $orderSnapshot;
    }

    /**
     * Transition order status adhering to the allowed state machine.
     * PATCH /api/v1/admin/orders/{idOrNumber}/status
     */
    public function updateStatus(array $params): void
    {
        $idOrNumber = trim((string) ($params['idOrNumber'] ?? $params['id'] ?? ''));
        $order = $this->resolveOrder($idOrNumber);

        $body = JsonBodyMiddleware::getBody();
        $newStatus = Input::text($body['status'] ?? '');
        $comment = isset($body['comment']) ? Input::text($body['comment']) : null;

        if ($newStatus === '') {
            Response::error('New status is required.', 422, 'VALIDATION_ERROR', ['status' => 'Status field cannot be empty.']);
        }

        $adminUser = RequestContext::getUser();
        $adminId = $adminUser ? (int) $adminUser['id'] : null;

        try {
            $updated = $this->fulfillmentService->updateOrderStatus((int) $order['id'], $newStatus, $comment, $adminId);
            Response::success($this->withAuditLogs($updated), "Order status successfully transitioned to '{$newStatus}'.");
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'INVALID_TRANSITION');
        } catch (\Throwable $e) {
            error_log("[Status Transition Failure] " . $e->getMessage());
            Response::error('Failed to update the order status. Please try again.', 500, 'INTERNAL_SERVER_ERROR');
        }
    }

    /**
     * Settle and confirm manual / WhatsApp payment for an order.
     * POST /api/v1/admin/orders/{idOrNumber}/confirm-payment
     */
    public function confirmPayment(array $params): void
    {
        $idOrNumber = trim((string) ($params['idOrNumber'] ?? $params['id'] ?? ''));
        $order = $this->resolveOrder($idOrNumber);

        $body = JsonBodyMiddleware::getBody();
        $reference = Input::text($body['reference'] ?? '');
        $note = Input::text($body['note'] ?? $body['comment'] ?? '');

        $adminUser = RequestContext::getUser();
        $adminId = $adminUser ? (int) $adminUser['id'] : null;

        try {
            $result = (new PaymentFinalizer($this->db))->recordManualPayment((int) $order['id'], $reference, $adminId, $note);
            AuditService::record($adminId, 'payment.manual_confirm', 'order', (string) $order['id'], null, [
                'reference' => $reference,
                'note' => $note,
                'outcome' => $result['outcome'],
            ]);
            Response::success(
                $this->withAuditLogs($this->fulfillmentService->loadOrderSnapshot((int) $order['id'])),
                $result['outcome'] === PaymentFinalizer::OUTCOME_LATE_PAYMENT
                    ? 'Payment recorded as a late payment. The order stays cancelled and is flagged so you can refund the customer.'
                    : 'Payment recorded and order marked as paid.'
            );
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'INVALID_PAYMENT_CONFIRMATION');
        } catch (\Throwable $e) {
            error_log("[Payment Confirmation Failure] " . $e->getMessage());
            Response::error('Failed to confirm the payment. Please try again.', 500, 'INTERNAL_SERVER_ERROR');
        }
    }

    /**
     * Clear an order's attention flag once the admin has dealt with it, with a note saying how.
     * POST /api/v1/admin/orders/{idOrNumber}/resolve-attention  {note}
     */
    public function resolveAttention(array $params): void
    {
        $idOrNumber = trim((string) ($params['idOrNumber'] ?? $params['id'] ?? ''));
        $order = $this->resolveOrder($idOrNumber);

        $body = JsonBodyMiddleware::getBody();
        $note = is_string($body['note'] ?? null) ? trim($body['note']) : '';
        if (mb_strlen($note) < 3 || mb_strlen($note) > 500) {
            $message = 'Add a note of 3 to 500 characters saying how this was resolved.';
            Response::error($message, 422, 'VALIDATION_ERROR', ['note' => $message]);
        }

        $adminUser = RequestContext::getUser();
        $adminId = $adminUser ? (int) $adminUser['id'] : null;

        try {
            $updated = $this->fulfillmentService->resolveAttention((int) $order['id'], $note, $adminId);
            Response::success($this->withAuditLogs($updated), 'Attention flag cleared.');
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'NOT_FLAGGED');
        } catch (\Throwable $e) {
            error_log("[Resolve Attention Failure] " . $e->getMessage());
            Response::error('Failed to clear the flag. Please try again.', 500, 'INTERNAL_SERVER_ERROR');
        }
    }

    /**
     * Assign or update physical shipment details (carrier, tracking number, tracking URL, shipped status).
     * POST /api/v1/admin/orders/{idOrNumber}/shipment
     */
    public function assignShipment(array $params): void
    {
        $idOrNumber = trim((string) ($params['idOrNumber'] ?? $params['id'] ?? ''));
        $order = $this->resolveOrder($idOrNumber);

        $body = JsonBodyMiddleware::getBody();
        $carrier = Input::text($body['carrier'] ?? '');

        if ($carrier === '') {
            Response::error('Carrier name is required.', 422, 'VALIDATION_ERROR', ['carrier' => 'Please provide a valid carrier (e.g. GIG Logistics, DHL, FedEx).']);
        }

        $adminUser = RequestContext::getUser();
        $adminId = $adminUser ? (int) $adminUser['id'] : null;

        try {
            $updated = $this->fulfillmentService->assignShipment((int) $order['id'], $body, $adminId);
            Response::success($this->withAuditLogs($updated), 'Shipment details and carrier tracking updated successfully.');
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\Throwable $e) {
            error_log("[Shipment Assignment Failure] " . $e->getMessage());
            Response::error('Failed to save the shipment. Please try again.', 500, 'INTERNAL_SERVER_ERROR');
        }
    }

    /**
     * Confirm delivery of physical parcel.
     * POST /api/v1/admin/orders/{idOrNumber}/delivered
     */
    public function confirmDelivery(array $params): void
    {
        $idOrNumber = trim((string) ($params['idOrNumber'] ?? $params['id'] ?? ''));
        $order = $this->resolveOrder($idOrNumber);

        $body = JsonBodyMiddleware::getBody();
        $comment = isset($body['comment']) ? Input::text($body['comment']) : null;

        $adminUser = RequestContext::getUser();
        $adminId = $adminUser ? (int) $adminUser['id'] : null;

        try {
            $updated = $this->fulfillmentService->markDelivered((int) $order['id'], $comment, $adminId);
            Response::success($this->withAuditLogs($updated), 'Order delivery confirmed successfully.');
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'INVALID_TRANSITION');
        } catch (\Throwable $e) {
            error_log("[Delivery Confirmation Failure] " . $e->getMessage());
            Response::error('Failed to confirm delivery. Please try again.', 500, 'INTERNAL_SERVER_ERROR');
        }
    }

    /**
     * Resolve order from sequential ID or public order number.
     */
    private function resolveOrder(string $idOrNumber): array
    {
        if ($idOrNumber === '') {
            Response::error('Order identifier is required.', 422, 'VALIDATION_ERROR');
        }

        $order = null;
        if (is_numeric($idOrNumber)) {
            $order = $this->db->fetchOne("SELECT * FROM `orders` WHERE `id` = ?", 'i', [(int) $idOrNumber]);
        } else {
            $order = $this->db->fetchOne("SELECT * FROM `orders` WHERE `order_number` = ?", 's', [$idOrNumber]);
        }

        if ($order === null) {
            Response::error('Order not found.', 404, 'ORDER_NOT_FOUND');
        }

        return $order;
    }
}
