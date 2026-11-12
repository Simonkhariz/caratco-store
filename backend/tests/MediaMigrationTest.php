<?php

declare(strict_types=1);

/**
 * Copying pictures from the server disk to the bucket (roadmap Prompt 24) with
 * scripts/migrate_uploads.php, rehearsed against the stand-in S3 API: a test batch (pictures in
 * use first), checks of count, content, metadata and the shop's /uploads links, safe re-runs,
 * refusals, a full copy that needs a valid backup, and copying back for a rollback. Originals
 * must never change. Uses its own throw-away disk folder and test category; nothing is sent to
 * Cloudflare and the real uploads folder is never read.
 *
 *   php backend/tests/run.php MediaMigration
 */

require __DIR__ . '/support/TestKit.php';
require __DIR__ . '/support/FakeS3.php';

use App\Storage\StorageKey;

$t = new TestKit('MediaMigration');
$fake = new FakeS3($t, (int) (getenv('TEST_S3_PORT') ?: 8121));
$disk = "{$fake->dir}/disk";
$restore = "{$fake->dir}/restore";
$reports = "{$fake->dir}/reports";
@mkdir("{$disk}/catalog", 0700, true);
@mkdir($restore, 0700, true);

// Five pictures and one file that is not a picture; two pictures are used by the shop.
$inUse = 'prod_20260101_000001_aaaaaaaa.webp';
$inUse2 = 'prod_20260101_000002_bbbbbbbb.jpg';
$files = [
    $inUse => 'meta.webp',
    $inUse2 => 'gps-upright.jpg',
    'catalog/a-shirt.png' => 'plain.png',
    'catalog/b-shirt.webp' => 'meta.webp',
    'prod_20260101_000003_cccccccc.jpg' => 'gps-rotated.jpg',
];
foreach ($files as $key => $fixture) {
    copy(__DIR__ . "/fixtures/{$fixture}", "{$disk}/{$key}");
}
file_put_contents("{$disk}/notes.txt", 'not a picture');
$fingerprint = static function () use ($disk): array {
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($disk, FilesystemIterator::SKIP_DOTS)) as $f) {
        $out[str_replace('\\', '/', substr($f->getPathname(), strlen($disk) + 1))] = hash_file('sha256', $f->getPathname());
    }
    ksort($out);
    return $out;
};
$originals = $fingerprint();

$stamp = $t->uniq('mig');
$categoryId = (int) $t->sql(
    "INSERT INTO `categories` (`name`, `slug`, `description`, `image_url`, `display_order`, `is_active`) VALUES (?, ?, 'Media migration test', ?, 99, 1)",
    'sss',
    ["Migration {$stamp}", "migration-{$stamp}", "/uploads/{$inUse}"]
) > 0 ? (int) $t->scalar('SELECT `id` FROM `categories` WHERE `slug` = ?', 's', ["migration-{$stamp}"]) : 0;
$bannerId = $t->sql(
    "INSERT INTO `banners` (`title`, `image_url`, `display_order`, `is_active`) VALUES (?, ?, 99, 0)",
    'ss',
    ["Migration {$stamp}", "http://localhost:3000/uploads/{$inUse2}"]
) > 0 ? (int) $t->scalar('SELECT `id` FROM `banners` WHERE `title` = ?', 's', ["Migration {$stamp}"]) : 0;
$t->cleanup(function () use ($t, $categoryId, $bannerId): void {
    $t->sql('DELETE FROM `categories` WHERE `id` = ?', 'i', [$categoryId]);
    $t->sql('DELETE FROM `banners` WHERE `id` = ?', 'i', [$bannerId]);
});
$t->ok('test category and banner use two of the pictures', $categoryId > 0 && $bannerId > 0);

$diskEnv = $fake->envFile('env-disk', array_merge($fake->envLines(), ['STORAGE_DRIVER=local', "UPLOAD_DIR={$disk}", "MIGRATION_REPORT_DIR={$reports}", 'STOREFRONT_REVALIDATE_URL=']));
$restoreEnv = $fake->envFile('env-restore', array_merge($fake->envLines(), ['STORAGE_DRIVER=local', "UPLOAD_DIR={$restore}", "MIGRATION_REPORT_DIR={$reports}"]));
$allOutput = '';
$run = static function (array $args, string $env = '') use ($diskEnv, &$allOutput): array {
    $proc = proc_open(
        array_merge([PHP_BINARY, BASE_PATH . '/scripts/migrate_uploads.php'], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        BASE_PATH,
        array_merge(getenv(), ['SHOPCO_ENV_FILE' => $env !== '' ? $env : $diskEnv])
    );
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $allOutput .= $out;
    return ['code' => proc_close($proc), 'out' => $out];
};
$inBucket = static fn (string $key): ?string => is_file($fake->objectFile("uploads/{$key}")) ? (string) file_get_contents($fake->objectFile("uploads/{$key}")) : null;
$bucketKeys = static function () use ($fake): array {
    $keys = array_map(static fn (string $f): string => rawurldecode(basename($f)), glob("{$fake->dir}/objects/*") ?: []);
    $keys = array_values(array_filter($keys, static fn (string $k): bool => !str_ends_with($k, '.meta')));
    sort($keys);
    return $keys;
};
$puts = static fn (): int => count(array_filter(
    array_map('json_decode', @file("{$fake->dir}/requests.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []),
    static fn ($r): bool => ($r->method ?? '') === 'PUT'
));
$latestReport = static function () use ($reports): array {
    $all = glob("{$reports}/*.json") ?: [];
    sort($all);
    return $all === [] ? [] : (json_decode((string) file_get_contents(end($all)), true) ?: []);
};

// -----------------------------------------------------------------------------
$t->section('Plan only by default');
$plan = $run([]);
$t->check('exits cleanly', 0, $plan['code']);
$t->ok('says it is a plan and counts the pictures in use', str_contains($plan['out'], 'Plan only') && str_contains($plan['out'], '6 picture(s) on the server disk, 2 in use'), $plan['out']);
$t->ok('the pictures in use come first', (bool) preg_match('/planned\s+' . preg_quote($inUse, '/') . '.*\n.*planned\s+' . preg_quote($inUse2, '/') . '/', $plan['out']), $plan['out']);
$t->check('nothing was written to the bucket', [0, []], [$puts(), $bucketKeys()]);
$t->check('and no report was written', [], glob("{$reports}/*.json") ?: []);

// -----------------------------------------------------------------------------
$t->section('A test batch of two');
$batch = $run(['--apply', '--limit=2']);
$t->check('exits cleanly', 0, $batch['code']);
$t->check('copies exactly the two pictures in use', ["uploads/{$inUse}", "uploads/{$inUse2}"], $bucketKeys());
$t->check('same bytes in the bucket', [file_get_contents("{$disk}/{$inUse}"), file_get_contents("{$disk}/{$inUse2}")], [$inBucket($inUse), $inBucket($inUse2)]);
$meta = json_decode((string) @file_get_contents($fake->objectFile("uploads/{$inUse2}") . '.meta'), true) ?: [];
$t->check('with the right type and caching', ['image/jpeg', StorageKey::cacheControl($inUse2)], [$meta['content_type'] ?? null, $meta['cache_control'] ?? null]);
$report = $latestReport();
$t->check('report: counts and object totals', [['copied' => 2], 6, 2], [$report['counts'] ?? null, $report['source_count'] ?? null, $report['target_count'] ?? null]);
$t->check('report: each file passed content, size, type and caching checks', [true, true], array_map(static fn (array $f): bool => $f['checks'] === ['content' => true, 'size' => true, 'type' => true, 'caching' => true], $report['files'] ?? []));
$t->check('report: names where each picture is used', [['categories'], ['banners']], array_column($report['files'] ?? [], 'used_by'));
$t->check('the originals did not change', $originals, $fingerprint());

// -----------------------------------------------------------------------------
$t->section('Running it again is safe');
$before = $puts();
$again = $run(['--apply', '--limit=2']);
$t->check('exits cleanly', 0, $again['code']);
$t->check('nothing is uploaded twice', $before, $puts());
$t->check('both reported as already there', ['already there' => 2], $latestReport()['counts'] ?? null);

// -----------------------------------------------------------------------------
$t->section("The shop's links serve the copies");
$api = $fake->startApi((int) (getenv('TEST_S3_API_PORT') ?: 8122), $fake->envFile('env-api', array_merge($fake->envLines(), ['STOREFRONT_REVALIDATE_URL='])));
$linked = $run(['--apply', "--keys={$inUse},{$inUse2}", "--verify-url={$api}"]);
$t->check('exits cleanly', 0, $linked['code']);
$t->check('/uploads/<key> from an API on the bucket returns the same picture', [true, true], array_map(static fn (array $f): bool => ($f['checks']['link'] ?? false) === true, $latestReport()['files'] ?? []));
$category = $t->http('GET', "{$api}/api/v1/categories");
$listed = array_values(array_filter((array) TestKit::get($category, 'data'), static fn ($c): bool => ($c['slug'] ?? '') === "migration-{$stamp}"));
$t->check('the stored link is unchanged (/uploads/<key>)', "/uploads/{$inUse}", $listed[0]['image_url'] ?? null);

// -----------------------------------------------------------------------------
$t->section('Refusals and failures');
$before = $puts();
$t->ok('--all without a backup is refused', ($r = $run(['--apply', '--all']))['code'] === 1 && str_contains($r['out'], '--all needs --backup'), $r['out']);
$backup = "{$fake->dir}/backup";
@mkdir($backup, 0700, true);
file_put_contents("{$backup}/database.sql.gz", 'db');
file_put_contents("{$backup}/uploads.tar.gz", 'pictures');
$manifest = ['created_at' => date('c'), 'database_dump' => ['file' => 'database.sql.gz', 'sha256' => hash('sha256', 'db')], 'uploads_archive' => ['file' => 'uploads.tar.gz', 'sha256' => hash('sha256', 'changed later')]];
file_put_contents("{$backup}/manifest.json", json_encode($manifest));
$t->ok('--all with a backup that no longer matches is refused', ($r = $run(['--apply', '--all', "--backup={$backup}"]))['code'] === 1 && str_contains($r['out'], 'does not match'), $r['out']);
$t->check('nothing was uploaded by the refused runs', $before, $puts());
$t->ok('a name that is not on the disk fails', ($r = $run(['--apply', '--keys=prod_20260101_000009_dddddddd.webp']))['code'] === 1 && str_contains($r['out'], 'not found at the source'), $r['out']);
$t->ok('an unsafe name is refused before anything runs', ($r = $run(['--apply', '--keys=../.env']))['code'] === 1 && str_contains($r['out'], 'Not a valid picture name'), $r['out']);
$skipped = $run(['--apply', '--keys=notes.txt']);
$t->ok('a file that is not a picture is skipped, not copied', $skipped['code'] === 0 && str_contains($skipped['out'], 'skipped') && $inBucket('notes.txt') === null, $skipped['out']);
$fake->mode('500');
$down = $run(['--apply', '--keys=catalog/a-shirt.png']);
$fake->mode('ok');
$t->ok('a provider error fails clearly', $down['code'] === 1 && str_contains($down['out'], 'InternalError') && str_contains($down['out'], 'Report:'), $down['out']);
$t->ok('without a PHP crash', !str_contains($down['out'], 'Fatal error'), $down['out']);
$fake->mode('500');
$downBack = $run(['--apply', '--to=local'], $restoreEnv);
$fake->mode('ok');
$t->ok('a bucket that cannot be listed stops the rollback cleanly', $downBack['code'] === 1 && str_contains($downBack['out'], '[migrate_uploads]') && !str_contains($downBack['out'], 'Fatal error'), $downBack['out']);

// -----------------------------------------------------------------------------
$t->section('Full copy with a valid backup');
$manifest['uploads_archive']['sha256'] = hash('sha256', 'pictures');
file_put_contents("{$backup}/manifest.json", json_encode($manifest));
$full = $run(['--apply', '--all', "--backup={$backup}"]);
$t->check('exits cleanly', 0, $full['code']);
$counts = $latestReport()['counts'] ?? [];
$t->check('3 copied, 2 already there, the non-picture skipped', ['copied' => 3, 'already there' => 2, 'skipped' => 1], [
    'copied' => $counts['copied'] ?? 0, 'already there' => $counts['already there'] ?? 0, 'skipped' => $counts['skipped'] ?? 0,
]);
$t->check('object count: every picture on the disk is in the bucket', array_map(static fn (string $k): string => "uploads/{$k}", array_values(array_diff(array_keys($originals), ['notes.txt']))), $bucketKeys());
$t->ok('every copy has the same bytes', array_reduce(array_keys($files), static fn (bool $ok, string $k): bool => $ok && $inBucket($k) === file_get_contents("{$disk}/{$k}"), true));
$t->check('the originals did not change', $originals, $fingerprint());

// -----------------------------------------------------------------------------
$t->section('A different file already in the bucket is never overwritten');
file_put_contents($fake->objectFile('uploads/catalog/b-shirt.webp'), 'someone else');
$conflict = $run(['--apply', '--keys=catalog/b-shirt.webp']);
$t->ok('reported as a failure', $conflict['code'] === 1 && str_contains($conflict['out'], 'different file with this name'), $conflict['out']);
$t->check('and left alone', 'someone else', $inBucket('catalog/b-shirt.webp'));
file_put_contents($fake->objectFile('uploads/catalog/b-shirt.webp'), file_get_contents("{$disk}/catalog/b-shirt.webp"));

// -----------------------------------------------------------------------------
$t->section('Rollback: copy everything back to a disk');
$back = $run(['--apply', '--all', "--backup={$backup}", '--to=local'], $restoreEnv);
$t->check('exits cleanly', 0, $back['code']);
$restored = [];
foreach (array_keys($files) as $key) {
    $restored[$key] = is_file("{$restore}/{$key}") ? hash_file('sha256', "{$restore}/{$key}") : null;
}
ksort($restored);
$t->check('every picture is back, byte for byte', array_diff_key($originals, ['notes.txt' => true]), $restored);
$t->ok('the bucket still holds them all (a rollback deletes nothing)', count($bucketKeys()) === 5);

// -----------------------------------------------------------------------------
$t->section('Keys stay secret');
$t->ok('no run printed the access key or secret', !str_contains($allOutput, $fake->secret) && !str_contains($allOutput, $fake->access));
$reportText = implode('', array_map('file_get_contents', glob("{$reports}/*.json") ?: []));
$t->ok('no report holds them', $reportText !== '' && !str_contains($reportText, $fake->secret) && !str_contains($reportText, $fake->access));

exit($t->finish());
