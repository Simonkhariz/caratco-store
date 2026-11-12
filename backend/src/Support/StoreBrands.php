<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The brands shown in the homepage logo strip (store setting `storefront_brands`).
 */
final class StoreBrands
{
    /**
     * "Nike, Adidas,, puma " -> ["Nike", "Adidas", "puma"]: trimmed, empties and repeats dropped.
     *
     * @return string[]
     */
    public static function parse(string $value): array
    {
        $brands = [];
        foreach (explode(',', $value) as $part) {
            $name = trim(preg_replace('/\s+/', ' ', $part) ?? '');
            if ($name !== '' && !in_array(mb_strtolower($name), array_map('mb_strtolower', $brands), true)) {
                $brands[] = $name;
            }
        }
        return $brands;
    }
}
