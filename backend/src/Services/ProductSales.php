<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use Throwable;

/**
 * Keeps products.units_sold (units on paid orders that were not cancelled or fully refunded)
 * up to date, so "Best selling" is an indexed column instead of adding up every order line
 * on each catalogue request (that took minutes once there were 150k order lines).
 */
final class ProductSales
{
    /** Orders whose units count as sold. */
    private const COUNTED = "o.`payment_status` IN ('successful', 'partially_refunded') AND o.`order_status` NOT IN ('cancelled', 'refunded')";

    /**
     * Recount the products on one order after its payment or order status changed.
     * Never lets a statistics problem break a payment, refund or status change.
     *
     * Inside a transaction the recount waits until after the commit. Its subquery reads every
     * order holding the product with shared locks, so running it inside two payments for
     * orders sharing a product could deadlock them, and the database would then roll one
     * payment back while this method carried on as if nothing happened.
     */
    public static function refreshForOrder(Database $db, int $orderId): void
    {
        if ($db->inTransaction()) {
            $db->afterCommit(static fn () => self::recount($db, $orderId));
            return;
        }
        self::recount($db, $orderId);
    }

    private static function recount(Database $db, int $orderId): void
    {
        try {
            $db->executeUpdate(
                "UPDATE `products` p
                 JOIN (SELECT DISTINCT `product_id` FROM `order_items` WHERE `order_id` = ? AND `product_id` IS NOT NULL) t
                   ON t.`product_id` = p.`id`
                 SET p.`units_sold` = (
                     SELECT COALESCE(SUM(oi.`quantity`), 0)
                     FROM `order_items` oi JOIN `orders` o ON o.`id` = oi.`order_id`
                     WHERE oi.`product_id` = p.`id` AND " . self::COUNTED . "
                 )",
                'i',
                [$orderId]
            );
        } catch (Throwable $e) {
            error_log('[ProductSales] Could not refresh units sold for order ' . $orderId . ': ' . $e->getMessage());
        }
    }

    /** Recount every product (maintenance; the migration does the same once). */
    public static function refreshAll(Database $db): int
    {
        return $db->executeUpdate(
            "UPDATE `products` p
             LEFT JOIN (
                 SELECT oi.`product_id`, SUM(oi.`quantity`) AS qty
                 FROM `order_items` oi JOIN `orders` o ON o.`id` = oi.`order_id`
                 WHERE oi.`product_id` IS NOT NULL AND " . self::COUNTED . "
                 GROUP BY oi.`product_id`
             ) s ON s.`product_id` = p.`id`
             SET p.`units_sold` = COALESCE(s.qty, 0)"
        );
    }
}
