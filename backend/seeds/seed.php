<?php

declare(strict_types=1);

/**
 * Database Seed Runner: fills a new shop with demo data.
 *
 *   php backend/seeds/seed.php
 *       Loads this shop's own demo catalogue, backend/seeds/demo/catalog.json (settings, delivery
 *       zones, a promotion, categories, banners, products, pictures, reviews), when that file
 *       exists. The shape is shown in backend/seeds/demo/catalog.example.json. Without that file
 *       it loads the clothing catalogue the tests use (backend/tests/fixtures/seeds).
 *
 *   php backend/seeds/seed.php <folder>
 *       Runs every NNN_*.sql file in that folder, in order. The test runner passes
 *       backend/tests/fixtures/seeds, so every shop copy is tested against the same data.
 *
 *   php backend/seeds/seed.php <file.json>
 *       Loads that catalogue file (its pictures are looked for in a "pictures" folder beside it).
 *
 * The folder or file can also come from the env value SEED_PATH. A catalogue file never deletes
 * or replaces anything: when the database already has products it stops and says so.
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/src/autoload.php';

use App\Config\Env;
use App\Database\Database;
use App\Services\CatalogSeeder;
use App\Support\Cache;
use App\Support\ProductRatings;

Env::load(BASE_PATH . '/.env');

$host = Env::get('DB_HOST', '127.0.0.1');
$port = Env::int('DB_PORT', 3306);
$database = Env::get('DB_DATABASE', 'shopco_dev');
$username = Env::get('DB_USERNAME', 'root');
$password = Env::get('DB_PASSWORD', '');

// Where the data comes from: the argument, else SEED_PATH, else the shop's own catalogue file,
// else the test fixtures (so a copy without a catalogue file still seeds).
$source = trim((string) ($argv[1] ?? '')) ?: trim((string) Env::get('SEED_PATH', ''));
if ($source === '') {
    $source = is_file(__DIR__ . '/demo/catalog.json') ? __DIR__ . '/demo/catalog.json' : BASE_PATH . '/tests/fixtures/seeds';
    // A copy built for a host has neither (the tests are left out): say so plainly.
    if (!is_file($source) && !glob($source . '/[0-9]*.sql')) {
        fwrite(STDERR, "There is no demo catalogue to load: backend/seeds/demo/catalog.json does not exist.\n"
            . "Add one (backend/seeds/demo/catalog.example.json shows the shape), or give a catalogue file: php backend/seeds/seed.php <file.json>\n");
        exit(1);
    }
}
if (is_dir($source) && !glob(rtrim($source, '/\\') . '/[0-9]*.sql') && is_file(rtrim($source, '/\\') . '/catalog.json')) {
    $source = rtrim($source, '/\\') . '/catalog.json';
}

echo "========================================================\n";
echo " Seed Runner\n";
echo " Target: {$username}@{$host}:{$port}/{$database}\n";
echo " Source: {$source}\n";
echo "========================================================\n\n";

if (!is_dir($source) && !is_file($source)) {
    fwrite(STDERR, "[FATAL] '{$source}' is not a folder of .sql files or a catalogue .json file.\n");
    exit(1);
}

// A catalogue file (JSON).
if (is_file($source)) {
    try {
        $result = (new CatalogSeeder(Database::getInstance()))->seed($source);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, "[STOPPED] " . $e->getMessage() . "\n");
        exit(1);
    } catch (Throwable $e) {
        fwrite(STDERR, "[FAILED] " . $e->getMessage() . "\nNothing was written to the database.\n");
        exit(1);
    }
    // The shop may already be running: let it show the new catalogue straight away.
    try {
        Cache::bumpCatalog();
        Cache::bumpStorefront();
    } catch (Throwable $e) {
        // The cache clears by itself within seconds.
    }

    echo "[SUCCESS] Seeding complete!\n";
    foreach ($result['counts'] as $what => $count) {
        printf("  %-11s %d\n", ucfirst($what) . ':', $count);
    }
    foreach ($result['notes'] as $note) {
        echo "  Note: {$note}\n";
    }
    exit(0);
}

// A folder of SQL files.
$db = new mysqli($host, $username, $password, $database, $port);
if ($db->connect_errno) {
    fwrite(STDERR, "[FATAL] Connection to '{$database}' failed: " . $db->connect_error . "\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$seedFiles = glob(rtrim($source, '/\\') . '/[0-9]*.sql') ?: [];
sort($seedFiles);

if (empty($seedFiles)) {
    echo "[INFO] No seed files found.\n";
    $db->close();
    exit(0);
}

foreach ($seedFiles as $file) {
    $filename = basename($file);
    echo "Executing seed: {$filename}... ";

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        echo "[SKIPPED - EMPTY]\n";
        continue;
    }

    if ($db->multi_query($sql)) {
        do {
            if ($result = $db->store_result()) {
                $result->free();
            }
        } while ($db->more_results() && $db->next_result());

        if ($db->errno) {
            echo "[FAILED]\n";
            fwrite(STDERR, "  Error in {$filename}: " . $db->error . "\n");
            $db->close();
            exit(1);
        }

        echo "[OK]\n";
    } else {
        echo "[FAILED]\n";
        fwrite(STDERR, "  Error in {$filename}: " . $db->error . "\n");
        $db->close();
        exit(1);
    }
}

// Whatever a seed file says, ratings are recomputed: the starting rating plus approved reviews,
// and the review count only from approved reviews (App\Support\ProductRatings).
ProductRatings::refresh(Database::getInstance());

// Summary queries
$catCount = $db->query("SELECT COUNT(*) AS c FROM categories")->fetch_assoc()['c'] ?? 0;
$prodCount = $db->query("SELECT COUNT(*) AS c FROM products")->fetch_assoc()['c'] ?? 0;
$varCount = $db->query("SELECT COUNT(*) AS c FROM product_variants")->fetch_assoc()['c'] ?? 0;
$zoneCount = $db->query("SELECT COUNT(*) AS c FROM shipping_zones")->fetch_assoc()['c'] ?? 0;

echo "\n[SUCCESS] Seeding complete!\n";
echo "  Categories:     {$catCount}\n";
echo "  Products:       {$prodCount} (physical)\n";
echo "  Variants:       {$varCount}\n";
echo "  Shipping Zones: {$zoneCount}\n";

$db->close();
exit(0);
