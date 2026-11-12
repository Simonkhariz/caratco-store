<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Env;

/**
 * Session cookie policy.
 * Admin and customer sessions live in separate HTTP-only cookies so being signed in
 * as both in one browser never mixes identities. Tokens are never exposed to JavaScript.
 */
final class SessionCookie
{
    public const SCOPE_CUSTOMER = 'customer';
    public const SCOPE_ADMIN = 'admin';

    public static function name(string $scope): string
    {
        if ($scope === self::SCOPE_ADMIN) {
            // A browser keeps one set of cookies per host name whatever the port, so two shops on
            // one PC would sign each other's admins out unless each has its own name
            // (ADMIN_COOKIE_NAME). Anything that is not a plain cookie name falls back to the usual one.
            $name = trim((string) Env::get('ADMIN_COOKIE_NAME', ''));
            return preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $name) === 1 ? $name : 'shopco_admin';
        }
        return Env::get('SESSION_COOKIE_NAME', 'shopco_session') ?: 'shopco_session';
    }

    public static function scopeForRole(string $role): string
    {
        return in_array($role, ['admin', 'super_admin'], true) ? self::SCOPE_ADMIN : self::SCOPE_CUSTOMER;
    }

    /**
     * Normalize a user-supplied scope value (query string / body).
     */
    public static function normalizeScope(mixed $scope): string
    {
        return $scope === self::SCOPE_ADMIN ? self::SCOPE_ADMIN : self::SCOPE_CUSTOMER;
    }

    /**
     * Absolute session lifetime in seconds.
     */
    public static function ttlSeconds(string $scope): int
    {
        if ($scope === self::SCOPE_ADMIN) {
            return max(900, Env::int('ADMIN_SESSION_LIFETIME_SECONDS', 43200)); // 12 hours
        }
        return max(3600, Env::int('SESSION_LIFETIME_SECONDS', 2592000)); // 30 days
    }

    /**
     * Inactivity timeout in seconds, or null when sessions do not idle out.
     */
    public static function idleTimeoutSeconds(string $scope): ?int
    {
        if ($scope === self::SCOPE_ADMIN) {
            return max(300, Env::int('ADMIN_SESSION_IDLE_SECONDS', 7200)); // 2 hours
        }
        return null;
    }

    public static function read(string $scope): ?string
    {
        $value = $_COOKIE[self::name($scope)] ?? '';
        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? $value : null;
    }

    public static function set(string $scope, string $token, int $expiresTimestamp): void
    {
        setcookie(self::name($scope), $token, self::options($expiresTimestamp));
    }

    public static function clear(string $scope): void
    {
        setcookie(self::name($scope), '', self::options(time() - 3600));
    }

    public static function isSecure(): bool
    {
        if (Env::isProduction()) {
            return true;
        }
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private static function options(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => self::isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
