<?php

declare(strict_types=1);

namespace App\Support;

use App\Database\Database;

/**
 * Browsers that have signed in to an account before ("known devices").
 *
 * After a successful sign-in the browser keeps a random device key in an HTTP-only cookie; only
 * its SHA-256 is stored (login_devices, migration 033). The per-account limit on wrong passwords
 * stops guessing spread over many connections, but on its own it would also let anyone lock the
 * owner out by flooding wrong passwords. A known device skips that one limit (the per-connection
 * limits still apply), so the owner can always sign in from their own browser.
 */
final class LoginDevice
{
    private const TTL_DAYS = 180;
    private const MAX_PER_USER = 10;

    public static function cookieName(): string
    {
        return SessionCookie::name(SessionCookie::SCOPE_ADMIN) . '_device';
    }

    /** Whether this browser has signed in to this account before. */
    public static function isKnown(int $userId): bool
    {
        $hash = self::presentedHash();
        if ($hash === null) {
            return false;
        }
        try {
            $row = Database::getInstance()->fetchOne(
                "SELECT `id` FROM `login_devices` WHERE `user_id` = ? AND `token_hash` = ? AND `created_at` > DATE_SUB(NOW(), INTERVAL " . self::TTL_DAYS . " DAY) LIMIT 1",
                'is',
                [$userId, $hash]
            );
            return $row !== null;
        } catch (\Throwable $e) {
            // Before migration 033 the table does not exist: behave as before (no known devices).
            return false;
        }
    }

    /** Remembers this browser for the account after a successful sign-in. */
    public static function remember(int $userId): void
    {
        try {
            $db = Database::getInstance();
            $hash = self::presentedHash();
            if ($hash !== null) {
                $updated = $db->executeUpdate(
                    "UPDATE `login_devices` SET `last_used_at` = NOW() WHERE `user_id` = ? AND `token_hash` = ?",
                    'is',
                    [$userId, $hash]
                );
                if ($updated > 0) {
                    return;
                }
            }
            $token = bin2hex(random_bytes(32));
            $db->execute(
                "INSERT INTO `login_devices` (`user_id`, `token_hash`, `last_used_at`) VALUES (?, ?, NOW())",
                'is',
                [$userId, hash('sha256', $token)]
            );
            // Keep the newest few per account.
            $db->execute(
                "DELETE FROM `login_devices` WHERE `user_id` = ? AND `id` NOT IN (
                    SELECT `id` FROM (SELECT `id` FROM `login_devices` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT " . self::MAX_PER_USER . ") AS keep_rows)",
                'ii',
                [$userId, $userId]
            );
            if (!headers_sent()) {
                setcookie(self::cookieName(), $token, [
                    'expires' => time() + self::TTL_DAYS * 86400,
                    'path' => '/',
                    'domain' => '',
                    'secure' => SessionCookie::isSecure(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[LoginDevice] ' . $e->getMessage());
        }
    }

    /** Forgets every browser of an account (after its email or password changes). */
    public static function forgetAll(int $userId): void
    {
        try {
            Database::getInstance()->execute("DELETE FROM `login_devices` WHERE `user_id` = ?", 'i', [$userId]);
        } catch (\Throwable $e) {
            error_log('[LoginDevice] ' . $e->getMessage());
        }
    }

    private static function presentedHash(): ?string
    {
        $value = $_COOKIE[self::cookieName()] ?? '';
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            return null;
        }
        return hash('sha256', $value);
    }
}
