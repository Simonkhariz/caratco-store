<?php

declare(strict_types=1);

namespace App\Validators;

use App\Support\Input;

class CategoryValidator
{
    /**
     * Validate category input data.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public static function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (!$isUpdate || array_key_exists('name', $data)) {
            $name = Input::text($data['name'] ?? '');
            if ($name === '' || strlen($name) < 2) {
                $errors['name'] = 'Category name is required (min 2 characters).';
            } elseif (mb_strlen($name) > 100) {
                $errors['name'] = 'Category name can be at most 100 characters.';
            }
        }

        if (!$isUpdate || array_key_exists('slug', $data)) {
            $slug = Input::text($data['slug'] ?? '');
            if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
                $errors['slug'] = 'Valid lowercase URL slug is required.';
            } elseif (is_numeric($slug)) {
                $errors['slug'] = 'A slug cannot be only a number like 501 or 2e3: it would be read as an ID. Add a word, e.g. category-501.';
            } elseif (mb_strlen($slug) > 100) {
                $errors['slug'] = 'A slug can be at most 100 characters.';
            }
        }

        if (array_key_exists('display_order', $data) && !in_array($data['display_order'], [null, ''], true)
            && Input::wholeNumber($data['display_order'], 0, Input::MAX_DISPLAY_ORDER) === null) {
            $errors['display_order'] = 'Display order must be a whole number from 0 to ' . number_format(Input::MAX_DISPLAY_ORDER) . '.';
        }

        if (is_array($data['description'] ?? null)) {
            $errors['description'] = 'Enter the description as plain text.';
        }

        if (is_array($data['image_url'] ?? null) || Input::tooLong($data['image_url'] ?? '', 500)) {
            $errors['image_url'] = 'Enter one image address of at most 500 characters.';
        }

        return $errors;
    }
}
