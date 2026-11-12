<?php

declare(strict_types=1);

namespace App\Config;

/**
 * What kind of shop this copy is: its name, the names of the two product options and the wording
 * that changes from one business to the next. Read from ONE file, backend/config/shop.json, which
 * the storefront reads too (lib/shop.ts), so server messages, emails and screens always agree.
 *
 * To turn this project into another shop, edit that file. Nothing here needs to change.
 */
final class Shop
{
    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    /** @var array<string, mixed> Values laid over the file by a test (see overrideForTests). */
    private static array $testOverrides = [];

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$config === null) {
            $config = self::readFile();
            if (self::testing()) {
                $fromEnv = json_decode((string) Env::get('SHOP_CONFIG_OVERRIDES', ''), true);
                $config = self::merge(self::merge($config, is_array($fromEnv) ? $fromEnv : []), self::$testOverrides);
            }
            self::$config = $config;
        }
        return self::$config;
    }

    /**
     * The settings file, decoded. A shop whose file is missing or broken must not quietly run as
     * some default shop with its age check and heavy item fee switched off, so this never falls
     * back: it writes one line to the PHP error log and stops. A web request gets a 500 that says
     * so (public/index.php reads the file before any route runs); a command-line script prints the
     * same message and ends.
     *
     * @return array<string, mixed>
     */
    private static function readFile(): array
    {
        $path = dirname(__DIR__, 2) . '/config/shop.json';
        // Tests only: another file, to check what a missing or broken one does.
        $other = self::testing() ? trim((string) Env::get('SHOP_CONFIG_FILE', '')) : '';
        if ($other !== '') {
            $path = $other;
        }

        $raw = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            self::stop('it is missing or cannot be opened');
        }
        // Some Windows editors save a byte order mark in front of the text; JSON does not allow one.
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            self::stop('it is not valid JSON (' . (json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'it must be one object') . ')');
        }
        return $decoded;
    }

    private static bool $problemLogged = false;

    private static function stop(string $problem): never
    {
        $message = "This shop's settings file could not be read: backend/config/shop.json, {$problem}. The shop cannot run without it.";
        // A script run by hand logs to the screen unless PHP has a log file: there the line below is the log.
        $cli = PHP_SAPI === 'cli';
        if (!self::$problemLogged && (!$cli || (string) ini_get('error_log') !== '')) {
            self::$problemLogged = true;
            error_log('[Shop] ' . $message);
        }
        if ($cli) {
            fwrite(STDERR, $message . "\n");
            exit(1);
        }
        throw new ShopConfigError($message);
    }

    /*
     * FOR TESTS ONLY. The tests cannot edit shop.json (every shop copy runs the same suites against
     * its own file), yet they must check how another kind of shop behaves: age checks, heavy items,
     * a second option that is not a colour. So when, and only when, APP_ENV is "testing":
     *
     *   - the env value SHOP_CONFIG_OVERRIDES (JSON, e.g. {"features":{"ageCheck":true}}) is laid
     *     over the file. A suite starts its own API with it (TestKit::startApi), the same way
     *     DEV_MODE_DEFAULT reaches the test API through its environment;
     *   - overrideForTests() does the same inside the test's own PHP process;
     *   - the env value SHOP_CONFIG_FILE names another file to read instead of shop.json (see readFile).
     *
     * Objects are merged key by key; lists and plain values replace the file's. On a real shop
     * (any other APP_ENV) both are ignored, so a stray env value can never change the shop.
     */
    private static function testing(): bool
    {
        return strtolower((string) Env::get('APP_ENV', '')) === 'testing';
    }

    /**
     * Lay these values over shop.json for the rest of this PHP process (an empty array puts the
     * file's own values back). Does nothing unless APP_ENV is "testing".
     *
     * @param array<string, mixed> $values
     */
    public static function overrideForTests(array $values): void
    {
        self::$testOverrides = self::testing() ? $values : [];
        self::$config = null;
    }

    /**
     * $over laid over $base: objects are merged key by key, lists and plain values replace.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $bothObjects = is_array($value) && !array_is_list($value)
                && is_array($base[$key] ?? null) && !array_is_list($base[$key]);
            $base[$key] = $bothObjects ? self::merge($base[$key], $value) : $value;
        }
        return $base;
    }

    /** A value by dotted path ("option1.label"), or the default when it is missing. */
    public static function get(string $path, mixed $default = null): mixed
    {
        $value = self::all();
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }
        return $value;
    }

    private static function text(string $path, string $default): string
    {
        $value = self::get($path);
        return is_string($value) && trim($value) !== '' ? $value : $default;
    }

    /** The store's name when the owner has not saved one in Store settings. */
    public static function name(): string
    {
        return self::text('name', 'Store');
    }

    /** The line under the name in emails and the footer. */
    public static function tagline(): string
    {
        return self::text('tagline', '');
    }

    /** "Size", "Volume", "Length": the first product option. */
    public static function option1(): string
    {
        return self::text('option1.label', 'Size');
    }

    /** "Colour", "Scent", "Metal": the second product option. */
    public static function option2(): string
    {
        return self::text('option2.label', 'Colour');
    }

    /** Lower case, for the middle of a sentence. */
    public static function option1Word(): string
    {
        return mb_strtolower(self::option1());
    }

    public static function option2Word(): string
    {
        return mb_strtolower(self::option2());
    }

    /** The stored value meaning "this product does not use the first option". */
    public static function option1None(): string
    {
        return self::text('option1.none', 'Standard');
    }

    public static function option2None(): string
    {
        return self::text('option2.none', 'Default');
    }

    /** The order the first option's choices are listed in (anything else follows, sorted). */
    public static function option1Choices(): array
    {
        $choices = self::get('option1.choices', []);
        return is_array($choices) ? array_values(array_filter($choices, 'is_string')) : [];
    }

    /** "a size", "an option": the word with its article, for the middle of a sentence. */
    public static function withArticle(string $word): string
    {
        return (preg_match('/^[aeiou]/i', $word) === 1 ? 'an ' : 'a ') . $word;
    }

    /** True when the second option is a colour with a swatch (so colour families apply). */
    public static function option2IsColour(): bool
    {
        return self::get('option2.swatch', true) === true;
    }

    /** True when a product really uses the option (its value is not the "none" word). */
    public static function usesOption1(?string $value): bool
    {
        return trim((string) $value) !== '' && strcasecmp(trim((string) $value), self::option1None()) !== 0;
    }

    public static function usesOption2(?string $value): bool
    {
        return trim((string) $value) !== '' && strcasecmp(trim((string) $value), self::option2None()) !== 0;
    }

    /** "M / Navy", "50ml", "": the chosen options of one item, leaving out any the product does not use. */
    public static function optionsText(?string $option1, ?string $option2, string $separator = ' / '): string
    {
        return implode($separator, array_filter([
            self::usesOption1($option1) ? trim((string) $option1) : '',
            self::usesOption2($option2) ? trim((string) $option2) : '',
        ], static fn (string $part): bool => $part !== ''));
    }

    public static function feature(string $name): bool
    {
        return self::get("features.{$name}", false) === true;
    }

    /** Category slugs whose products need the buyer to confirm their age (empty = none). */
    public static function ageRestrictedCategories(): array
    {
        if (!self::feature('ageCheck')) {
            return [];
        }
        $slugs = self::get('ageCheck.categories', []);
        return is_array($slugs) ? array_values(array_filter($slugs, 'is_string')) : [];
    }

    /**
     * True when a product can only be bought by an adult: the shop checks ages, and either the
     * product's category (by slug) is one of the listed ones or the product itself is marked
     * (products.is_age_restricted: a wine hamper among the hampers).
     */
    public static function isAgeRestricted(?string $categorySlug, mixed $marked = false): bool
    {
        if (!self::feature('ageCheck')) {
            return false;
        }
        if (in_array($marked, [true, 1, '1'], true)) {
            return true;
        }
        return self::isAgeRestrictedCategory($categorySlug);
    }

    /** True when the shop checks ages and lists this category (by slug) as for adults only. */
    public static function isAgeRestrictedCategory(?string $categorySlug): bool
    {
        $slug = strtolower(trim((string) $categorySlug));
        return $slug !== '' && in_array($slug, array_map('strtolower', self::ageRestrictedCategories()), true);
    }

    public static function minimumAge(): int
    {
        return max(1, (int) self::get('ageCheck.minAge', 18));
    }

    /** A colour from the theme ("accent", "surface"...), for emails. */
    public static function colour(string $name, string $default): string
    {
        $value = self::get("theme.{$name}");
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1 ? $value : $default;
    }

    /** File-name friendly name: "SHOP.CO" becomes "shopco". */
    public static function fileName(): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', self::name()));
        return $slug !== '' ? $slug : 'store';
    }
}
