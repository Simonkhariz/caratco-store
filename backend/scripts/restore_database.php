<?php

declare(strict_types=1);

/**
 * Load a database backup (database.sql.gz from scripts/backup.php, or any plain .sql file)
 * into an EMPTY database. For hosts without shell access to the mysql client, e.g. cPanel
 * (phpMyAdmin also works for small files, but usually stops at 50 MB).
 *
 * It never overwrites data: it refuses a database that already has tables. To recover a live
 * shop, restore into a new empty database, check it, then point DB_DATABASE at it.
 *
 *   php backend/scripts/restore_database.php --backup=<backup folder> --database=<empty database>
 *   php backend/scripts/restore_database.php --file=<dump.sql.gz> --database=<empty database>
 *   add --create to create the database first (not allowed on cPanel: create it in
 *   cPanel → MySQL Databases and give the shop's user all privileges on it)
 *
 * With --backup, the dump's SHA-256 is checked against manifest.json before anything is loaded,
 * and, for backups written by PHP, every table's row count is checked afterwards. Uses DB_HOST,
 * DB_PORT, DB_USERNAME and DB_PASSWORD from backend/.env.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/src/autoload.php';

use App\Config\Env;

Env::load(BASE_PATH . '/.env');

$fail = static function (string $message): never {
    fwrite(STDERR, "[restore] {$message}\n");
    exit(1);
};
$option = static function (string $name) use ($argv): ?string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return null;
};

$target = (string) $option('database');
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $target)) {
    $fail('Give the database to restore into: --database=<name> (letters, digits and _ only).');
}

$manifest = null;
$backupDir = $option('backup');
if ($backupDir !== null) {
    $file = rtrim($backupDir, '/\\') . '/database.sql.gz';
    $manifest = json_decode((string) @file_get_contents(rtrim($backupDir, '/\\') . '/manifest.json'), true);
    if (!is_array($manifest) || !isset($manifest['database_dump']['sha256'])) {
        $fail("No readable manifest.json in {$backupDir}.");
    }
    if (!is_file($file) || hash_file('sha256', $file) !== $manifest['database_dump']['sha256']) {
        $fail('database.sql.gz does not match manifest.json (damaged or changed): not restoring it.');
    }
} else {
    $file = (string) $option('file');
    if ($file === '' || !is_file($file)) {
        $fail('Give the backup: --backup=<backup folder> or --file=<dump.sql.gz>.');
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli(
        (string) Env::get('DB_HOST', '127.0.0.1'),
        (string) Env::get('DB_USERNAME', 'root'),
        (string) Env::get('DB_PASSWORD', ''),
        '',
        Env::int('DB_PORT', 3306)
    );
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    $fail('Cannot connect to MySQL: ' . $e->getMessage());
}

$exists = $conn->query("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . $conn->real_escape_string($target) . "'")->num_rows === 1;
if (!$exists) {
    if (!in_array('--create', $argv, true)) {
        $fail("Database {$target} does not exist. Create it (cPanel → MySQL Databases) or add --create.");
    }
    try {
        $conn->query("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        $fail("Cannot create {$target}: " . $e->getMessage());
    }
}
$conn->select_db($target);
$tables = static fn (): array => array_map(static fn (array $r): string => (string) $r[0], $conn->query("SHOW FULL TABLES WHERE `Table_type` = 'BASE TABLE'")->fetch_all());
if ($tables() !== []) {
    $fail("{$target} already has tables. Restoring never replaces data: use a new, empty database.");
}

// Statements end a line with ";" (mysqldump and App\Support\SqlDump both write them that way).
$in = @gzopen($file, 'rb');
if ($in === false) {
    $fail("Cannot read {$file}.");
}
$started = microtime(true);
$statement = '';
$count = 0;
$line = 0;
try {
    while (($text = gzgets($in)) !== false) {
        $line++;
        if ($statement === '') {
            $trimmed = trim($text);
            if ($trimmed === '' || str_starts_with($trimmed, '-- ') || $trimmed === '--') {
                continue;
            }
            if (stripos($trimmed, 'DELIMITER ') === 0) {
                throw new RuntimeException('this dump contains stored routines or triggers; load it with the mysql client instead');
            }
        }
        $statement .= $text;
        if (str_ends_with(rtrim($text), ';')) {
            $conn->query($statement);
            $statement = '';
            $count++;
        }
    }
    if (trim($statement) !== '') {
        throw new RuntimeException('the file ends in the middle of a statement (incomplete backup?)');
    }
} catch (Throwable $e) {
    gzclose($in);
    // The database was empty before: remove what was loaded, so it is empty again.
    $conn->query('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables() as $table) {
        $conn->query('DROP TABLE `' . str_replace('`', '``', $table) . '`');
    }
    $fail("Stopped at line {$line}: " . mb_substr($e->getMessage(), 0, 300) . " {$target} has been emptied again.");
}
gzclose($in);

$rows = [];
foreach ($tables() as $table) {
    $rows[$table] = (int) $conn->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetch_row()[0];
}
printf("[restore] %s: %d statements, %d tables, %d rows in %.1f s\n", $target, $count, count($rows), array_sum($rows), microtime(true) - $started);

if (is_array($manifest['table_rows'] ?? null)) {
    $expected = array_map('intval', $manifest['table_rows']);
    ksort($expected);
    ksort($rows);
    if ($expected !== $rows) {
        $diff = [];
        foreach (array_unique(array_merge(array_keys($expected), array_keys($rows))) as $table) {
            if (($expected[$table] ?? null) !== ($rows[$table] ?? null)) {
                $diff[] = "{$table}: backup " . ($expected[$table] ?? 'missing') . ', restored ' . ($rows[$table] ?? 'missing');
            }
        }
        $fail('Row counts differ from the backup: ' . implode('; ', $diff));
    }
    echo "[restore] every table has the same number of rows as the backup\n";
}
echo "[restore] next: check it (admin → System status after pointing DB_DATABASE at {$target}, a few orders and products)\n";
