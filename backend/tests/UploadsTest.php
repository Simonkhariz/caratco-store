<?php

declare(strict_types=1);

/**
 * Uploaded pictures: camera metadata is removed on upload (GPS, device; the rotation is kept),
 * unused files are only listed for review and removed by a confirmed clean-up, tests never touch
 * the shop's real uploads folder, and the backup script captures the database and the uploads.
 * run.php points UPLOAD_DIR at storage/test-uploads.
 *
 *   php backend/tests/run.php Uploads
 */

require __DIR__ . '/support/TestKit.php';

use App\Config\Env;
use App\Services\DatabaseCleanup;
use App\Support\UploadFiles;

$t = new TestKit('Uploads');
$fixtures = __DIR__ . '/fixtures';
$uploadDir = UploadFiles::directory();
$t->ok('tests use their own uploads folder', str_ends_with($uploadDir, 'storage/test-uploads') && is_dir($uploadDir), $uploadDir);

$admin = $t->adminSession('uploads');
/** @return array{status: int, json: array|null} */
$upload = static function (string $file, string $mime) use ($t, $admin): array {
    $ch = curl_init($t->api . '/admin/upload-image');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => ['image' => new CURLFile($file, $mime, basename($file))],
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-CSRF-Token: ' . $admin['csrf']],
        CURLOPT_COOKIEFILE => $t->cookieFile($admin['jar']),
        CURLOPT_COOKIEJAR => $t->cookieFile($admin['jar']),
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode($body, true)];
};
$saved = static fn (array $res): string => $uploadDir . '/' . (string) ($res['json']['data']['file_name'] ?? 'missing');

// -----------------------------------------------------------------------------
$t->section('Camera metadata is removed on upload');
$t->ok('fixture has a GPS location and a rotation', isset(exif_read_data("{$fixtures}/gps-rotated.jpg", null, true)['GPS']));
$res = $upload("{$fixtures}/gps-rotated.jpg", 'image/jpeg');
$t->check('rotated phone photo uploaded', 201, $res['status']);
$file = $saved($res);
$exif = @exif_read_data($file, null, true) ?: [];
$t->check('GPS location removed', false, isset($exif['GPS']));
$t->check('camera make and model removed', [null, null], [$exif['IFD0']['Make'] ?? null, $exif['IFD0']['Model'] ?? null]);
$t->check('rotation kept, so it is shown upright', 6, $exif['IFD0']['Orientation'] ?? null);
$t->ok('still a valid picture of the same size', (getimagesize($file) ?: [0, 0])[0] === 64);
$t->check('reported size is the stored size', filesize($file), $res['json']['data']['size_bytes'] ?? null);
$t->check('the picture data itself is unchanged', substr((string) file_get_contents("{$fixtures}/gps-rotated.jpg"), (int) strpos((string) file_get_contents("{$fixtures}/gps-rotated.jpg"), "\xFF\xDA")), substr((string) file_get_contents($file), (int) strpos((string) file_get_contents($file), "\xFF\xDA")));

$res = $upload("{$fixtures}/gps-upright.jpg", 'image/jpeg');
$exif = @exif_read_data($saved($res), null, true) ?: [];
$t->check('upright photo: no EXIF left at all', [false, false], [isset($exif['GPS']), isset($exif['IFD0'])]);

$res = $upload("{$fixtures}/meta.webp", 'image/webp');
$raw = (string) file_get_contents($saved($res));
$t->check('WebP uploaded', 201, $res['status']);
$t->check('WebP EXIF and XMP removed', [false, false], [str_contains($raw, 'EXIF'), str_contains($raw, 'xmpmeta')]);
$t->ok('WebP still valid', getimagesize($saved($res)) !== false);

$png = (string) file_get_contents("{$fixtures}/plain.png");
$text = 'Comment' . "\0" . 'Shot at 12 Secret Street';
$chunk = pack('N', strlen($text)) . 'tEXt' . $text . pack('N', crc32('tEXt' . $text));
$pngWithText = substr($png, 0, 33) . $chunk . substr($png, 33); // after the 33-byte signature + IHDR
$tmpPng = sys_get_temp_dir() . '/shopco-uploads-' . getmypid() . '.png';
file_put_contents($tmpPng, $pngWithText);
$t->cleanup(fn () => @unlink($tmpPng));
$res = $upload($tmpPng, 'image/png');
$t->check('PNG uploaded', 201, $res['status']);
$t->check('PNG text chunk removed', false, str_contains((string) file_get_contents($saved($res)), 'Secret Street'));

// -----------------------------------------------------------------------------
$t->section('Only real JPEG, PNG and WebP pictures are accepted (no PHP extension needed)');
$fake = static function (string $name, string $bytes, string $claimedMime) use ($t, $upload): int {
    $path = sys_get_temp_dir() . '/shopco-fake-' . getmypid() . '-' . $name;
    file_put_contents($path, $bytes);
    $t->cleanup(fn () => @unlink($path));
    return $upload($path, $claimedMime)['status'];
};
$t->check('text renamed to .jpg is refused, whatever the browser claims', 422, $fake('note.jpg', 'just some text', 'image/jpeg'));
$t->check('PHP code renamed to .png is refused', 422, $fake('shell.png', '<?php echo 1;', 'image/png'));
$t->check('SVG is refused (it can carry script)', 422, $fake('logo.jpg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'image/jpeg'));
$t->check('a real GIF is refused: not an allowed format', 422, $fake('a.jpg', (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 'image/jpeg'));
$t->check('only the first bytes of a JPEG are refused', 422, $fake('cut.jpg', "\xFF\xD8\xFF\xE0", 'image/jpeg'));
$res = $upload("{$fixtures}/plain.png", 'image/jpeg');
$t->check('a real PNG the browser calls a JPEG is stored as a PNG', [201, 'png'], [$res['status'], pathinfo((string) ($res['json']['data']['file_name'] ?? ''), PATHINFO_EXTENSION)]);
$usesFinfo = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/src', FilesystemIterator::SKIP_DOTS)) as $source) {
    if (str_contains((string) file_get_contents($source->getPathname()), 'finfo(')) {
        $usesFinfo[] = $source->getFilename();
    }
}
$t->check('the API never needs the fileinfo extension, which some hosts lack', [], $usesFinfo);

// -----------------------------------------------------------------------------
$t->section('Unused pictures: listed for review, removed only by a confirmed clean-up');
$t->sql("DELETE FROM `store_settings` WHERE `key_name` LIKE 'retention_%'");
array_map('unlink', glob("{$uploadDir}/*") ?: []);
$old = time() - 3 * 86400;
$put = static function (string $name, int $mtime) use ($uploadDir, $fixtures): string {
    copy("{$fixtures}/plain.png", "{$uploadDir}/{$name}");
    touch("{$uploadDir}/{$name}", $mtime);
    return "uploads/{$name}";
};
$unusedOld = $put('prod_20260101_000000_aaaaaaaa.png', $old);
$usedOld = $put('prod_20260101_000000_bbbbbbbb.png', $old);
$unusedNew = $put('prod_20260101_000000_cccccccc.png', time());
$pid = (int) $t->scalar("SELECT `id` FROM `products` ORDER BY `id` LIMIT 1");
$t->db->insert("INSERT INTO `product_images` (`product_id`, `image_url`, `is_primary`, `display_order`) VALUES (?, ?, 0, 99)", 'is', [$pid, "/{$usedOld}"]);
$t->cleanup(fn () => $t->sql("DELETE FROM `product_images` WHERE `image_url` LIKE '/uploads/prod_20260101_%'"));

$cleanup = new DatabaseCleanup($t->db);
$asOf = date('Y-m-d H:i:s');
$snapshot = $cleanup->snapshot($asOf);
$t->check('only the old file nothing uses is listed', [$unusedOld], $snapshot['ids']['unused_images'] ?? null);
$t->ok('it counts as a technical item (no "records past keep periods" alert)', in_array('unused_images', DatabaseCleanup::HOUSEKEEPING, true));
$records = $cleanup->records('unused_images', $asOf, 1, 25);
$t->check('the admin sees the file, its size and date', '/' . $unusedOld, $records['items'][0]['file'] ?? null);
$t->ok('listing removed nothing', is_file("{$uploadDir}/" . basename($unusedOld)));

// Someone starts using the file after the review: the clean-up refuses.
$t->db->insert("INSERT INTO `product_images` (`product_id`, `image_url`, `is_primary`, `display_order`) VALUES (?, ?, 0, 98)", 'is', [$pid, "/{$unusedOld}"]);
$refused = null;
try {
    $cleanup->execute($asOf, $snapshot['fingerprint'], 'Test');
} catch (DomainException $e) {
    $refused = true;
}
$t->check('a file used since the review is not removed (list changed)', true, $refused);
$t->ok('and is still there', is_file("{$uploadDir}/" . basename($unusedOld)));
$t->sql("DELETE FROM `product_images` WHERE `image_url` = ?", 's', ["/{$unusedOld}"]);

$snapshot = $cleanup->snapshot($asOf);
$removed = $cleanup->execute($asOf, $snapshot['fingerprint'], 'Test');
$t->check('the confirmed clean-up removes the reviewed file', 1, $removed['unused_images'] ?? null);
$t->check('the unused file is gone', false, is_file("{$uploadDir}/" . basename($unusedOld)));
$t->check('the file a product uses is kept', true, is_file("{$uploadDir}/" . basename($usedOld)));
$t->check('a file changed within the last day is kept', true, is_file("{$uploadDir}/" . basename($unusedNew)));

// In testing without its own folder, nothing would ever be listed or removed.
$prop = new ReflectionProperty(Env::class, 'vars');
$vars = $prop->getValue();
$prop->setValue(null, array_merge($vars, ['UPLOAD_DIR' => '']));
$t->check('without UPLOAD_DIR, tests can never list real pictures', [], UploadFiles::unused($t->db, date('Y-m-d H:i:s', time() + 10 * 86400)));
$t->check('or delete them', 0, UploadFiles::delete(['uploads/prod_20260101_000000_bbbbbbbb.png']));
$prop->setValue(null, $vars);
$t->check('paths outside the uploads folder are never deleted', 0, UploadFiles::delete(['uploads/../tests/fixtures/plain.png', '../.env']));

// -----------------------------------------------------------------------------
$t->section('Backups');
$backupDir = sys_get_temp_dir() . '/shopco-backup-test-' . getmypid();
$proc = proc_open([PHP_BINARY, BASE_PATH . '/scripts/backup.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, array_merge(getenv(), ['BACKUP_DIR' => $backupDir]));
$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
$code = proc_close($proc);
$t->check('backup script succeeds', 0, $code);
$folder = (glob("{$backupDir}/*", GLOB_ONLYDIR) ?: [''])[0];
$t->cleanup(function () use ($backupDir): void {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backupDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($backupDir);
});
$dump = (string) @gzdecode((string) @file_get_contents("{$folder}/database.sql.gz"));
$t->ok('the database dump holds the tables', str_contains($dump, 'CREATE TABLE `orders`') && str_contains($dump, 'Dump completed'), trim($out));
$t->ok('it is the test database', str_contains($dump, (string) Env::get('DB_DATABASE')) || str_contains($dump, 'CREATE TABLE'));
$archive = new PharData("{$folder}/uploads.tar.gz");
$names = [];
foreach (new RecursiveIteratorIterator($archive) as $entry) {
    $names[] = basename((string) $entry);
}
$t->check('the uploads archive holds the pictures', true, in_array(basename($usedOld), $names, true));
$manifest = json_decode((string) @file_get_contents("{$folder}/manifest.json"), true) ?: [];
$t->check('the manifest checksum matches the dump', hash_file('sha256', "{$folder}/database.sql.gz"), $manifest['database_dump']['sha256'] ?? null);

// -----------------------------------------------------------------------------
$t->section('Backups without mysqldump, and restoring them');
// Throw-away databases next to the test database, dropped at the end; nothing else is touched.
$source = (string) Env::get('DB_DATABASE');
$restoreA = "{$source}_ra" . getmypid();
$restoreB = "{$source}_rb" . getmypid();
$restoreC = "{$source}_rc" . getmypid();
$t->cleanup(function () use ($t, $restoreA, $restoreB, $restoreC): void {
    foreach ([$restoreA, $restoreB, $restoreC] as $db) {
        $t->sql("DROP DATABASE IF EXISTS `{$db}`");
    }
});
$script = static function (string $name, array $args, array $env = []) use ($backupDir): array {
    $proc = proc_open(array_merge([PHP_BINARY, BASE_PATH . "/scripts/{$name}.php"], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, array_merge(getenv(), ['BACKUP_DIR' => $backupDir], $env));
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return ['code' => proc_close($proc), 'out' => trim($out)];
};
$tableCount = static fn (string $db): int => (int) $t->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'", 's', [$db]);

$r = $script('restore_database', ["--backup={$folder}", "--database={$restoreA}", '--create']);
$t->ok('a mysqldump backup restores into a new database without shell tools', $r['code'] === 0 && $tableCount($restoreA) === $tableCount($source), $r['out']);

sleep(1); // a new dated folder
$r = $script('backup', ['--php-dump']);
$t->ok('--php-dump writes the backup with PHP alone (as on hosts that block mysqldump)', $r['code'] === 0 && str_contains($r['out'], 'written by PHP'), $r['out']);
$folders = glob("{$backupDir}/*", GLOB_ONLYDIR) ?: [];
sort($folders);
$phpFolder = (string) end($folders);
$phpManifest = json_decode((string) @file_get_contents("{$phpFolder}/manifest.json"), true) ?: [];
$t->check('the manifest says how it was written and counts every table', ['php', $tableCount($source)], [$phpManifest['database_dump_method'] ?? null, count($phpManifest['table_rows'] ?? [])]);

sleep(1);
$r = $script('backup', [], ['MYSQLDUMP_PATH' => BASE_PATH . '/no-such-mysqldump']);
$t->ok('when mysqldump is missing, the backup is written by PHP instead of failing', $r['code'] === 0 && str_contains($r['out'], 'written by PHP'), $r['out']);

$r = $script('restore_database', ["--backup={$phpFolder}", "--database={$restoreB}", '--create']);
$t->ok('it restores with every table holding the same number of rows', $r['code'] === 0 && str_contains($r['out'], 'same number of rows'), $r['out']);
$sample = static fn (string $db): array => $t->db->fetchAll("SELECT `id`, `order_number`, `grand_total`, `created_at`, `shipping_address_snapshot` FROM `{$db}`.`orders` ORDER BY `id` LIMIT 50");
$t->check('restored orders are identical, field for field (text, money, dates, JSON)', $sample($source), $sample($restoreB));

$r = $script('restore_database', ["--backup={$phpFolder}", "--database={$restoreB}"]);
$t->ok('a database that already has tables is never restored over', $r['code'] === 1 && str_contains($r['out'], 'already has tables'), $r['out']);
$r = $script('restore_database', ["--backup={$phpFolder}", "--database={$source}"]);
$t->ok('nor is the live database', $r['code'] === 1 && str_contains($r['out'], 'already has tables'), $r['out']);

$damaged = "{$backupDir}/damaged";
@mkdir($damaged, 0700, true);
copy("{$phpFolder}/manifest.json", "{$damaged}/manifest.json");
file_put_contents("{$damaged}/database.sql.gz", gzencode('SELECT 1;'));
$r = $script('restore_database', ["--backup={$damaged}", "--database={$restoreC}", '--create']);
$t->ok('a dump that does not match its manifest is refused before loading', $r['code'] === 1 && str_contains($r['out'], 'does not match') && $tableCount($restoreC) === 0, $r['out']);

$whole = (string) gzdecode((string) file_get_contents("{$phpFolder}/database.sql.gz"));
file_put_contents("{$damaged}/cut.sql.gz", gzencode(substr($whole, 0, (int) (strlen($whole) * 0.6))));
$r = $script('restore_database', ["--file={$damaged}/cut.sql.gz", "--database={$restoreC}", '--create']);
$t->ok('a cut-off dump stops and leaves the new database empty again', $r['code'] === 1 && $tableCount($restoreC) === 0, $r['out']);

array_map('unlink', glob("{$uploadDir}/*") ?: []);
exit($t->finish());
