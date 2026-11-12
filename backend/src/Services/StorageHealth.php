<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Support\SlowRequestLog;

/**
 * Is the database getting too big, the site slow, or is old data waiting for the admin?
 * Used by admin → Data & storage, the admin alert bell and the daily check
 * (scripts/check_database.php, which emails the admins). Nothing here removes anything.
 *
 * Checks:
 *   size      database size against the admin's limit (storage_alert_mb): warning from 80 %
 *   slow      API requests slower than SLOW_REQUEST_MS in the last 24 hours
 *   due       customer or business records past the admin's keep periods, waiting for review
 *   backlog   many expired sessions / counters (technical rows) waiting for review
 *   queries   time taken by a few typical storefront and admin queries (full report only)
 */
final class StorageHealth
{
    public const ALERT_MB_OPTIONS = [250, 500, 1000, 2000, 5000];
    public const DEFAULT_ALERT_MB = 500;

    private const SLOW_WARNING = 20;
    private const SLOW_CRITICAL = 200;
    private const QUERY_WARNING_MS = 500;
    private const QUERY_CRITICAL_MS = 2000;
    private const BACKLOG_WARNING = 10000;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function alertMb(): int
    {
        $row = $this->db->fetchOne("SELECT `value` FROM `store_settings` WHERE `key_name` = 'storage_alert_mb'");
        $mb = (int) ($row['value'] ?? 0);
        return in_array($mb, self::ALERT_MB_OPTIONS, true) ? $mb : self::DEFAULT_ALERT_MB;
    }

    /**
     * @param string $asOf the moment the "due" records are counted for (the admin's review time)
     * @param bool $full also list the biggest tables and slowest routes and time typical
     *                   queries (the alert bell and daily check use the quick version)
     * @return array<string, mixed>
     */
    public function report(string $asOf, bool $full = true): array
    {
        $issues = [];

        // --- Size -------------------------------------------------------------------------
        $tables = $this->db->fetchAll(
            "SELECT `TABLE_NAME` AS `name`, COALESCE(`TABLE_ROWS`, 0) AS `rows`,
                    COALESCE(`DATA_LENGTH`, 0) + COALESCE(`INDEX_LENGTH`, 0) AS `bytes`, COALESCE(`DATA_FREE`, 0) AS `free`
             FROM information_schema.`TABLES`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_TYPE` = 'BASE TABLE'
             ORDER BY `bytes` DESC"
        );
        $totalBytes = array_sum(array_map(static fn (array $t): int => (int) $t['bytes'], $tables));
        $alertMb = $this->alertMb();
        $limitBytes = $alertMb * 1024 * 1024;
        $usedPercent = $limitBytes > 0 ? (int) round($totalBytes / $limitBytes * 100) : 0;
        $sizeMb = round($totalBytes / 1048576, 1);
        if ($usedPercent >= 100) {
            $issues[] = $this->issue('critical', 'size', 'The database is over your size limit', "It is {$sizeMb} MB; your limit is {$alertMb} MB. Review the old data below and clean it up, choose shorter keep periods, or raise the limit if your hosting plan allows it.");
        } elseif ($usedPercent >= 80) {
            $issues[] = $this->issue('warning', 'size', 'The database is getting large', "It is {$sizeMb} MB, {$usedPercent}% of your {$alertMb} MB limit. Review the old data below and clean it up.");
        }

        // --- Slow requests (last 24 hours) ------------------------------------------------
        $slow = $this->db->fetchOne(
            "SELECT COALESCE(SUM(`hits`), 0) AS hits, COALESCE(MAX(`max_ms`), 0) AS max_ms
             FROM `api_slow_requests` WHERE `hour_start` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $slowRoutes = $this->db->fetchAll(
            "SELECT `method`, `route`, SUM(`hits`) AS hits, MAX(`max_ms`) AS max_ms, ROUND(SUM(`total_ms`) / SUM(`hits`)) AS avg_ms
             FROM `api_slow_requests` WHERE `hour_start` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY `method`, `route` ORDER BY hits DESC, max_ms DESC LIMIT 5"
        );
        $slowHits = (int) ($slow['hits'] ?? 0);
        $thresholdSec = rtrim(rtrim(number_format(SlowRequestLog::thresholdMs() / 1000, 1), '0'), '.');
        if ($slowHits >= self::SLOW_WARNING) {
            $top = $slowRoutes[0] ?? null;
            $issues[] = $this->issue(
                $slowHits >= self::SLOW_CRITICAL ? 'critical' : 'warning',
                'slow',
                'The site has been slow',
                "{$slowHits} requests took longer than {$thresholdSec} s in the last 24 hours"
                . ($top ? " (most often {$top['method']} {$top['route']})" : '') . '.'
            );
        }

        // --- Old data waiting for the admin -------------------------------------------------
        $cleanup = new DatabaseCleanup($this->db);
        $snapshot = $cleanup->snapshot($asOf);
        $due = $snapshot['counts'];
        if (!$snapshot['pictures_checked']) {
            $issues[] = $this->issue('warning', 'pictures_not_checked', 'Pictures could not be checked', DatabaseCleanup::PICTURES_NOT_CHECKED);
        }
        $records = 0;
        $technical = 0;
        foreach ($due as $rule => $count) {
            if ($rule === 'inactive_customers') {
                continue;
            }
            if (in_array($rule, DatabaseCleanup::HOUSEKEEPING, true)) {
                $technical += $count;
            } else {
                $records += $count;
            }
        }
        if ($records > 0) {
            $people = $due['inactive_customers'] ?? 0;
            $issues[] = $this->issue(
                'warning',
                'due',
                number_format($records) . ' ' . ($records === 1 ? 'record is' : 'records are') . ' past your keep periods',
                ($people > 0 ? 'Including the personal details of ' . number_format($people) . ' inactive ' . ($people === 1 ? 'customer' : 'customers') . '. ' : '')
                . 'Nothing is removed until you review the list and confirm the clean-up.'
            );
        }
        if ($technical >= self::BACKLOG_WARNING) {
            $issues[] = $this->issue('warning', 'backlog', 'Expired sessions and counters are piling up', number_format($technical) . ' technical rows (no customer data) can be cleaned up.');
        }

        $lastRun = $cleanup->lastRun();
        $report = [
            'as_of' => $asOf,
            'database' => [
                'size_bytes' => $totalBytes,
                'alert_mb' => $alertMb,
                'used_percent' => $usedPercent,
                'tables' => array_map(static fn (array $t): array => [
                    'name' => (string) $t['name'],
                    'rows' => (int) $t['rows'],
                    'bytes' => (int) $t['bytes'],
                    'free_bytes' => (int) $t['free'],
                ], $full ? array_slice($tables, 0, 10) : []),
            ],
            'slow_requests' => [
                'threshold_ms' => SlowRequestLog::thresholdMs(),
                'last_24h' => $slowHits,
                'slowest_ms' => (int) ($slow['max_ms'] ?? 0),
                'routes' => $full ? array_map(static fn (array $r): array => [
                    'method' => (string) $r['method'],
                    'route' => (string) $r['route'],
                    'hits' => (int) $r['hits'],
                    'avg_ms' => (int) $r['avg_ms'],
                    'max_ms' => (int) $r['max_ms'],
                ], $slowRoutes) : [],
            ],
            'cleanup' => [
                'due' => $due,
                'pictures_checked' => $snapshot['pictures_checked'],
                'note' => $snapshot['pictures_checked'] ? null : DatabaseCleanup::PICTURES_NOT_CHECKED,
                'last_run' => $lastRun,
                'days_since' => $lastRun ? (int) floor((time() - strtotime((string) $lastRun['at'])) / 86400) : null,
            ],
        ];

        if ($full) {
            $started = microtime(true);
            $this->db->fetchAll("SELECT `id` FROM `products` WHERE `status` = 'published' ORDER BY `created_at` DESC LIMIT 24");
            $this->db->fetchOne("SELECT COUNT(*) AS c FROM `orders` WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $this->db->fetchAll("SELECT `id` FROM `orders` ORDER BY `created_at` DESC LIMIT 20");
            $queryMs = (int) round((microtime(true) - $started) * 1000);
            $report['queries'] = ['typical_ms' => $queryMs];
            if ($queryMs >= self::QUERY_WARNING_MS) {
                $issues[] = $this->issue(
                    $queryMs >= self::QUERY_CRITICAL_MS ? 'critical' : 'warning',
                    'queries',
                    'Database queries are slow',
                    "Typical shop queries took {$queryMs} ms (normally under 50 ms). Cleaning up old data helps; if it stays slow, the server may need more memory."
                );
            }
        }

        $level = 'ok';
        foreach ($issues as $i) {
            if ($i['level'] === 'critical') {
                $level = 'critical';
                break;
            }
            $level = 'warning';
        }

        return ['level' => $level, 'issues' => $issues, 'checked_at' => date('Y-m-d H:i:s')] + $report;
    }

    /** @return array{level: string, code: string, title: string, detail: string} */
    private function issue(string $level, string $code, string $title, string $detail): array
    {
        return compact('level', 'code', 'title', 'detail');
    }
}
