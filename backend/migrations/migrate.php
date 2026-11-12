<?php

declare(strict_types=1);

/**
 * Database Migration Runner for Shop.co
 * Executes versioned SQL migrations in sequential order, exactly once each.
 *
 * Applied migrations are recorded in `schema_migrations`. On a database created before the
 * ledger existed, migrations 001-008 (all written as CREATE TABLE IF NOT EXISTS) are
 * recorded as already applied the first time this runs, so only newer files execute.
 *
 * Usage: php backend/migrations/migrate.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/src/autoload.php';

use App\Config\Env;

// Load environment configuration
Env::load(BASE_PATH . '/.env');

$host = Env::get('DB_HOST', '127.0.0.1');
$port = Env::int('DB_PORT', 3306);
$database = Env::get('DB_DATABASE', 'shopco_dev');
$username = Env::get('DB_USERNAME', 'root');
$password = Env::get('DB_PASSWORD', '');

// Migrations that predate the ledger and are safe to treat as applied on existing databases.
const LEGACY_BASELINE_MAX = 8;

echo "========================================================\n";
echo " Shop.co E-Commerce Database Migration Runner\n";
echo " Target: {$username}@{$host}:{$port}/{$database}\n";
echo "========================================================\n\n";

mysqli_report(MYSQLI_REPORT_OFF);

// 1. Connect without selecting database to ensure database exists
$rootConn = @new mysqli($host, $username, $password, '', $port);
if ($rootConn->connect_errno) {
    fwrite(STDERR, "[FATAL] Unable to connect to MySQL: " . $rootConn->connect_error . "\n");
    fwrite(STDERR, "Please verify that MySQL is started in XAMPP on port {$port}.\n");
    exit(1);
}

// 2. Create database if it does not exist
$createDbSql = "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
if (!$rootConn->query($createDbSql)) {
    fwrite(STDERR, "[FATAL] Failed to create database '{$database}': " . $rootConn->error . "\n");
    $rootConn->close();
    exit(1);
}
echo "[OK] Database '{$database}' verified/created (utf8mb4_unicode_ci).\n";
$rootConn->close();

// 3. Connect to the target database
$db = new mysqli($host, $username, $password, $database, $port);
if ($db->connect_errno) {
    fwrite(STDERR, "[FATAL] Connection to '{$database}' failed: " . $db->connect_error . "\n");
    exit(1);
}
$db->set_charset('utf8mb4');

// 4. Ensure the migration ledger exists
$db->query(
    "CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `filename` VARCHAR(191) NOT NULL PRIMARY KEY,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$applied = [];
$res = $db->query("SELECT `filename` FROM `schema_migrations`");
while ($res && ($row = $res->fetch_assoc())) {
    $applied[$row['filename']] = true;
}

$migrationFiles = glob(BASE_PATH . '/migrations/[0-9]*.sql') ?: [];
sort($migrationFiles);

if (empty($migrationFiles)) {
    echo "[INFO] No migration files found in " . BASE_PATH . "/migrations/\n";
    $db->close();
    exit(0);
}

// 5. Baseline an existing pre-ledger database
$usersTable = $db->query("SHOW TABLES LIKE 'users'");
if (empty($applied) && $usersTable && $usersTable->num_rows > 0) {
    echo "[INFO] Existing database without a migration ledger detected; recording legacy migrations as applied.\n";
    $stmt = $db->prepare("INSERT IGNORE INTO `schema_migrations` (`filename`) VALUES (?)");
    foreach ($migrationFiles as $file) {
        $filename = basename($file);
        if ((int) $filename <= LEGACY_BASELINE_MAX) {
            // Re-run the (idempotent) legacy file once so any table it adds exists, then record it.
            if (!runSqlFile($db, $file)) {
                exit(1);
            }
            $stmt->bind_param('s', $filename);
            $stmt->execute();
            $applied[$filename] = true;
            echo "  [BASELINED] {$filename}\n";
        }
    }
    $stmt->close();
}

// 6. Apply pending migrations in order
$successCount = 0;
foreach ($migrationFiles as $file) {
    $filename = basename($file);
    if (isset($applied[$filename])) {
        continue;
    }

    echo "Running migration: {$filename}... ";
    if (!runSqlFile($db, $file)) {
        exit(1);
    }

    $stmt = $db->prepare("INSERT INTO `schema_migrations` (`filename`) VALUES (?)");
    $stmt->bind_param('s', $filename);
    $stmt->execute();
    $stmt->close();

    echo "[OK]\n";
    $successCount++;
}

echo $successCount > 0
    ? "\n[SUCCESS] Applied {$successCount} new migration(s) to '{$database}'.\n"
    : "\n[OK] Database is up to date; no pending migrations.\n";

$res = $db->query("SHOW TABLES");
$tableCount = $res ? $res->num_rows : 0;
echo "[INFO] Total active tables in '{$database}': {$tableCount}\n";

$db->close();
exit(0);

/**
 * Execute every statement in a SQL file, stopping on the first error.
 */
function runSqlFile(mysqli $db, string $file): bool
{
    $filename = basename($file);
    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        return true;
    }

    if (!$db->multi_query($sql)) {
        fwrite(STDERR, "\n[FAILED] {$filename}: " . $db->error . "\n");
        return false;
    }

    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
        if ($db->errno) {
            fwrite(STDERR, "\n[FAILED] {$filename}: " . $db->error . "\n");
            return false;
        }
    } while ($db->more_results() && $db->next_result());

    if ($db->errno) {
        fwrite(STDERR, "\n[FAILED] {$filename}: " . $db->error . "\n");
        return false;
    }

    return true;
}
