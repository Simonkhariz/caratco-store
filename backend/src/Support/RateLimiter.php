<?php

declare(strict_types=1);

namespace App\Support;

use App\Database\Database;

/**
 * Fixed-window rate limiter backed by the `rate_limits` MySQL table.
 * Counter updates are a single atomic upsert, so concurrent requests cannot lose hits.
 */
final class RateLimiter
{
    /**
     * Count a hit and reject the request with 429 once the limit is exceeded.
     */
    public static function attempt(string $bucket, string $key, int $maxHits, int $windowSeconds, string $message = 'Too many requests. Please wait a moment and try again.'): void
    {
        $state = self::increment($bucket, $key, $windowSeconds);
        if ($state['hits'] > $maxHits) {
            self::reject($message, $state['retry_after']);
        }
    }

    /**
     * Reject with 429 if the limit is already reached, without counting this request.
     */
    public static function guard(string $bucket, string $key, int $maxHits, int $windowSeconds, string $message = 'Too many attempts. Please wait before trying again.'): void
    {
        $now = time();
        $row = Database::getInstance()->fetchOne(
            "SELECT `hits`, `window_start` FROM `rate_limits` WHERE `id` = ? LIMIT 1",
            's',
            [self::id($bucket, $key)]
        );
        if ($row === null || (int) $row['window_start'] <= $now - $windowSeconds) {
            return;
        }
        if ((int) $row['hits'] >= $maxHits) {
            self::reject($message, max(1, (int) $row['window_start'] + $windowSeconds - $now));
        }
    }

    /**
     * Count a hit without rejecting (e.g. record a failed login).
     */
    public static function hit(string $bucket, string $key, int $windowSeconds): void
    {
        self::increment($bucket, $key, $windowSeconds);
    }

    public static function clear(string $bucket, string $key): void
    {
        Database::getInstance()->execute("DELETE FROM `rate_limits` WHERE `id` = ?", 's', [self::id($bucket, $key)]);
    }

    /**
     * @return array{hits: int, retry_after: int}
     */
    private static function increment(string $bucket, string $key, int $windowSeconds): array
    {
        $db = Database::getInstance();
        $id = self::id($bucket, $key);
        $now = time();
        $cutoff = $now - $windowSeconds;

        // MySQL evaluates ON DUPLICATE KEY assignments left to right; `hits` is computed
        // from the old window_start before window_start itself is reset.
        $db->execute(
            "INSERT INTO `rate_limits` (`id`, `hits`, `window_start`) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE
                `hits` = IF(`window_start` <= ?, 1, `hits` + 1),
                `window_start` = IF(`window_start` <= ?, VALUES(`window_start`), `window_start`)",
            'siii',
            [$id, $now, $cutoff, $cutoff]
        );

        $row = $db->fetchOne("SELECT `hits`, `window_start` FROM `rate_limits` WHERE `id` = ? LIMIT 1", 's', [$id]);

        return [
            'hits' => (int) ($row['hits'] ?? 1),
            'retry_after' => max(1, (int) ($row['window_start'] ?? $now) + $windowSeconds - $now),
        ];
    }

    private static function id(string $bucket, string $key): string
    {
        // An IPv6 visitor controls a whole /64 (2^64 addresses): count the network, not the
        // address, so changing the last half of the address cannot reset a limit.
        $parts = array_map(static fn (string $part): string => self::networkOf(trim($part)), explode('|', $key));
        return hash('sha256', $bucket . '|' . strtolower(implode('|', $parts)));
    }

    /** An IPv6 address becomes its /64 network; anything else is returned as it is. */
    public static function networkOf(string $value): string
    {
        if ($value === '' || !str_contains($value, ':') || filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $value;
        }
        $packed = @inet_pton($value);
        if ($packed === false || strlen($packed) !== 16) {
            return $value;
        }
        // An IPv4-mapped address (::ffff:1.2.3.4) is one IPv4 visitor: keep it whole.
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return $value;
        }
        return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    private static function reject(string $message, int $retryAfter): void
    {
        if (!headers_sent()) {
            header('Retry-After: ' . $retryAfter);
        }
        Response::error($message, 429, 'TOO_MANY_REQUESTS', ['retry_after_seconds' => $retryAfter]);
    }
}
