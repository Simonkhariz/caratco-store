<?php

declare(strict_types=1);

/**
 * The clean database file for a new host (scripts/export_for_host.php): it must bring the shop and
 * nothing from testing, load into a new empty database, leave a working shop there, and change
 * nothing where it was made.
 *
 *   php backend/tests/run.php HostExport
 */

require __DIR__ . '/support/TestKit.php';

$t = new TestKit('HostExport');
// The first value of the first row of a query.
$value = static function (string $query) use ($t): mixed {
    $rows = $t->sql($query);
    return array_values($rows[0] ?? [null])[0];
};

// Test activity that must not travel: a customer with an address, an order, a session and a coupon use.
$email = $t->uniq('export-customer') . '@example.test';
$t->sql("INSERT INTO `users` (`uuid`, `full_name`, `email`, `password_hash`, `role`) VALUES (UUID(), 'Test Shopper', '{$email}', 'x', 'customer')");
$shopper = (int) $value("SELECT `id` FROM `users` WHERE `email` = '{$email}'");
$adminEmail = $t->uniq('export-admin') . '@example.test';
$t->sql("INSERT INTO `users` (`uuid`, `full_name`, `email`, `password_hash`, `role`) VALUES (UUID(), 'Test Admin', '{$adminEmail}', 'x', 'admin')");
$t->sql("INSERT INTO `sessions` (`id`, `user_id`, `last_activity`, `expires_at`) VALUES (SHA2('export-test', 256), {$shopper}, UNIX_TIMESTAMP(), DATE_ADD(NOW(), INTERVAL 1 DAY))");
$t->sql("INSERT INTO `newsletter_subscribers` (`email`) VALUES ('{$email}')");
$productId = (int) $value("SELECT `id` FROM `products` ORDER BY `id` LIMIT 1");
$t->sql("INSERT INTO `reviews` (`product_id`, `user_id`, `author_name`, `author_email`, `rating`, `comment`, `status`) VALUES ({$productId}, {$shopper}, 'Happy Shopper', '{$email}', 5, 'Approved review', 'approved')");
$t->sql("INSERT INTO `reviews` (`product_id`, `author_name`, `rating`, `comment`, `status`) VALUES ({$productId}, 'Waiting Shopper', 4, 'Pending review', 'pending')");
$t->sql("INSERT INTO `promotions` (`code`, `description`, `discount_percent`, `max_uses`, `used_count`, `is_active`) VALUES ('EXPORTTEST', 'Used in testing', 10, 100, 37, 1)");
$t->sql("INSERT INTO `store_settings` (`key_name`, `value`) VALUES ('storefront_refresh_last', '{\"url\":\"http://127.0.0.1:3000\"}') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
$t->sql("INSERT INTO `store_settings` (`key_name`, `value`) VALUES ('dev_mode', '1') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");

$tables = [];
foreach ($t->sql('SHOW TABLES') as $row) {
    $tables[] = (string) array_values($row)[0];
}
$before = [];
foreach ($tables as $table) {
    $before[$table] = (int) $value("SELECT COUNT(*) FROM `{$table}`");
}

$dir = sys_get_temp_dir() . '/shopco-host-export-' . getmypid();
@mkdir($dir, 0700, true);
$file = "{$dir}/for-host.sql.gz";
$proc = proc_open([PHP_BINARY, BASE_PATH . '/scripts/export_for_host.php', "--out={$file}"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, getenv());
$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);

$t->section('A file that cannot be written fails, and none is left behind');
// support/failing_gzip.php makes the writer's gzwrite/gzclose fail as on a full disk.
foreach (['short', 'lost', 'close'] as $failure) {
    $brokenFile = "{$dir}/broken-{$failure}.sql.gz";
    $proc = proc_open(
        [PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/support/failing_gzip.php', BASE_PATH . '/scripts/export_for_host.php', "--out={$brokenFile}"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        BASE_PATH,
        array_merge(getenv(), ['FAKE_GZIP_FAILURE' => $failure])
    );
    $brokenOutput = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $t->check("{$failure}: exit 1", 1, proc_close($proc));
    $t->ok("{$failure}: says it failed", str_contains($brokenOutput, 'The export failed'), $brokenOutput);
    $t->ok("{$failure}: no file left", !is_file($brokenFile));
}

$t->section('The file is made, and nothing changes where it was made');
$t->ok('exit 0', $code === 0, $output);
$t->ok('the file exists', is_file($file));
$same = true;
foreach ($tables as $table) {
    $same = $same && $before[$table] === (int) $value("SELECT COUNT(*) FROM `{$table}`");
}
$t->ok('every table here keeps every row', $same);
$t->ok('the report names who can sign in on the host', str_contains($output, 'administrators can sign in'), $output);
$t->ok('the report never shows a password or hash', !preg_match('/\$2y\$|password_hash/', $output), $output);

$sql = (string) gzdecode((string) file_get_contents($file));
$t->section('What it contains');
$t->ok('no customer email', !str_contains($sql, $email));
$t->ok('no MariaDB-only way of adding columns', !preg_match('/ADD COLUMN IF NOT EXISTS/i', $sql));
$t->ok('no address of this PC', !str_contains($sql, '127.0.0.1:3000'));

$t->section('It loads into a new empty database and gives a working shop');
// Named after this run's test database, so several shop copies on one PC never share it.
$hostDb = preg_replace('/[^A-Za-z0-9_]/', '', (string) \App\Config\Env::get('DB_DATABASE', 'shopco_test')) . '_hostexport';
$t->sql("DROP DATABASE IF EXISTS `{$hostDb}`");
$t->sql("CREATE DATABASE `{$hostDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$proc = proc_open([PHP_BINARY, BASE_PATH . '/scripts/restore_database.php', "--file={$file}", "--database={$hostDb}"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH);
$restored = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$t->ok('it loads', proc_close($proc) === 0, $restored);

$count = static fn (string $table, string $where = '1=1'): int => (int) $value("SELECT COUNT(*) FROM `{$hostDb}`.`{$table}` WHERE {$where}");
$hostTables = array_map(static fn (array $r): string => (string) array_values($r)[0], $t->sql("SHOW TABLES FROM `{$hostDb}`"));
sort($hostTables);
$sorted = $tables;
sort($sorted);
$t->check('every table exists there', $sorted, $hostTables);

foreach (['categories', 'products', 'product_variants', 'product_images', 'shipping_zones', 'banners', 'schema_migrations'] as $table) {
    $t->check("{$table}: all rows came", $before[$table], $count($table));
}
$t->check('administrators came', (int) $value("SELECT COUNT(*) FROM `users` WHERE `role` <> 'customer'"), $count('users', "`role` <> 'customer'"));
$t->ok('the setup page stays closed: an administrator exists there', $count('users', "`email` = '{$adminEmail}'") === 1);

foreach (['sessions', 'addresses', 'carts', 'cart_items', 'wishlists', 'wishlist_items', 'orders', 'order_items', 'payments', 'refunds', 'newsletter_subscribers', 'email_outbox', 'rate_limits', 'admin_audit_logs', 'inventory_logs', 'email_verification_tokens'] as $table) {
    $t->check("{$table}: left out", 0, $count($table));
}
$t->check('no customers came', 0, $count('users', "`role` = 'customer'"));

$t->check('the approved review came', 1, $count('reviews', "`comment` = 'Approved review'"));
$t->check('without who wrote it', 0, $count('reviews', '`user_id` IS NOT NULL OR `author_email` IS NOT NULL'));
$t->check('reviews still waiting did not come', 0, $count('reviews', "`status` <> 'approved'"));
$t->check('coupons came, with their use counts back to 0', 0, (int) $value("SELECT `used_count` FROM `{$hostDb}`.`promotions` WHERE `code` = 'EXPORTTEST'"));
$t->check("this PC's refresh address did not come", 0, $count('store_settings', "`key_name` = 'storefront_refresh_last'"));
$t->check('dev mode is not carried over: it starts off', 0, $count('store_settings', "`key_name` = 'dev_mode'"));

// New rows start from 1, so the host's first order is not number 67.
$t->sql("INSERT INTO `{$hostDb}`.`newsletter_subscribers` (`email`) VALUES ('first@example.test')");
$t->check('emptied tables count from 1 again', 1, (int) $value("SELECT MAX(`id`) FROM `{$hostDb}`.`newsletter_subscribers`"));

$t->sql("DROP DATABASE IF EXISTS `{$hostDb}`");
$t->sql("DELETE FROM `reviews` WHERE `comment` IN ('Approved review', 'Pending review')");
$t->sql("DELETE FROM `promotions` WHERE `code` = 'EXPORTTEST'");
$t->sql("DELETE FROM `store_settings` WHERE `key_name` IN ('storefront_refresh_last', 'dev_mode')");
$t->sql("DELETE FROM `newsletter_subscribers` WHERE `email` = '{$email}'");
$t->sql("DELETE FROM `sessions` WHERE `user_id` = {$shopper}");
$t->sql("DELETE FROM `users` WHERE `id` = {$shopper} OR `email` = '{$adminEmail}'");
@unlink($file);
@rmdir($dir);

$t->finish();
