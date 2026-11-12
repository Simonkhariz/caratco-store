<?php

declare(strict_types=1);

/**
 * Deliberate Local-Only Admin Setup Reset Procedure
 * Wipes administrator accounts and unlocks initial setup for fresh testing.
 *
 * MUST ONLY BE EXECUTED VIA CLI.
 * Usage:
 *   php backend/migrations/reset_admin_setup.php [--confirm]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden. Reset script can only be run directly from the command line.\n";
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/src/autoload.php';

use App\Config\Env;
use App\Database\Database;

Env::load(BASE_PATH . '/.env');

echo "========================================================\n";
echo " Shop.co — Reset Admin Onboarding Lock (Local Dev Only)\n";
echo "========================================================\n\n";

$options = getopt('', ['confirm::']);
$isConfirmed = isset($options['confirm']);

if (!$isConfirmed) {
    echo "This script will delete all administrator users and reset 'is_setup_completed' to '0'.\n";
    echo "Type 'RESET' to proceed: ";
    $line = trim(fgets(STDIN) ?: '');
    if ($line !== 'RESET') {
        echo "Aborted. No changes were made.\n";
        exit(0);
    }
}

$db = Database::getInstance();

$db->transaction(function (Database $db) {
    // 1. Delete admin and super_admin users
    $db->execute("DELETE FROM `users` WHERE `role` IN ('admin', 'super_admin')");

    // 2. Reset setup lock flags
    $db->execute("UPDATE `store_settings` SET `value` = '0' WHERE `key_name` = 'is_setup_completed'");
    $db->execute("DELETE FROM `store_settings` WHERE `key_name` = 'admin_onboarded_email'");

    // 3. Clear rate limiting cache if present
    $cacheFile = BASE_PATH . '/storage/framework/rate_limits.json';
    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }
});

echo "\n[SUCCESS] Administrator setup lock has been unlocked successfully!\n";
echo "  - Admin users cleared.\n";
echo "  - 'is_setup_completed' set to '0'.\n";
echo "  - Setup endpoint 'POST /api/v1/setup' is now open for onboarding.\n";
exit(0);
