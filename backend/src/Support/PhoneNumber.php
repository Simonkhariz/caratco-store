<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Phone numbers in the international format WhatsApp needs (wa.me links).
 */
final class PhoneNumber
{
    /** What a customer's phone number must be: the same rule as lib/checkout-validation.ts. */
    public const NIGERIAN_RULE = 'Enter a Nigerian phone number: 11 digits starting with 0, or +234 and 10 digits.';

    /**
     * 11 digits starting with 0, or 234 and 10 digits (with or without the +, and with the 0 some
     * people keep after it); spaces, dashes and brackets are ignored.
     */
    public static function isNigerian(string $raw): bool
    {
        $clean = preg_replace('/[\s()-]/u', '', $raw) ?? '';
        return preg_match('/^(?:0[0-9]{10}|\+?2340?[0-9]{10})$/', $clean) === 1;
    }

    /**
     * What to look for when an admin searches for a phone number, however they typed it: the part
     * every saved form shares ("0803 123 4567" and "+234 803..." both give 8031234567). Null when
     * the search isn't a phone number.
     */
    public static function searchDigits(string $term): ?string
    {
        if (!preg_match('/^[+0-9\s()-]+$/', $term)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $term) ?? '';
        $digits = preg_replace('/^(?:234)?0?/', '', $digits) ?? '';
        return strlen($digits) >= 7 ? $digits : null;
    }

    /** How a customer's number is saved: one format however it was typed, so orders read alike. */
    public static function stored(string $raw): string
    {
        return self::toInternational($raw) ?? $raw;
    }

    /**
     * Normalise a number typed in Nigerian local format to "+234...".
     *   0803 123 4567     -> +2348031234567
     *   803 123 4567      -> +2348031234567
     *   +234 0803 123 4567 -> +2348031234567
     *   +44 20 7946 0958  -> +442079460958 (other countries are kept as typed)
     * Returns null when the input cannot be a phone number.
     */
    public static function toInternational(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || !preg_match('/^\+?[0-9 ()-]{7,24}$/', $raw)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 11 && $digits[0] === '0') {
            $digits = '234' . substr($digits, 1);
        } elseif (strlen($digits) === 10 && in_array($digits[0], ['7', '8', '9'], true)) {
            $digits = '234' . $digits;
        } elseif (strlen($digits) === 14 && str_starts_with($digits, '2340')) {
            $digits = '234' . substr($digits, 4);
        }

        if (str_starts_with($digits, '234') && strlen($digits) !== 13) {
            return null;
        }
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return '+' . $digits;
    }
}
