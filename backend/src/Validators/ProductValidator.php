<?php

declare(strict_types=1);

namespace App\Validators;

use App\Config\Shop;
use App\Repositories\ProductRepository;
use App\Support\Input;

class ProductValidator
{
    public const MAX_SPECIFICATIONS = 30;
    public const MAX_SPECIFICATION_NAME = 60;
    public const MAX_SPECIFICATION_VALUE = 300;
    public const MAX_WARRANTY = 160;

    /**
     * Validate product input data for creation or updates.
     *
     * @param array<string, mixed> $data
     * @return array<string, string> Array of validation error messages keyed by field name
     */
    public static function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (!$isUpdate || array_key_exists('name', $data)) {
            $name = Input::text($data['name'] ?? '');
            if ($name === '' || strlen($name) < 3) {
                $errors['name'] = 'Product name is required and must be at least 3 characters.';
            } elseif (mb_strlen($name) > 255) {
                $errors['name'] = 'Product name can be at most 255 characters.';
            }
        }

        if (!$isUpdate || array_key_exists('slug', $data)) {
            $slug = Input::text($data['slug'] ?? '');
            if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
                $errors['slug'] = 'Valid URL slug (lowercase letters, numbers, hyphens) is required.';
            } elseif (is_numeric($slug)) {
                $errors['slug'] = 'A slug cannot be only a number like 501 or 2e3: it would be read as a product ID. Add a word, e.g. product-501.';
            } elseif (mb_strlen($slug) > 255) {
                $errors['slug'] = 'A slug can be at most 255 characters.';
            }
        }

        if (!$isUpdate || array_key_exists('base_price', $data)) {
            if (!isset($data['base_price']) || !is_numeric($data['base_price']) || (float) $data['base_price'] <= 0) {
                $errors['base_price'] = 'Base price must be a positive number.';
            } elseif (!Input::isMoney($data['base_price'])) {
                $errors['base_price'] = 'Base price can be at most ' . Input::maxMoneyLabel() . '.';
            }
        }

        if (!in_array($data['original_price'] ?? null, [null, ''], true) && !Input::isMoney($data['original_price'])) {
            $errors['original_price'] = 'The "was" price must be from ₦0 to ' . Input::maxMoneyLabel() . '.';
        }

        if (!in_array($data['category_id'] ?? null, [null, ''], true) && Input::wholeNumber($data['category_id'], 0, 2147483647) === null) {
            $errors['category_id'] = 'Choose a category from the list.';
        }

        if (!$isUpdate || array_key_exists('sku', $data)) {
            $sku = Input::text($data['sku'] ?? '');
            if ($sku === '' || strlen($sku) < 3) {
                $errors['sku'] = 'SKU is required and must be at least 3 characters.';
            } elseif (mb_strlen($sku) > 100) {
                $errors['sku'] = 'SKU can be at most 100 characters.';
            }
        }

        if (is_array($data['description'] ?? null) || strlen(Input::text($data['description'] ?? '')) > 65535) {
            $errors['description'] = 'Enter the description as plain text, up to 65,000 characters.';
        }

        if (is_array($data['dress_style'] ?? null) || Input::tooLong($data['dress_style'] ?? '', 50)) {
            $errors['dress_style'] = 'Style can be at most 50 characters.';
        }

        foreach (['stock_quantity' => 'Stock', 'low_stock_threshold' => 'Low-stock level'] as $field => $label) {
            if (isset($data[$field]) && Input::wholeNumber($data[$field], 0, Input::MAX_STOCK) === null) {
                $errors[$field] = "{$label} must be a whole number from 0 to " . number_format(Input::MAX_STOCK) . '.';
            }
        }

        if (array_key_exists('variants', $data) && ($problem = self::variantsProblem($data['variants'])) !== null) {
            $errors['variants'] = $problem;
        }

        if (array_key_exists('images', $data) && ($problem = self::imagesProblem($data['images'])) !== null) {
            $errors['images'] = $problem;
        }

        if (array_key_exists('specifications', $data) && ($problem = self::specificationsProblem($data['specifications'])) !== null) {
            $errors['specifications'] = $problem;
        }

        if (is_array($data['warranty'] ?? null) || is_bool($data['warranty'] ?? null)) {
            $errors['warranty'] = 'Enter the warranty as plain text, or leave it empty.';
        } elseif (Input::tooLong($data['warranty'] ?? '', self::MAX_WARRANTY)) {
            $errors['warranty'] = 'Warranty can be at most ' . self::MAX_WARRANTY . ' characters.';
        }

        if (array_key_exists('is_heavy', $data) && !in_array($data['is_heavy'], [true, false, 0, 1, '0', '1'], true)) {
            $errors['is_heavy'] = 'Say whether this is a heavy item with yes or no.';
        }

        if (isset($data['product_type']) && $data['product_type'] !== 'physical') {
            $errors['product_type'] = "Product type must be 'physical'. Digital products are not supported in this store.";
        }

        if (isset($data['status']) && !in_array($data['status'], ['draft', 'published', 'archived'], true)) {
            $errors['status'] = "Status must be 'draft', 'published', or 'archived'.";
        }

        if (array_key_exists('rating_start', $data) && \App\Support\ProductRatings::parse($data['rating_start']) === false) {
            $errors['rating_start'] = 'Enter a starting rating from 1 to 5 (for example 4.5), or leave it empty.';
        }

        if (!empty($data['flash_deal_ends_at']) && strtotime(str_replace('T', ' ', Input::text($data['flash_deal_ends_at']))) === false) {
            $errors['flash_deal_ends_at'] = 'Enter a valid end date and time for the flash deal.';
        }

        return $errors;
    }

    /**
     * The first thing wrong with a form's variant rows, or null when they are fine. Stock that
     * was not changed is not re-checked, so a product whose stock is already over the limit can
     * still be saved.
     */
    private static function variantsProblem(mixed $variants): ?string
    {
        if ($variants === null) {
            return null;
        }
        if (!is_array($variants)) {
            return 'Send the variants as a list.';
        }
        // The two options are named by the shop ("size" and "colour", "volume" and "scent").
        $first = Shop::option1Word();
        $second = Shop::option2Word();
        $seen = [];
        foreach (array_values($variants) as $i => $v) {
            $row = 'Variant ' . ($i + 1) . ': ';
            if (!is_array($v) || is_array($v['size'] ?? null) || is_array($v['color_name'] ?? null)) {
                return $row . 'send it as ' . Shop::withArticle($first) . ' and ' . Shop::withArticle($second) . '.';
            }
            if (Input::tooLong($v['size'] ?? '', 50) || Input::tooLong($v['color_name'] ?? '', 50)) {
                return $row . "{$first} and {$second} can be at most 50 characters.";
            }
            // An option left empty is fine: the product does not use it (it is stored as the
            // shop's "none" word). Two variants still cannot be the same pair.
            $options = ProductRepository::variantOptions($v);
            $pair = mb_strtolower($options['size']) . '|' . mb_strtolower($options['color_name']);
            if (isset($seen[$pair])) {
                return $row . "another variant already has the same {$first} and {$second}.";
            }
            $seen[$pair] = true;
            if (is_array($v['sku'] ?? null) || Input::tooLong($v['sku'] ?? '', 100)) {
                return $row . 'SKU can be at most 100 characters.';
            }
            if (is_array($v['color_hex'] ?? null) || Input::tooLong($v['color_hex'] ?? '', 10)) {
                return $row . "{$second} code can be at most 10 characters.";
            }
            if (!in_array($v['price_override'] ?? null, [null, ''], true) && !Input::isMoney($v['price_override'])) {
                return $row . 'price must be from ₦0 to ' . Input::maxMoneyLabel() . '.';
            }
            if (isset($v['stock_quantity'])) {
                $unchanged = is_numeric($v['original_stock_quantity'] ?? null) && is_numeric($v['stock_quantity'])
                    && (float) $v['original_stock_quantity'] === (float) $v['stock_quantity'];
                if (!$unchanged && Input::wholeNumber($v['stock_quantity'], 0, Input::MAX_STOCK) === null) {
                    return $row . 'stock must be a whole number from 0 to ' . number_format(Input::MAX_STOCK) . '.';
                }
            }
            if (isset($v['low_stock_threshold']) && Input::wholeNumber($v['low_stock_threshold'], 0, Input::MAX_STOCK) === null) {
                return $row . 'low-stock level must be a whole number from 0 to ' . number_format(Input::MAX_STOCK) . '.';
            }
        }
        return null;
    }

    /**
     * The first thing wrong with the specification rows ("Material: Cotton"), or null. A row
     * without a name or without a value is not a problem: the form's empty rows are simply dropped.
     */
    private static function specificationsProblem(mixed $specifications): ?string
    {
        if ($specifications === null) {
            return null;
        }
        if (!is_array($specifications) || !array_is_list($specifications)) {
            return 'Send the specifications as a list of names and values.';
        }
        foreach ($specifications as $i => $spec) {
            $row = 'Specification ' . ($i + 1) . ': ';
            if (!is_array($spec) || is_array($spec['name'] ?? null) || is_array($spec['value'] ?? null)) {
                return $row . 'send it as a name and a value.';
            }
            if (Input::tooLong($spec['name'] ?? '', self::MAX_SPECIFICATION_NAME)) {
                return $row . 'the name can be at most ' . self::MAX_SPECIFICATION_NAME . ' characters.';
            }
            if (Input::tooLong($spec['value'] ?? '', self::MAX_SPECIFICATION_VALUE)) {
                return $row . 'the value can be at most ' . self::MAX_SPECIFICATION_VALUE . ' characters.';
            }
        }
        if (count(ProductRepository::specificationRows($specifications)) > self::MAX_SPECIFICATIONS) {
            return 'A product can have at most ' . self::MAX_SPECIFICATIONS . ' specifications.';
        }
        return null;
    }

    private static function imagesProblem(mixed $images): ?string
    {
        if ($images === null) {
            return null;
        }
        if (!is_array($images)) {
            return 'Send the photos as a list.';
        }
        foreach ($images as $img) {
            if (!is_array($img) || Input::text($img['image_url'] ?? '') === '' || Input::tooLong($img['image_url'], 500)) {
                return 'Every photo needs an address of at most 500 characters.';
            }
            if (is_array($img['alt_text'] ?? null) || Input::tooLong($img['alt_text'] ?? '', 255)) {
                return 'A photo description can be at most 255 characters.';
            }
        }
        return null;
    }

    /**
     * Validate an inventory stock adjustment.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public static function validateInventoryAdjustment(array $data): array
    {
        $errors = [];

        if (empty($data['variant_id']) || !is_numeric($data['variant_id']) || (int) $data['variant_id'] <= 0) {
            $errors['variant_id'] = 'Valid variant ID is required.';
        }

        if (!isset($data['adjustment']) || !Input::wholeNumber($data['adjustment'], -Input::MAX_STOCK, Input::MAX_STOCK)) {
            $errors['adjustment'] = 'Adjustment quantity must be a non-zero whole number up to ' . number_format(Input::MAX_STOCK) . ' (e.g. +10 or -5).';
        }

        $reason = Input::text($data['reason'] ?? '');
        if ($reason === '') {
            $errors['reason'] = 'Adjustment reason is required (e.g. restock, damage, return, audit_reconciliation).';
        }

        return $errors;
    }

    /**
     * Validate private digital deliverable metadata.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public static function validateDigitalDeliverable(array $data): array
    {
        $errors = [];

        $fileName = trim((string) ($data['file_name'] ?? ''));
        if ($fileName === '' || strlen($fileName) < 3) {
            $errors['file_name'] = 'File name is required (e.g. guide.pdf, presets.zip).';
        }

        if (isset($data['file_size_bytes']) && (!is_numeric($data['file_size_bytes']) || (int) $data['file_size_bytes'] < 0)) {
            $errors['file_size_bytes'] = 'File size in bytes must be a non-negative number.';
        }

        return $errors;
    }
}

