<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Env;
use App\Database\Database;
use Throwable;

/**
 * Counts API requests slower than SLOW_REQUEST_MS (default 1500; 0 turns it off), one row per
 * hour and route, for the "site is getting slow" alert in admin → Data & storage. Fast
 * requests write nothing. Called from index.php once the response has been sent.
 */
final class SlowRequestLog
{
    public static function thresholdMs(): int
    {
        return DevSwitches::enabled(DevSwitches::SLOW_REQUEST_LOG) ? max(0, Env::int('SLOW_REQUEST_MS', 1500)) : 0;
    }

    public static function record(string $method, ?string $route, float $startedAt): void
    {
        $threshold = self::thresholdMs();
        $ms = (int) round((microtime(true) - $startedAt) * 1000);
        // Unknown paths are not recorded, so the table only ever holds real routes.
        if ($threshold === 0 || $ms < $threshold || $route === null) {
            return;
        }

        try {
            Database::getInstance()->execute(
                "INSERT INTO `api_slow_requests` (`hour_start`, `method`, `route`, `hits`, `total_ms`, `max_ms`)
                 VALUES (DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), ?, ?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE `hits` = `hits` + 1, `total_ms` = `total_ms` + VALUES(`total_ms`),
                                         `max_ms` = GREATEST(`max_ms`, VALUES(`max_ms`))",
                'ssii',
                [substr($method, 0, 8), mb_substr($route, 0, 191), $ms, $ms]
            );
        } catch (Throwable $e) {
            error_log('[SlowRequestLog] ' . $e->getMessage());
        }
    }
}
