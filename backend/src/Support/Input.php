<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reading text fields from JSON bodies safely. With strict types, trim() on a number or a list
 * throws a TypeError (a 500 instead of a clear 422), so request values go through here first.
 * Also the limits admin forms share: a database that is not in strict mode silently cuts or caps
 * values too big for their column, so they are refused before they get there.
 */
final class Input
{
    /** Largest price or discount (order totals are DECIMAL(12,2), so a big order still fits). */
    public const MAX_MONEY = 99999999.99;
    public const MAX_STOCK = 1000000;
    public const MAX_DISPLAY_ORDER = 100000;
    public const MAX_PROMO_USES = 1000000;
    private const MAX_PAGE = 1000000;
    /** Room left for "-2" and for the shorter slug column on categories. */
    private const MAX_GENERATED_SLUG = 90;

    /**
     * The value as trimmed text: numbers and booleans become text, null becomes $default, and a
     * list or object becomes '' so the usual "required" validation reports it.
     */
    public static function text(mixed $value, string $default = ''): string
    {
        if ($value === null) {
            return trim($default);
        }
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return trim((string) $value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        return '';
    }

    /** The value as text with runs of spaces collapsed to one ("Top  Wear" -> "Top Wear"). */
    public static function singleSpaced(mixed $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', self::text($value));
    }

    public static function tooLong(mixed $value, int $max): bool
    {
        return mb_strlen(self::text($value)) > $max;
    }

    /** The value as a whole number within the limits, or null when it is anything else. */
    public static function wholeNumber(mixed $value, int $min, int $max): ?int
    {
        if (is_bool($value) || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        if ($number !== floor($number) || $number < $min || $number > $max) {
            return null;
        }
        return (int) $number;
    }

    /** A number from $min up to the largest amount the shop accepts. */
    public static function isMoney(mixed $value, float $min = 0.0): bool
    {
        return !is_bool($value) && is_numeric($value) && (float) $value >= $min && (float) $value <= self::MAX_MONEY;
    }

    /** "₦99,999,999.99", for messages. */
    public static function maxMoneyLabel(): string
    {
        return '₦' . number_format(self::MAX_MONEY, 2);
    }

    /**
     * The ?page= number. Anything that is not a number reads as page 1; an absurdly large one
     * answers 422 (it would overflow into a float and break LIMIT/OFFSET).
     */
    public static function page(mixed $value): int
    {
        $page = is_numeric($value) ? (float) $value : 1.0;
        if ($page > self::MAX_PAGE) {
            Response::error('That page number is too large.', 422, 'VALIDATION_ERROR', ['page' => 'That page number is too large.']);
        }
        return max(1, (int) $page);
    }

    /**
     * A web address made from a name when the admin gave none. It is never only a number ("501"
     * would be read as an ID, so it becomes "product-501") and never one already in use.
     *
     * @param callable(string): bool $taken
     */
    public static function slugFromName(string $name, string $prefix, callable $taken): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        if ($slug === '' || is_numeric($slug)) {
            $slug = trim("{$prefix}-{$slug}", '-');
        }
        $slug = rtrim(substr($slug, 0, self::MAX_GENERATED_SLUG), '-');

        $candidate = $slug;
        for ($n = 2; $taken($candidate); $n++) {
            $candidate = "{$slug}-{$n}";
        }
        return $candidate;
    }
}
