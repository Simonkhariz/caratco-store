<?php

declare(strict_types=1);

/**
 * Daily database check. It never removes anything.
 *
 * Checks the database size against the admin's limit, slow requests, and records that are past
 * the keep periods set in admin → Data & storage. When something needs attention it emails the
 * admins (at most once a week per problem level); they review the exact records in admin and
 * confirm a clean-up there with their password and an emailed code.
 *
 * Usage:
 *   php backend/scripts/check_database.php
 *
 * Windows Task Scheduler (daily at 03:00):
 *   schtasks /Create /SC DAILY /ST 03:00 /TN "ShopCo Database Check" ^
 *     /TR "C:\xampp\php\php.exe C:\path\to\E-commerce\backend\scripts\check_database.php"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/src/autoload.php';

use App\Config\Env;
use App\Services\DatabaseCleanup;
use App\Services\MailService;
use App\Services\StorageHealth;

Env::load(BASE_PATH . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Africa/Lagos') ?: 'Africa/Lagos');

try {
    $health = (new StorageHealth())->report(date('Y-m-d H:i:s'), false);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Database check failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo '[' . date('Y-m-d H:i:s') . '] Database health: ' . $health['level'] . "\n";
foreach ($health['issues'] as $issue) {
    echo "  {$issue['title']}: {$issue['detail']}\n";
}
echo "Past the keep periods (waiting for an admin to review; nothing removed):\n";
foreach ($health['cleanup']['due'] as $rule => $count) {
    printf("  %-48s %d\n", DatabaseCleanup::LABELS[$rule] ?? $rule, $count);
}

if ($health['level'] !== 'ok') {
    $queued = (new MailService())->sendStorageAlertToAdmins($health);
    if ($queued > 0) {
        echo "Alert queued for {$queued} admin(s).\n";
    }
}
