<?php

declare(strict_types=1);

/**
 * The database file to import on a new host (deploy/cpanel/README.md): the shop as a client should
 * see it, without what built up while you tested it.
 *
 *   KEPT      products, variants, pictures, categories, delivery zones, coupons (their use counts
 *             back to 0), banners, approved reviews (without who wrote them, beyond the name shown),
 *             store settings, and your administrator accounts
 *   LEFT OUT  customers and their addresses, carts and wishlists, orders and payments, sessions and
 *             sign-in links, newsletter subscribers, queued emails, logs and counters
 *
 * It only reads this database and writes one new file: nothing here is changed or deleted.
 * Every table is still created on the host, empty where its rows were left out.
 *
 *   php backend/scripts/export_for_host.php                   writes storage/backups/for-host-<date>.sql.gz
 *   php backend/scripts/export_for_host.php --out=<file.sql.gz>
 *
 * For moving a live shop with its real orders, use a normal backup instead (docs/BACKUPS.md).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/src/autoload.php';

use App\Config\Env;
use App\Support\GzipFile;
use App\Support\SqlDump;

Env::load(BASE_PATH . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Africa/Lagos') ?: 'Africa/Lagos');

$fail = static function (string $message): never {
    fwrite(STDERR, "[export] {$message}\n");
    exit(1);
};
if (!function_exists('gzopen')) {
    $fail('PHP\'s zlib extension is not enabled; it is needed to compress the file.');
}

$out = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $out = substr($arg, strlen('--out='));
    } else {
        $fail("Unknown option {$arg}.");
    }
}
if ($out === '') {
    $root = rtrim((string) (Env::get('BACKUP_DIR', '') ?: BASE_PATH . '/storage/backups'), '/\\');
    if (!is_dir($root) && !@mkdir($root, 0750, true) && !is_dir($root)) {
        $fail("Cannot create {$root}.");
    }
    $out = "{$root}/for-host-" . date('Y-m-d_His') . '.sql.gz';
}

// Everything not listed is written as an empty table, so a table added later is left out until
// someone decides it belongs here: new data must never reach a public server by default.
$keepRows = [
    'categories' => '',
    'products' => '',
    'product_variants' => '',
    'product_images' => '',
    'digital_deliverables' => '',
    'shipping_zones' => '',
    'promotions' => '',
    'banners' => '',
    'reviews' => "`status` = 'approved'",
    // Traces of this PC's own activity (last email sweep, last storefront refresh and its address)
    // would only mislead System status on the host.
    'store_settings' => "`key_name` NOT IN ('outbox_last_sweep', 'storefront_refresh_last', 'dev_mode', 'dev_switches')",
    'schema_migrations' => '',
    'users' => "`role` <> 'customer'",
];
// What remains of test activity in the rows that are kept. Replaced as the file is written, so the
// real values are never in it.
$replace = [
    'promotions' => ['used_count' => '0'],
    'reviews' => ['user_id' => null, 'author_email' => null],
];

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli(
        (string) Env::get('DB_HOST', '127.0.0.1'),
        (string) Env::get('DB_USERNAME', 'root'),
        (string) Env::get('DB_PASSWORD', ''),
        (string) Env::get('DB_DATABASE', ''),
        Env::int('DB_PORT', 3306)
    );
    $conn->set_charset('utf8mb4');
    // Fails loudly on a full disk (GzipFile), so a cut-off file is never taken to the host.
    $gz = new GzipFile($out);
    $counts = SqlDump::dump($conn, static function (string $sql) use ($gz): void {
        $gz->write($sql);
    }, $keepRows, $replace);
    $gz->close();

    $admins = [];
    foreach ($conn->query("SELECT `email` FROM `users` WHERE `role` <> 'customer' ORDER BY `id`")->fetch_all() as $row) {
        $admins[] = (string) $row[0];
    }
    $left = [];
    foreach (array_diff(array_keys($counts), array_keys($keepRows)) as $table) {
        $n = (int) $conn->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', (string) $table) . '`')->fetch_row()[0];
        if ($n > 0) {
            $left[(string) $table] = $n;
        }
    }
    $customers = (int) $conn->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'customer'")->fetch_row()[0];
    $conn->close();
} catch (Throwable $e) {
    @unlink($out);
    $fail('The export failed: ' . $e->getMessage());
}

echo "Written: {$out}\n\nKept:\n";
foreach (array_keys($keepRows) as $table) {
    if ($table !== 'schema_migrations' && isset($counts[$table])) {
        printf("  %-22s %d\n", $table === 'users' ? 'administrators' : $table, $counts[$table]);
    }
}
echo "\nLeft out (still here on this PC):\n";
if ($customers > 0) {
    printf("  %-22s %d\n", 'customers', $customers);
}
foreach ($left as $table => $n) {
    printf("  %-22s %d\n", $table, $n);
}
echo "\nThese administrators can sign in on the host with their current passwords:\n";
foreach ($admins as $email) {
    echo "  {$email}\n";
}
echo "If any was a test account or has a weak password, change it in Admin > My account after the first sign-in.\n";
