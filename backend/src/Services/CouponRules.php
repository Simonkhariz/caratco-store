<?php

declare(strict_types=1);

namespace App\Services;

/**
 * How much a promotion code takes off, and how to describe it. The one place these rules live:
 * checkout, the promo check, the homepage and admin all use it (the storefront mirrors the same
 * rules in lib/coupon.ts only to preview the cart total; the server's figure always wins).
 *
 *   percent: discount_percent of the items subtotal, never more than max_discount_amount if set
 *   fixed:   discount_amount off
 *   both:    only when the items subtotal is at least min_order_amount (if set), and never more
 *            than the subtotal itself
 */
final class CouponRules
{
    /**
     * Which code the homepage advertises: the newest live one the owner ticked. Every other code
     * stays private. Used by the homepage and by the admin list's "showing now" note.
     */
    public const ADVERTISED_SQL = "`show_on_homepage` = 1 AND `is_active` = 1
        AND (`starts_at` IS NULL OR `starts_at` <= NOW())
        AND (`expires_at` IS NULL OR `expires_at` >= NOW())
        AND (`max_uses` <= 0 OR `used_count` < `max_uses`)
        ORDER BY `id` DESC LIMIT 1";

    /** @param array<string, mixed> $promo a promotions row */
    public static function type(array $promo): string
    {
        return ($promo['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
    }

    /** @param array<string, mixed> $promo */
    public static function minimum(array $promo): ?float
    {
        $min = $promo['min_order_amount'] ?? null;
        return $min === null || $min === '' || (float) $min <= 0 ? null : round((float) $min, 2);
    }

    /** @param array<string, mixed> $promo */
    public static function cap(array $promo): ?float
    {
        $cap = $promo['max_discount_amount'] ?? null;
        return self::type($promo) !== 'percent' || $cap === null || $cap === '' || (float) $cap <= 0 ? null : round((float) $cap, 2);
    }

    /** @param array<string, mixed> $promo */
    public static function fixedAmount(array $promo): float
    {
        return round(max(0.0, (float) ($promo['discount_amount'] ?? 0)), 2);
    }

    /**
     * The amount off for this items subtotal (0 below the minimum).
     *
     * @param array<string, mixed> $promo
     */
    public static function discountFor(array $promo, float $subtotal): float
    {
        if ($subtotal <= 0) {
            return 0.0;
        }
        $min = self::minimum($promo);
        if ($min !== null && $subtotal + 0.001 < $min) {
            return 0.0;
        }
        $discount = self::type($promo) === 'fixed'
            ? self::fixedAmount($promo)
            : round($subtotal * ((int) ($promo['discount_percent'] ?? 0) / 100.0), 2);
        $cap = self::cap($promo);
        if ($cap !== null) {
            $discount = min($discount, $cap);
        }
        return round(min($discount, $subtotal), 2);
    }

    /**
     * Short wording, e.g. "10% off (up to ₦10,000) on orders of ₦20,000+" or "₦5,000 off".
     *
     * @param array<string, mixed> $promo
     */
    public static function label(array $promo): string
    {
        $naira = static fn (float $v): string => '₦' . number_format($v, $v == floor($v) ? 0 : 2);
        $text = self::type($promo) === 'fixed'
            ? $naira(self::fixedAmount($promo)) . ' off'
            : (int) ($promo['discount_percent'] ?? 0) . '% off';
        $cap = self::cap($promo);
        if ($cap !== null) {
            $text .= ' (up to ' . $naira($cap) . ')';
        }
        $min = self::minimum($promo);
        if ($min !== null) {
            $text .= ' on orders of ' . $naira($min) . '+';
        }
        return $text;
    }

    /**
     * The rule fields the storefront needs to preview the discount and show the wording.
     *
     * @param array<string, mixed> $promo
     * @return array{discount_type: string, discount_percent: int, discount_value: float, min_order_amount: ?float, max_discount_amount: ?float, label: string}
     */
    public static function publicFields(array $promo): array
    {
        return [
            'discount_type' => self::type($promo),
            'discount_percent' => self::type($promo) === 'percent' ? (int) ($promo['discount_percent'] ?? 0) : 0,
            'discount_value' => self::type($promo) === 'fixed' ? self::fixedAmount($promo) : 0.0,
            'min_order_amount' => self::minimum($promo),
            'max_discount_amount' => self::cap($promo),
            'label' => self::label($promo),
        ];
    }
}
