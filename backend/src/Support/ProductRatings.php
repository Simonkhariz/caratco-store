<?php

declare(strict_types=1);

namespace App\Support;

use App\Database\Database;

/**
 * The star rating a product shows.
 *
 * A product starts from a starting rating: its own (products.rating_start, set in the product
 * editor) or, when it has none, the store default (setting `default_product_rating`, 4.5 unless
 * changed; empty means no starting rating). The starting rating counts like WEIGHT reviews, so
 * real reviews take over as they come in: with a 4.5 start, one 1-star review shows 3.9, and
 * twenty 3-star reviews show 3.3. With no starting rating at all, only reviews count.
 *
 * rating_count is always the real number of approved reviews, and review_avg their own average
 * (shown to the admin), so the shop never claims reviews that do not exist.
 */
final class ProductRatings
{
    public const SETTING = 'default_product_rating';
    public const DEFAULT = 4.5;
    public const WEIGHT = 5;

    /**
     * Admin input -> rating. '' or null = none; 1 to 5 with at most one decimal (4, 4.5, 4.7).
     *
     * @return float|null|false false when the value is not a valid rating
     */
    public static function parse(mixed $value): float|null|false
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (is_bool($value) || is_array($value) || !is_numeric($value)) {
            return false;
        }
        $rating = (float) $value;
        if ($rating < 1 || $rating > 5 || abs(round($rating, 1) - $rating) > 0.0001) {
            return false;
        }
        return round($rating, 1);
    }

    /** The store's default starting rating, or null when the owner left it empty. */
    public static function storeDefault(Database $db): ?float
    {
        $row = $db->fetchOne("SELECT `value` FROM `store_settings` WHERE `key_name` = ? LIMIT 1", 's', [self::SETTING]);
        if ($row === null) {
            return self::DEFAULT;
        }
        $parsed = self::parse($row['value']);
        return $parsed === false ? self::DEFAULT : $parsed;
    }

    /** Recompute one product's rating, or every product's when $productId is null. */
    public static function refresh(Database $db, ?int $productId = null): void
    {
        $default = self::storeDefault($db);
        $reviewFilter = $productId !== null ? ' AND `product_id` = ?' : '';
        $productFilter = $productId !== null ? ' WHERE p.id = ?' : '';
        $weight = self::WEIGHT;

        $sql = "UPDATE `products` p
                LEFT JOIN (
                    SELECT `product_id`, AVG(`rating`) AS avg_rating, COUNT(*) AS review_count
                    FROM `reviews`
                    WHERE `status` = 'approved'{$reviewFilter}
                    GROUP BY `product_id`
                ) r ON r.product_id = p.id
                SET p.rating_count = COALESCE(r.review_count, 0),
                    p.review_avg = COALESCE(ROUND(r.avg_rating, 2), 0),
                    p.rating_avg = CASE
                        WHEN COALESCE(p.rating_start, ?) IS NULL THEN COALESCE(ROUND(r.avg_rating, 2), 0)
                        ELSE ROUND((COALESCE(p.rating_start, ?) * {$weight} + COALESCE(r.avg_rating, 0) * COALESCE(r.review_count, 0))
                                   / ({$weight} + COALESCE(r.review_count, 0)), 2)
                    END{$productFilter}";

        if ($productId !== null) {
            $db->execute($sql, 'iddi', [$productId, $default, $default, $productId]);
        } else {
            $db->execute($sql, 'dd', [$default, $default]);
        }
    }
}
