<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Environment configuration loader and accessor.
 * Reads key-value pairs from .env without external dependencies.
 */
class Env
{
    /** @var array<string, string> */
    private static array $vars = [];

    private static bool $loaded = false;

    /**
     * Load environment variables from a file.
     *
     * When the process environment sets SHOPCO_ENV_FILE (the test runner does), that file is
     * loaded instead, so tests run against their own database without touching .env. If that
     * file cannot be read the process stops: falling back to .env would point a test run at
     * the real database.
     */
    public static function load(string $filePath): void
    {
        if (self::$loaded && !empty(self::$vars)) {
            return;
        }

        $override = getenv('SHOPCO_ENV_FILE');
        if (is_string($override) && $override !== '') {
            if (!is_readable($override)) {
                throw new \RuntimeException(
                    "SHOPCO_ENV_FILE is set to '{$override}', which cannot be read from " . getcwd() . '. Use an absolute path.'
                );
            }
            $filePath = $override;
        }

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Ignore comments and empty lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Must contain an equals sign
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Strip enclosing quotes if present
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            } elseif (preg_match('/\s+#/', $value, $m, PREG_OFFSET_CAPTURE)) {
                // Unquoted values may carry an inline " # comment"
                $value = rtrim(substr($value, 0, $m[0][1]));
            }

            self::$vars[$name] = $value;
            $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }

        self::$loaded = true;
    }

    /**
     * Get a string environment variable with fallback.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }

        $envValue = getenv($key);
        if ($envValue !== false) {
            return $envValue;
        }

        return $default;
    }

    /**
     * Get a boolean environment variable.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $val = self::get($key);
        if ($val === null) {
            return $default;
        }

        $lower = strtolower(trim($val));
        return in_array($lower, ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Get an integer environment variable.
     */
    public static function int(string $key, int $default = 0): int
    {
        $val = self::get($key);
        if ($val === null) {
            return $default;
        }

        return (int) $val;
    }

    /**
     * Whether the API is running in production mode (APP_ENV=production).
     */
    public static function isProduction(): bool
    {
        return strtolower((string) self::get('APP_ENV', 'development')) === 'production';
    }

    /**
     * Public storefront origin used to build links in emails and payment redirects.
     */
    public static function frontendUrl(): string
    {
        return rtrim((string) self::get('FRONTEND_URL', 'http://localhost:3000'), '/');
    }

    /**
     * Return all loaded variables (useful for debugging, without secrets).
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::$vars;
    }
}
