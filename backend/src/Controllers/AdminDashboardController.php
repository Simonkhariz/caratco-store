<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Shop;
use App\Database\Database;
use App\Services\FulfillmentService;
use App\Support\Response;

/**
 * Controller providing real, database-derived admin KPIs, trends, analytical reports,
 * and authenticated CSV exports.
 * Strict rule: Never count pending or failed payments as completed sales.
 */
class AdminDashboardController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** Payment rows that represent money actually received (later refunds are subtracted separately). */
    private const RECEIVED_PAYMENT_STATUSES = "'successful', 'partially_refunded', 'refunded'";

    /**
     * Joined to `refunds` r: only refunds of money counted as a sale. Underpaid money sent back was
     * never verified, so it is in no sales figure and must not come off net sales either.
     */
    private const REFUND_OF_A_SALE = " JOIN `payments` rp ON rp.id = r.payment_id AND rp.verified_at IS NOT NULL";

    /**
     * GET /api/v1/admin/dashboard/overview?period=7|30|90
     *
     * Sales are verified payments (by verification time) in the period; net sales subtract refunds
     * completed in the period. Each KPI is compared with the period of the same length immediately before.
     * Operational counts (fulfilment queue, attention, stock) are current snapshots.
     */
    public function overview(): void
    {
        // Release stock from abandoned orders first so the stock and queue numbers are current.
        try {
            (new FulfillmentService($this->db))->expireOverdueOrders(50);
        } catch (\Throwable $e) {
            error_log('[Order Expiry Sweep] ' . $e->getMessage());
        }

        $period = (int) ($_GET['period'] ?? 30);
        if (!in_array($period, [7, 30, 90], true)) {
            $period = 30;
        }
        $currentStart = date('Y-m-d 00:00:00', strtotime('-' . ($period - 1) . ' days'));
        // Exclusive end at the start of tomorrow (same window as the analytics report), so money
        // verified in the current second is counted.
        $currentEnd = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $previousStart = date('Y-m-d 00:00:00', strtotime('-' . (2 * $period - 1) . ' days'));

        $current = $this->periodTotals($currentStart, $currentEnd);
        $previous = $this->periodTotals($previousStart, $currentStart);

        $delta = static function (float $now, float $before): ?float {
            if ($before <= 0) {
                return null; // No baseline: the UI shows no comparison instead of a made-up percentage.
            }
            return round((($now - $before) / $before) * 100, 1);
        };

        $count = fn (string $sql): int => (int) ($this->db->fetchOne($sql)['c'] ?? 0);
        $stock = $this->db->fetchOne(
            "SELECT COALESCE(SUM(v.stock_quantity > 0 AND v.stock_quantity <= v.low_stock_threshold), 0) AS low_stock,
                    COALESCE(SUM(v.stock_quantity = 0), 0) AS out_of_stock
             FROM `product_variants` v JOIN `products` p ON p.id = v.product_id
             WHERE v.is_active = 1 AND p.status <> 'archived'"
        );

        // Daily trend across the selected period.
        $salesByDay = array_column($this->db->fetchAll(
            "SELECT DATE(verified_at) AS d, SUM(COALESCE(amount_received, amount)) AS sales, COUNT(DISTINCT order_id) AS paid_orders
             FROM `payments`
             WHERE status IN (" . self::RECEIVED_PAYMENT_STATUSES . ") AND verified_at >= ?
             GROUP BY DATE(verified_at)",
            's',
            [$currentStart]
        ), null, 'd');
        $ordersByDay = array_column($this->db->fetchAll(
            "SELECT DATE(created_at) AS d, COUNT(*) AS orders FROM `orders` WHERE created_at >= ? GROUP BY DATE(created_at)",
            's',
            [$currentStart]
        ), 'orders', 'd');

        // Refunds are shown on the day the money went back, like the report's daily breakdown.
        $refundsByDay = array_column($this->db->fetchAll(
            "SELECT DATE(COALESCE(r.completed_at, r.updated_at)) AS d, SUM(r.amount) AS refunded FROM `refunds` r" . self::REFUND_OF_A_SALE . "
             WHERE r.status = 'completed' AND COALESCE(r.completed_at, r.updated_at) >= ?
             GROUP BY DATE(COALESCE(r.completed_at, r.updated_at))",
            's',
            [$currentStart]
        ), 'refunded', 'd');

        $trend = [];
        for ($i = $period - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $trend[] = [
                'date' => $d,
                'sales' => (float) ($salesByDay[$d]['sales'] ?? 0),
                'refunds' => round((float) ($refundsByDay[$d] ?? 0), 2),
                'paid_orders' => (int) ($salesByDay[$d]['paid_orders'] ?? 0),
                'total_orders' => (int) ($ordersByDay[$d] ?? 0),
            ];
        }
        $pendingRefunds = $this->pendingRefunds();

        $recentOrders = $this->db->fetchAll("
            SELECT o.id, o.order_number,
                   COALESCE(u.full_name, JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address_snapshot, '$.full_name'))) AS customer_name,
                   COALESCE(u.email, o.guest_email) AS customer_email,
                   o.grand_total AS total_amount, o.currency, o.order_status, o.payment_status, o.requires_attention,
                   p.provider AS payment_method, o.created_at,
                   (SELECT COALESCE(SUM(oi.quantity), 0) FROM `order_items` oi WHERE oi.order_id = o.id) AS items_count
            FROM `orders` o
            LEFT JOIN `users` u ON u.id = o.user_id
            LEFT JOIN `payments` p ON p.id = (SELECT MAX(p2.id) FROM `payments` p2 WHERE p2.order_id = o.id)
            ORDER BY o.created_at DESC
            LIMIT 8
        ");

        $attentionOrders = $this->db->fetchAll("
            SELECT o.order_number, o.order_status, o.payment_status, o.attention_reason, o.grand_total, o.updated_at
            FROM `orders` o
            WHERE o.requires_attention = 1
            ORDER BY o.updated_at DESC
            LIMIT 5
        ");

        $criticalStock = $this->db->fetchAll("
            SELECT v.id, v.sku, v.size, v.color_name, v.stock_quantity, v.low_stock_threshold,
                   p.id AS product_id, p.name AS product_name
            FROM `product_variants` v
            JOIN `products` p ON p.id = v.product_id
            WHERE v.is_active = 1 AND p.status <> 'archived' AND v.stock_quantity <= v.low_stock_threshold
            ORDER BY v.stock_quantity ASC, p.name ASC
            LIMIT 6
        ");

        $topProducts = $this->db->fetchAll(
            "SELECT p.id, p.name, p.slug,
                    SUM(oi.quantity) AS total_units_sold,
                    SUM(oi.line_total) AS total_revenue
             FROM `order_items` oi
             JOIN `orders` o ON o.id = oi.order_id AND o.order_status NOT IN ('cancelled', 'refunded')
             JOIN `products` p ON p.id = oi.product_id
             WHERE oi.order_id IN (
                 SELECT DISTINCT order_id FROM `payments`
                 WHERE status IN (" . self::RECEIVED_PAYMENT_STATUSES . ") AND verified_at >= ?
             )
             GROUP BY p.id, p.name, p.slug
             ORDER BY total_units_sold DESC, total_revenue DESC
             LIMIT 5",
            's',
            [$currentStart]
        );

        // Failed attempts in the last day, and gateway attempts still unconfirmed after 30 minutes
        // on orders that are still open.
        $paymentAlerts = $this->db->fetchAll("
            SELECT p.id, p.provider_reference AS payment_reference, o.order_number, p.provider AS gateway,
                   p.amount, p.status, p.created_at
            FROM `payments` p
            JOIN `orders` o ON o.id = p.order_id
            WHERE (p.status = 'failed' AND p.updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY))
               OR (p.status = 'pending' AND p.provider IN ('kora', 'monnify')
                   AND p.created_at <= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                   AND o.order_status = 'pending_payment')
            ORDER BY p.created_at DESC
            LIMIT 5
        ");

        Response::success([
            'period' => [
                'days' => $period,
                'start' => $currentStart,
                'end' => date('Y-m-d H:i:s'),
                'previous_start' => $previousStart,
            ],
            'kpis' => [
                'currency' => 'NGN',
                'gross_sales' => $current['gross'],
                'net_sales' => $current['net'],
                'total_refunded' => $current['refunded'],
                'owed_refunds' => $current['owed'],
                'pending_refunds' => $pendingRefunds['total'],
                'pending_refund_count' => $pendingRefunds['count'],
                'paid_orders' => $current['paid_orders'],
                'aov' => $current['aov'],
                'total_orders' => $current['orders_placed'],
                'new_customers' => $current['new_customers'],
                'pending_fulfillment' => $count("SELECT COUNT(*) AS c FROM `orders` WHERE `order_status` IN ('paid', 'processing')"),
                'awaiting_payment' => $count("SELECT COUNT(*) AS c FROM `orders` WHERE `order_status` = 'pending_payment' AND (`expires_at` IS NULL OR `expires_at` > NOW())"),
                'needs_attention' => $count("SELECT COUNT(*) AS c FROM `orders` WHERE `requires_attention` = 1"),
                'low_stock_count' => (int) $stock['low_stock'],
                'out_of_stock_count' => (int) $stock['out_of_stock'],
                'total_customers' => $count("SELECT COUNT(*) AS c FROM `users` WHERE `role` = 'customer'"),
            ],
            'deltas' => [
                'gross_sales' => $delta($current['gross'], $previous['gross']),
                'net_sales' => $delta($current['net'], $previous['net']),
                'paid_orders' => $delta($current['paid_orders'], $previous['paid_orders']),
                'aov' => $delta($current['aov'], $previous['aov']),
                'total_orders' => $delta($current['orders_placed'], $previous['orders_placed']),
                'new_customers' => $delta($current['new_customers'], $previous['new_customers']),
            ],
            'trend' => $trend,
            'recent_orders' => $recentOrders,
            'attention_orders' => $attentionOrders,
            'critical_stock' => $criticalStock,
            'top_products' => $topProducts,
            'payment_alerts' => $paymentAlerts,
        ], 'Admin overview retrieved.');
    }

    /**
     * Refunds recorded but not yet marked completed (the money has not gone back yet). They are in
     * no sales figure until completed, so the screens show them as their own line; whatever the
     * period, because a waiting refund is a task for today.
     *
     * @return array{total: float, count: int}
     */
    private function pendingRefunds(): array
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(r.amount), 0) AS total, COUNT(*) AS cnt FROM `refunds` r" . self::REFUND_OF_A_SALE . " WHERE r.status = 'pending'"
        );
        return ['total' => round((float) ($row['total'] ?? 0), 2), 'count' => (int) ($row['cnt'] ?? 0)];
    }

    /**
     * Money and order totals for [start, end).
     *
     * @return array{gross: float, refunded: float, owed: float, net: float, paid_orders: int, aov: float, orders_placed: int, new_customers: int}
     */
    private function periodTotals(string $start, string $end): array
    {
        $sales = $this->db->fetchOne(
            "SELECT COALESCE(SUM(COALESCE(amount_received, amount)), 0) AS gross, COUNT(DISTINCT order_id) AS paid_orders
             FROM `payments`
             WHERE status IN (" . self::RECEIVED_PAYMENT_STATUSES . ") AND verified_at >= ? AND verified_at < ?",
            'ss',
            [$start, $end]
        );
        $refunded = (float) ($this->db->fetchOne(
            "SELECT COALESCE(SUM(r.amount), 0) AS total FROM `refunds` r" . self::REFUND_OF_A_SALE . "
             WHERE r.status = 'completed' AND COALESCE(r.completed_at, r.updated_at) >= ? AND COALESCE(r.completed_at, r.updated_at) < ?",
            'ss',
            [$start, $end]
        )['total'] ?? 0);
        $owed = array_sum(array_map(static fn (array $r): float => (float) $r['owed'], $this->owedRefunds($start, $end)));
        $ordersPlaced = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM `orders` WHERE created_at >= ? AND created_at < ?",
            'ss',
            [$start, $end]
        )['c'] ?? 0);
        $newCustomers = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM `users` WHERE role = 'customer' AND created_at >= ? AND created_at < ?",
            'ss',
            [$start, $end]
        )['c'] ?? 0);

        $gross = round((float) $sales['gross'], 2);
        $paidOrders = (int) $sales['paid_orders'];

        return [
            'gross' => $gross,
            'refunded' => round($refunded, 2),
            'owed' => round($owed, 2),
            'net' => round(max(0, $gross - $refunded - $owed), 2),
            'paid_orders' => $paidOrders,
            'aov' => $paidOrders > 0 ? round($gross / $paidOrders, 2) : 0.0,
            'orders_placed' => $ordersPlaced,
            'new_customers' => $newCustomers,
        ];
    }

    /**
     * Orders paid in [start, end) and then cancelled, with money still not returned (paid minus
     * completed refunds). Net sales subtract these on the overview and in the report alike.
     *
     * @return list<array<string, mixed>>
     */
    private function owedRefunds(string $start, string $end): array
    {
        $received = self::RECEIVED_PAYMENT_STATUSES;
        return $this->db->fetchAll(
            "SELECT o.order_number, o.updated_at AS cancelled_at, o.payment_status,
                    p.paid, COALESCE(r.refunded, 0) AS refunded, p.paid - COALESCE(r.refunded, 0) AS owed
             FROM `orders` o
             JOIN (SELECT order_id, SUM(COALESCE(amount_received, amount)) AS paid FROM `payments`
                   WHERE status IN ({$received}) AND verified_at >= ? AND verified_at < ? GROUP BY order_id) p ON p.order_id = o.id
             LEFT JOIN (SELECT r.order_id, SUM(r.amount) AS refunded FROM `refunds` r" . self::REFUND_OF_A_SALE . " WHERE r.status = 'completed' GROUP BY r.order_id) r ON r.order_id = o.id
             WHERE o.order_status = 'cancelled' AND p.paid - COALESCE(r.refunded, 0) > 0.009
             ORDER BY o.updated_at DESC",
            'ss',
            [$start, $end]
        );
    }

    /**
     * Resolve the report window from ?range= (today, yesterday, 7days, 30days, 90days, year, all)
     * or ?start_date=&end_date= (YYYY-MM-DD, inclusive). Returns [label, start, end) bounds.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveDateRange(): array
    {
        $range = (string) ($_GET['range'] ?? '30days');
        $startDate = trim((string) ($_GET['start_date'] ?? ''));
        $endDate = trim((string) ($_GET['end_date'] ?? ''));
        $today = date('Y-m-d');

        if ($startDate !== '' || $endDate !== '') {
            $valid = static fn (string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
            if (!$valid($startDate) || !$valid($endDate)) {
                Response::error('Use dates in the form YYYY-MM-DD.', 422, 'VALIDATION_ERROR');
            }
            if ($startDate > $endDate) {
                Response::error('The start date must be on or before the end date.', 422, 'VALIDATION_ERROR');
            }
            return ['custom', "{$startDate} 00:00:00", date('Y-m-d 00:00:00', strtotime("{$endDate} +1 day"))];
        }

        $tomorrow = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $daysBack = static fn (int $days): string => date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

        return match ($range) {
            'today' => ['today', "{$today} 00:00:00", $tomorrow],
            'yesterday' => ['yesterday', date('Y-m-d 00:00:00', strtotime('-1 day')), "{$today} 00:00:00"],
            '7days' => ['7days', $daysBack(7), $tomorrow],
            '90days' => ['90days', $daysBack(90), $tomorrow],
            'year' => ['year', date('Y-01-01 00:00:00'), $tomorrow],
            'all' => ['all', $this->firstActivityDate(), $tomorrow],
            default => ['30days', $daysBack(30), $tomorrow],
        };
    }

    private function firstActivityDate(): string
    {
        // Earliest of any order, verified payment or completed refund, so 'all' really is everything.
        $row = $this->db->fetchOne(
            "SELECT LEAST(
                 COALESCE((SELECT MIN(created_at) FROM `orders`), NOW()),
                 COALESCE((SELECT MIN(verified_at) FROM `payments`), NOW()),
                 COALESCE((SELECT MIN(COALESCE(completed_at, updated_at)) FROM `refunds` WHERE status = 'completed'), NOW())
             ) AS first"
        );
        return $row && $row['first'] ? date('Y-m-d 00:00:00', strtotime((string) $row['first'])) : date('Y-m-d 00:00:00');
    }

    /**
     * Every figure in the report and the CSV, for the window [start, end).
     *
     * Money is counted when it moves: sales on the payment's verification time, refunds on their
     * completion time. Orders paid and later cancelled without a refund are money the store owes
     * back; they are listed and subtracted from net sales.
     *
     * @return array<string, mixed>
     */
    private function buildReport(string $start, string $end): array
    {
        $received = self::RECEIVED_PAYMENT_STATUSES;
        $paidInWindow = "SELECT DISTINCT order_id FROM `payments` WHERE status IN ({$received}) AND verified_at >= ? AND verified_at < ?";
        $w = [$start, $end];

        $sales = $this->db->fetchOne(
            "SELECT COALESCE(SUM(COALESCE(amount_received, amount)), 0) AS gross, COUNT(DISTINCT order_id) AS paid_orders
             FROM `payments` WHERE status IN ({$received}) AND verified_at >= ? AND verified_at < ?",
            'ss',
            $w
        );
        $refunds = $this->db->fetchOne(
            "SELECT COALESCE(SUM(r.amount), 0) AS total, COUNT(*) AS cnt FROM `refunds` r" . self::REFUND_OF_A_SALE . "
             WHERE r.status = 'completed' AND COALESCE(r.completed_at, r.updated_at) >= ? AND COALESCE(r.completed_at, r.updated_at) < ?",
            'ss',
            $w
        );

        $owed = $this->owedRefunds($start, $end);
        $owedTotal = array_sum(array_map(static fn ($r) => (float) $r['owed'], $owed));

        $orders = $this->db->fetchOne(
            "SELECT COUNT(*) AS placed,
                    COALESCE(SUM(payment_status IN ({$received})), 0) AS placed_and_paid
             FROM `orders` WHERE created_at >= ? AND created_at < ?",
            'ss',
            $w
        );

        $units = (int) ($this->db->fetchOne(
            "SELECT COALESCE(SUM(oi.quantity), 0) AS units FROM `order_items` oi
             JOIN `orders` o ON o.id = oi.order_id AND o.order_status NOT IN ('cancelled', 'refunded')
             WHERE oi.order_id IN ({$paidInWindow})",
            'ss',
            $w
        )['units'] ?? 0);

        $customers = $this->db->fetchOne(
            "SELECT COUNT(*) AS paying, COALESCE(SUM(cnt >= 2), 0) AS repeat_customers FROM (
                 SELECT COALESCE(CAST(o.user_id AS CHAR), NULLIF(LOWER(o.guest_email), ''), CONCAT('order:', o.id)) AS customer_key, COUNT(*) AS cnt
                 FROM `orders` o WHERE o.id IN ({$paidInWindow})
                 GROUP BY customer_key
             ) t",
            'ss',
            $w
        );

        $gross = round((float) $sales['gross'], 2);
        $refunded = round((float) $refunds['total'], 2);
        $paidOrders = (int) $sales['paid_orders'];
        $placed = (int) $orders['placed'];
        $paying = (int) $customers['paying'];

        // Daily series (every day in the window, so charts have no gaps).
        $salesByDay = array_column($this->db->fetchAll(
            "SELECT DATE(verified_at) AS d, SUM(COALESCE(amount_received, amount)) AS sales, COUNT(DISTINCT order_id) AS paid
             FROM `payments` WHERE status IN ({$received}) AND verified_at >= ? AND verified_at < ? GROUP BY DATE(verified_at)",
            'ss',
            $w
        ), null, 'd');
        $ordersByDay = array_column($this->db->fetchAll(
            "SELECT DATE(created_at) AS d, COUNT(*) AS placed FROM `orders` WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at)",
            'ss',
            $w
        ), 'placed', 'd');
        $refundsByDay = array_column($this->db->fetchAll(
            "SELECT DATE(COALESCE(r.completed_at, r.updated_at)) AS d, SUM(r.amount) AS refunded FROM `refunds` r" . self::REFUND_OF_A_SALE . "
             WHERE r.status = 'completed' AND COALESCE(r.completed_at, r.updated_at) >= ? AND COALESCE(r.completed_at, r.updated_at) < ?
             GROUP BY DATE(COALESCE(r.completed_at, r.updated_at))",
            'ss',
            $w
        ), 'refunded', 'd');

        $daily = [];
        for ($day = strtotime($start), $stop = strtotime($end); $day < $stop && count($daily) < 1000; $day = strtotime('+1 day', $day)) {
            $d = date('Y-m-d', $day);
            $daily[] = [
                'date' => $d,
                'orders_count' => (int) ($ordersByDay[$d] ?? 0),
                'paid_count' => (int) ($salesByDay[$d]['paid'] ?? 0),
                'sales' => round((float) ($salesByDay[$d]['sales'] ?? 0), 2),
                'refunds' => round((float) ($refundsByDay[$d] ?? 0), 2),
            ];
        }

        $categories = $this->db->fetchAll(
            "SELECT COALESCE(c.name, 'Uncategorised') AS category_name,
                    COUNT(DISTINCT oi.order_id) AS order_count,
                    SUM(oi.quantity) AS units_sold,
                    SUM(oi.line_total) AS revenue
             FROM `order_items` oi
             JOIN `orders` o ON o.id = oi.order_id AND o.order_status NOT IN ('cancelled', 'refunded')
             LEFT JOIN `products` p ON p.id = oi.product_id
             LEFT JOIN `categories` c ON c.id = p.category_id
             WHERE oi.order_id IN ({$paidInWindow})
             GROUP BY c.id, c.name
             ORDER BY revenue DESC",
            'ss',
            $w
        );

        $topProducts = $this->db->fetchAll(
            "SELECT oi.product_id, MAX(oi.item_name) AS name, SUM(oi.quantity) AS units_sold, SUM(oi.line_total) AS revenue
             FROM `order_items` oi
             JOIN `orders` o ON o.id = oi.order_id AND o.order_status NOT IN ('cancelled', 'refunded')
             WHERE oi.order_id IN ({$paidInWindow})
             GROUP BY oi.product_id
             ORDER BY units_sold DESC, revenue DESC
             LIMIT 10",
            'ss',
            $w
        );

        // Gateways: attempts started in the window, and money received in the window, per gateway.
        $attempts = array_column($this->db->fetchAll(
            "SELECT provider, COUNT(*) AS attempts,
                    COALESCE(SUM(status IN ({$received})), 0) AS successful,
                    COALESCE(SUM(status = 'failed'), 0) AS failed
             FROM `payments` WHERE created_at >= ? AND created_at < ? GROUP BY provider",
            'ss',
            $w
        ), null, 'provider');
        $volume = array_column($this->db->fetchAll(
            "SELECT provider, SUM(COALESCE(amount_received, amount)) AS volume FROM `payments`
             WHERE status IN ({$received}) AND verified_at >= ? AND verified_at < ? GROUP BY provider",
            'ss',
            $w
        ), 'volume', 'provider');

        $gateways = [];
        foreach (array_unique(array_merge(array_keys($attempts), array_keys($volume))) as $provider) {
            $a = $attempts[$provider] ?? ['attempts' => 0, 'successful' => 0, 'failed' => 0];
            $gateways[] = [
                'provider_name' => $provider,
                'total_attempts' => (int) $a['attempts'],
                'successful_count' => (int) $a['successful'],
                'failed_count' => (int) $a['failed'],
                'total_volume' => round((float) ($volume[$provider] ?? 0), 2),
            ];
        }
        usort($gateways, static fn ($x, $y) => $y['total_volume'] <=> $x['total_volume']);
        $pendingRefunds = $this->pendingRefunds();

        return [
            'summary' => [
                'currency' => 'NGN',
                'gross_sales' => $gross,
                'total_refunded' => $refunded,
                'refund_count' => (int) $refunds['cnt'],
                'owed_refunds' => round($owedTotal, 2),
                'owed_refund_count' => count($owed),
                'pending_refunds' => $pendingRefunds['total'],
                'pending_refund_count' => $pendingRefunds['count'],
                'net_sales' => round(max(0, $gross - $refunded - $owedTotal), 2),
                'paid_orders' => $paidOrders,
                'aov' => $paidOrders > 0 ? round($gross / $paidOrders, 2) : 0.0,
                'total_orders' => $placed,
                'conversion_rate' => $placed > 0 ? round(((int) $orders['placed_and_paid'] / $placed) * 100, 1) : 0.0,
                'repeat_customer_rate' => $paying > 0 ? round(((int) $customers['repeat_customers'] / $paying) * 100, 1) : 0.0,
                'paying_customers' => $paying,
                'total_units_sold' => $units,
                'pending_fulfillment' => (int) ($this->db->fetchOne("SELECT COUNT(*) AS c FROM `orders` WHERE order_status IN ('paid', 'processing')")['c'] ?? 0),
            ],
            'daily_breakdown' => $daily,
            'category_breakdown' => $categories,
            'top_products' => $topProducts,
            'gateway_breakdown' => $gateways,
            'owed_refunds' => $owed,
            'formulas' => [
                'gross_sales' => 'Sum of payments verified in the period (Kora, Monnify and confirmed bank transfers), by verification time.',
                'total_refunded' => 'Sum of refunds marked completed in the period, by completion time. Underpaid money sent back is left out, as it never counted as a sale.',
                'owed_refunds' => 'Orders paid in the period and later cancelled, minus anything already refunded: money the store still has to return.',
                'net_sales' => 'Gross sales − refunds completed − refunds owed on cancelled orders.',
                'pending_refunds' => 'Refunds recorded but not yet marked completed. They come off net sales on the day they are completed.',
                'aov' => 'Gross sales ÷ paid orders.',
                'conversion_rate' => 'Orders placed in the period that were paid ÷ orders placed in the period.',
                'repeat_customer_rate' => 'Customers with 2+ paid orders in the period ÷ customers who paid in the period.',
                'total_units_sold' => 'Units on orders paid in the period.',
                'top_products' => 'Item prices on orders paid in the period, before discounts and delivery fees. Cancelled and refunded orders are left out.',
                'category_breakdown' => 'Item prices on orders paid in the period, before discounts and delivery fees. Cancelled and refunded orders are left out.',
                'gateway_breakdown' => 'Attempts and success counts are payment attempts started in the period; volume is money received in the period.',
                'pending_fulfillment' => 'Paid or processing orders right now (not limited to the period).',
            ],
        ];
    }

    /**
     * GET /api/v1/admin/analytics/report?range=…|start_date=&end_date=
     */
    public function analyticsReport(): void
    {
        [$range, $start, $end] = $this->resolveDateRange();
        $report = $this->buildReport($start, $end);

        Response::success(array_merge([
            'filters' => [
                'range' => $range,
                'start_date' => $start,
                'end_date' => date('Y-m-d H:i:s', strtotime($end) - 1),
            ],
        ], $report), 'Analytics report generated.');
    }

    /**
     * GET /api/v1/admin/analytics/export — the same figures as the report, as CSV.
     */
    public function exportCsv(): void
    {
        [$range, $start, $end] = $this->resolveDateRange();
        $r = $this->buildReport($start, $end);
        $s = $r['summary'];
        $money = static fn ($v): string => number_format((float) $v, 2, '.', '');

        $out = $this->beginCsv(Shop::fileName() . '-analytics-' . $range . '-' . date('Ymd-His') . '.csv');

        fputcsv($out, ['Report period', substr($start, 0, 10), date('Y-m-d', strtotime($end) - 1)]);
        fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
        fputcsv($out, []);

        fputcsv($out, ['Metric', 'Value']);
        foreach ([
            ['Gross sales (NGN)', $money($s['gross_sales'])],
            ['Refunds completed (NGN)', $money($s['total_refunded'])],
            ['Refunds owed on cancelled orders (NGN)', $money($s['owed_refunds'])],
            ['Net sales (NGN)', $money($s['net_sales'])],
            ['Paid orders', (string) $s['paid_orders']],
            ['Average order value (NGN)', $money($s['aov'])],
            ['Orders placed', (string) $s['total_orders']],
            ['Placed orders that were paid (%)', (string) $s['conversion_rate']],
            ['Repeat customers (%)', (string) $s['repeat_customer_rate']],
            ['Units sold', (string) $s['total_units_sold']],
        ] as $row) {
            fputcsv($out, $row);
        }
        fputcsv($out, []);

        fputcsv($out, ['Date', 'Orders placed', 'Paid orders', 'Sales (NGN)', 'Refunds (NGN)']);
        foreach ($r['daily_breakdown'] as $d) {
            fputcsv($out, [$d['date'], $d['orders_count'], $d['paid_count'], $money($d['sales']), $money($d['refunds'])]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Category', 'Orders', 'Units sold', 'Item sales before discounts (NGN)']);
        foreach ($r['category_breakdown'] as $c) {
            fputcsv($out, [$this->sanitizeCsvCell($c['category_name']), $c['order_count'], $c['units_sold'], $money($c['revenue'])]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Gateway', 'Attempts', 'Successful', 'Failed', 'Received (NGN)']);
        foreach ($r['gateway_breakdown'] as $g) {
            fputcsv($out, [$this->sanitizeCsvCell($g['provider_name']), $g['total_attempts'], $g['successful_count'], $g['failed_count'], $money($g['total_volume'])]);
        }

        if ($r['owed_refunds'] !== []) {
            fputcsv($out, []);
            fputcsv($out, ['Cancelled after payment – refund owed']);
            fputcsv($out, ['Order', 'Paid (NGN)', 'Refunded (NGN)', 'Owed (NGN)']);
            foreach ($r['owed_refunds'] as $o) {
                fputcsv($out, [$this->sanitizeCsvCell($o['order_number']), $money($o['paid']), $money($o['refunded']), $money($o['owed'])]);
            }
        }

        fclose($out);
        exit;
    }

    /**
     * @return resource
     */
    private function beginCsv(string $filename)
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads ₦ and names correctly
        return $out;
    }

    /**
     * GET /api/v1/admin/orders/export
     * Stream authenticated CSV export of filtered orders list.
     * Enforces anti-CSV injection sanitization and NEVER exposes passwords or tokens.
     */
    public function exportOrdersCsv(): void
    {
        // Same filters as the admin order list; all dates unless a range is given.
        if (!isset($_GET['range']) && !isset($_GET['start_date'])) {
            $_GET['range'] = 'all';
        }
        [$range, $startDate, $endDate] = $this->resolveDateRange();
        $search = trim((string) ($_GET['search'] ?? ''));

        $status = $_GET['status'] ?? 'all';
        $paymentStatus = $_GET['payment_status'] ?? 'all';

        $sql = "
            SELECT 
                o.order_number,
                o.created_at,
                COALESCE(u.full_name, JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address_snapshot, '$.full_name')), 'Customer') AS customer_name,
                COALESCE(u.email, o.guest_email) AS customer_email,
                o.order_status,
                o.payment_status,
                p.provider AS payment_gateway,
                p.provider_reference AS transaction_reference,
                o.currency,
                o.subtotal,
                o.shipping_fee,
                o.discount_amount,
                o.grand_total,
                (SELECT COUNT(*) FROM `order_items` oi WHERE oi.order_id = o.id) AS items_count,
                JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address_snapshot, '$.state')) AS destination_state,
                JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address_snapshot, '$.city')) AS destination_city
            FROM `orders` o
            LEFT JOIN `users` u ON u.id = o.user_id
            LEFT JOIN `payments` p ON p.order_id = o.id AND p.id = (SELECT MAX(p2.id) FROM `payments` p2 WHERE p2.order_id = o.id)
            WHERE o.created_at >= ? AND o.created_at < ?
        ";

        $types = 'ss';
        $params = [$startDate, $endDate];

        // The same special filters as the order list (AdminOrderController::index).
        if ($status === 'attention') {
            $sql .= " AND o.requires_attention = 1";
        } elseif ($status === 'awaiting_whatsapp') {
            $sql .= " AND o.order_status = 'pending_payment' AND EXISTS (
                SELECT 1 FROM `payments` pw WHERE pw.order_id = o.id AND pw.provider = 'whatsapp' AND pw.status = 'pending')";
        } elseif ($status === 'payment_claimed') {
            $sql .= " AND o.order_status = 'pending_payment' AND o.customer_marked_paid_at IS NOT NULL";
        } elseif ($status !== 'all' && $status !== '') {
            $sql .= " AND o.order_status = ?";
            $types .= 's';
            $params[] = $status;
        }

        if ($paymentStatus !== 'all' && $paymentStatus !== '') {
            $sql .= " AND o.payment_status = ?";
            $types .= 's';
            $params[] = $paymentStatus;
        }

        if ($search !== '') {
            // A phone number is found however it was typed; otherwise this repeats the plain search.
            $phone = \App\Support\PhoneNumber::searchDigits($search) ?? $search;
            $sql .= " AND (o.order_number LIKE ? OR o.guest_email LIKE ? OR u.email LIKE ? OR o.shipping_address_snapshot LIKE ? OR o.shipping_address_snapshot LIKE ?
                      OR EXISTS (SELECT 1 FROM `shipments` sh WHERE sh.order_id = o.id AND sh.tracking_number LIKE ?))";
            $types .= 'ssssss';
            array_push($params, "%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%", "%{$phone}%", "%{$search}%");
        }

        $sql .= " ORDER BY o.created_at DESC";

        $orders = $this->db->fetchAll($sql, $types, $params);

        $filename = Shop::fileName() . '-orders-export-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // Add UTF-8 BOM
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        // Header row
        fputcsv($out, [
            'Order Number',
            'Order Date',
            'Customer Name',
            'Customer Email',
            'Order Status',
            'Payment Status',
            'Payment Gateway',
            'Transaction Ref',
            'Currency',
            'Items Count',
            'Subtotal',
            'Delivery Fee',
            'Discount Amount',
            'Grand Total',
            'Destination State',
            'Destination City'
        ]);

        foreach ($orders as $row) {
            fputcsv($out, [
                $this->sanitizeCsvCell($row['order_number']),
                $this->sanitizeCsvCell($row['created_at']),
                $this->sanitizeCsvCell($row['customer_name']),
                $this->sanitizeCsvCell($row['customer_email']),
                $this->sanitizeCsvCell($row['order_status']),
                $this->sanitizeCsvCell($row['payment_status']),
                $this->sanitizeCsvCell($row['payment_gateway'] ?? 'N/A'),
                $this->sanitizeCsvCell($row['transaction_reference'] ?? 'N/A'),
                $this->sanitizeCsvCell($row['currency']),
                (string) $row['items_count'],
                number_format((float) $row['subtotal'], 2),
                number_format((float) $row['shipping_fee'], 2),
                number_format((float) $row['discount_amount'], 2),
                number_format((float) $row['grand_total'], 2),
                $this->sanitizeCsvCell($row['destination_state'] ?? 'N/A'),
                $this->sanitizeCsvCell($row['destination_city'] ?? 'N/A')
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * Prevent CSV formula injection by prepending single quote if cell starts with =, +, -, @, \t, or \r.
     */
    private function sanitizeCsvCell(mixed $value): string
    {
        $str = (string) ($value ?? '');
        if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $str;
        }
        return $str;
    }
}
