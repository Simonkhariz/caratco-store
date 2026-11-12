<?php

declare(strict_types=1);

/**
 * Copying backups to the bucket (scripts/backup_to_bucket.php), rehearsed against the stand-in
 * S3 API: a real backup from scripts/backup.php is copied and read back identical, into its own
 * folder that the shop never lists or serves as pictures; damaged or unfinished backups are
 * refused; re-runs send nothing twice; nothing in the bucket is ever deleted; the keys never
 * appear in the output. Nothing is sent to Cloudflare.
 *
 *   php backend/tests/run.php BackupBucket
 */

require __DIR__ . '/support/TestKit.php';
require __DIR__ . '/support/FakeS3.php';

$t = new TestKit('BackupBucket');
$fake = new FakeS3($t, (int) (getenv('TEST_S3_PORT') ?: 8131));
$backups = "{$fake->dir}/backups";
@mkdir($backups, 0700, true);
@mkdir("{$fake->dir}/empty-uploads", 0700, true); // a new shop on disk storage: no pictures yet

$env = $fake->envFile('env', array_merge($fake->envLines(), ['STORAGE_DRIVER=local', "UPLOAD_DIR={$fake->dir}/empty-uploads", "BACKUP_DIR={$backups}"]));
$allOutput = '';
$run = static function (string $script, array $args = [], string $envFile = '') use ($env, &$allOutput): array {
    $proc = proc_open(
        array_merge([PHP_BINARY, BASE_PATH . "/scripts/{$script}"], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        BASE_PATH,
        array_merge(getenv(), ['SHOPCO_ENV_FILE' => $envFile !== '' ? $envFile : $env])
    );
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $allOutput .= $out;
    return ['code' => proc_close($proc), 'out' => $out];
};
$copy = static fn (array $args = [], string $envFile = ''): array => $run('backup_to_bucket.php', $args, $envFile);
$object = static fn (string $key): ?string => is_file($fake->objectFile($key)) ? (string) file_get_contents($fake->objectFile($key)) : null;
$puts = static fn (): int => count(array_filter(
    array_map(static fn (string $l) => json_decode($l, true), @file("{$fake->dir}/requests.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []),
    static fn ($r): bool => is_array($r) && ($r['method'] ?? '') === 'PUT'
));
// A backup folder like backup.php writes, with a manifest that matches (or not) its files.
$makeBackup = static function (string $name, string $dump, ?string $manifestDump = null) use ($backups): string {
    $dir = "{$backups}/{$name}";
    @mkdir($dir, 0700, true);
    file_put_contents("{$dir}/database.sql.gz", gzencode($dump));
    $listed = $manifestDump === null ? (string) file_get_contents("{$dir}/database.sql.gz") : gzencode($manifestDump);
    file_put_contents("{$dir}/manifest.json", json_encode([
        'created_at' => date('c'),
        'database_dump' => ['file' => 'database.sql.gz', 'bytes' => strlen($listed), 'sha256' => hash('sha256', $listed)],
        'uploads_archive' => null,
    ]));
    return $dir;
};

// -----------------------------------------------------------------------------
$t->section('A backup that cannot be written fails, with no manifest');
// support/failing_gzip.php makes the writer's gzwrite/gzclose fail as on a full disk.
$failedDir = "{$fake->dir}/backups-failed";
@mkdir($failedDir, 0700, true);
$failedEnv = $fake->envFile('env-failed', array_merge($fake->envLines(), ['STORAGE_DRIVER=local', "UPLOAD_DIR={$fake->dir}/empty-uploads", "BACKUP_DIR={$failedDir}"]));
foreach ([['short', '--php-dump'], ['lost', '--php-dump'], ['close', '--php-dump'], ['short', ''], ['close', '']] as [$failure, $flag]) {
    $proc = proc_open(
        array_values(array_filter([PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/support/failing_gzip.php', BASE_PATH . '/scripts/backup.php', $flag])),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        BASE_PATH,
        array_merge(getenv(), ['SHOPCO_ENV_FILE' => $failedEnv, 'FAKE_GZIP_FAILURE' => $failure])
    );
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $label = "{$failure} write" . ($flag !== '' ? ' (PHP dump)' : ' (default dump)');
    $t->ok("{$label}: exits with an error saying so", $code === 1 && str_contains($out, 'database dump failed'), "exit {$code}: {$out}");
    $t->check("{$label}: no manifest, no cut-off dump", [], array_merge(glob("{$failedDir}/*/manifest.json") ?: [], glob("{$failedDir}/*/database.sql.gz") ?: []));
}

// -----------------------------------------------------------------------------
$t->section('A real backup is copied to the bucket and reads back identical');
$made = $run('backup.php');
$t->ok('backup.php made a backup, even with no pictures yet (an empty folder used to stop it)', $made['code'] === 0, $made['out']);
$real = glob("{$backups}/*", GLOB_ONLYDIR) ?: [];
$name = basename((string) end($real));
$result = $copy();
$t->ok('copied (exit 0)', $result['code'] === 0, $result['out']);
$t->ok('says where it went', str_contains($result['out'], "backups/{$name}/") && str_contains($result['out'], 'database.sql.gz'), $result['out']);
$t->check('the dump in the bucket is byte-for-byte the backup', file_get_contents("{$backups}/{$name}/database.sql.gz"), $object("backups/{$name}/database.sql.gz"));
$t->check('and so is the manifest', file_get_contents("{$backups}/{$name}/manifest.json"), $object("backups/{$name}/manifest.json"));
$meta = json_decode((string) @file_get_contents($fake->objectFile("backups/{$name}/database.sql.gz") . '.meta'), true) ?: [];
$t->check('stored as private, never cached', ['application/gzip', 'private, no-store'], [$meta['content_type'] ?? null, $meta['cache_control'] ?? null]);
$t->ok('nothing landed in the pictures folder', glob($fake->objectFile('uploads/') . '*') === []);
$order = array_values(array_filter(
    array_map(static fn (string $l) => json_decode($l, true), @file("{$fake->dir}/requests.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []),
    static fn ($r): bool => is_array($r) && ($r['method'] ?? '') === 'PUT'
));
$t->ok('manifest.json is sent last (its presence means the copy is complete)', str_ends_with((string) (end($order)['path'] ?? ''), 'manifest.json'));

// -----------------------------------------------------------------------------
$t->section('The shop never lists or serves backups as pictures');
$pictures = new App\Storage\S3Storage($fake->endpoint(), 'auto', $fake->bucket, $fake->access, $fake->secret);
$t->check('the picture listing does not see them', [], array_column(iterator_to_array($pictures->list(), false), 'key'));
$apiPort = (int) (getenv('TEST_S3_API_PORT') ?: 8132);
$base = $fake->startApi($apiPort, $fake->envFile('env-api', array_merge($fake->envLines(), ['STOREFRONT_REVALIDATE_URL='])));
foreach (["/uploads/../backups/{$name}/database.sql.gz", "/uploads/backups/{$name}/database.sql.gz", "/backups/{$name}/database.sql.gz"] as $path) {
    $t->check("not downloadable: {$path}", 404, $t->http('GET', $base . $path)['status']);
}

// -----------------------------------------------------------------------------
$t->section('Running it again sends nothing twice; nothing is ever deleted');
$before = $puts();
$again = $copy();
$t->ok('second run succeeds', $again['code'] === 0, $again['out']);
$t->ok('reports the files as already there', str_contains($again['out'], 'nothing new to send') && str_contains($again['out'], 'already there'), $again['out']);
$t->check('and sent nothing', $before, $puts());
file_put_contents($fake->objectFile('backups/2020-01-01_000000/database.sql.gz'), 'an old backup');
$makeBackup('2099-01-01_000000', 'SELECT 1;');
$newest = $copy();
$t->ok('the newest backup is chosen by default', $newest['code'] === 0 && $object('backups/2099-01-01_000000/manifest.json') !== null, $newest['out']);
$t->check('older backups in the bucket are left alone', 'an old backup', $object('backups/2020-01-01_000000/database.sql.gz'));
$t->check('as is the earlier copy', file_get_contents("{$backups}/{$name}/database.sql.gz"), $object("backups/{$name}/database.sql.gz"));
$t->ok('and every local backup is still there', is_file("{$backups}/{$name}/database.sql.gz") && is_file("{$backups}/2099-01-01_000000/database.sql.gz"));

// -----------------------------------------------------------------------------
$t->section('Damaged or unfinished backups are refused before anything is sent');
$makeBackup('2099-02-01_000000', 'SELECT 2;', 'what the manifest expected');
$before = $puts();
$bad = $copy(['--dir=2099-02-01_000000']);
$t->ok('a file that does not match its manifest: refused', $bad['code'] === 1 && str_contains($bad['out'], 'does not match manifest.json'), $bad['out']);
$t->check('nothing sent', $before, $puts());
@mkdir("{$backups}/2099-03-01_000000", 0700, true);
file_put_contents("{$backups}/2099-03-01_000000/database.sql.gz", gzencode('half'));
$unfinished = $copy(['--dir=2099-03-01_000000']);
$t->ok('a backup without a manifest (still running or failed): refused', $unfinished['code'] === 1 && str_contains($unfinished['out'], 'did not finish'), $unfinished['out']);
$latest = $copy();
$t->ok('by default an unfinished newer backup is skipped (the newest finished one is checked: here the damaged one, refused)', $latest['code'] === 1 && str_contains($latest['out'], '2099-02-01_000000') && !str_contains($latest['out'], '2099-03-01_000000'), $latest['out']);
$t->check('a folder that is not a backup: refused', 1, $copy(['--dir=' . $fake->dir])['code']);
$t->check('a backup that does not exist: refused', 1, $copy(['--dir=1999-01-01_000000'])['code']);
$t->check('an unknown option: refused', 1, $copy(['--delete'])['code']);
$t->check('nothing sent by any of them', $before, $puts());
// The damaged test backup is removed from the test folder so the next sections use good ones.
foreach (glob("{$backups}/2099-02-01_000000/*") ?: [] as $f) {
    unlink($f);
}
rmdir("{$backups}/2099-02-01_000000");

// -----------------------------------------------------------------------------
$t->section('Backups never go into the pictures folder');
$before = $puts();
foreach (['uploads', 'uploads/backups', '/uploads/'] as $prefix) {
    $r = $copy([], $fake->envFile('env-prefix', array_merge($fake->envLines(), ["BACKUP_DIR={$backups}", "BACKUP_S3_PREFIX={$prefix}"])));
    $t->ok("BACKUP_S3_PREFIX={$prefix}: refused", $r['code'] === 1 && str_contains($r['out'], 'separate from the pictures'), $r['out']);
}
$r = $copy([], $fake->envFile('env-noprefix', array_merge($fake->envLines(), ["BACKUP_DIR={$backups}", 'S3_PREFIX='])));
$t->ok('pictures at the top of the bucket (S3_PREFIX empty): refused', $r['code'] === 1 && str_contains($r['out'], 'separate from the pictures'), $r['out']);
$t->check('nothing sent', $before, $puts());

// -----------------------------------------------------------------------------
$t->section('Pictures in the bucket: a growing copy that survives a mistaken delete');
$mirrorBackups = "{$fake->dir}/mirror-backups";
@mkdir($mirrorBackups, 0700, true);
$s3Env = $fake->envFile('env-s3', array_merge($fake->envLines(), ["BACKUP_DIR={$mirrorBackups}", 'MIGRATION_REPORT_DIR=' . $fake->dir . '/reports']));
$bucket = new App\Storage\S3Storage($fake->endpoint(), 'auto', $fake->bucket, $fake->access, $fake->secret);
$source = "{$fake->dir}/source.webp";
copy(__DIR__ . '/fixtures/meta.webp', $source);
$pictureA = 'prod_20260101_000001_aaaaaaaa.webp';
$pictureB = 'catalog/banner-test.webp';
$bucket->put($pictureA, $source, 'image/webp', App\Storage\StorageKey::cacheControl($pictureA));
$bucket->put($pictureB, $source, 'image/webp', 'public, max-age=86400');
$mirrorDir = "{$mirrorBackups}/pictures";
$newestManifest = static function () use ($mirrorBackups): array {
    $folders = glob("{$mirrorBackups}/2*", GLOB_ONLYDIR) ?: [];
    sort($folders);
    return json_decode((string) file_get_contents(end($folders) . '/manifest.json'), true) ?: [];
};
$first = $run('backup.php', [], $s3Env);
$t->ok('the backup runs with pictures in a bucket', $first['code'] === 0, $first['out']);
$t->check('both pictures are copied, identical', [file_get_contents($source), file_get_contents($source)], [@file_get_contents("{$mirrorDir}/{$pictureA}"), @file_get_contents("{$mirrorDir}/{$pictureB}")]);
$t->check('the manifest records it', [2, 2, 0], [$newestManifest()['pictures_mirror']['in_bucket'] ?? null, $newestManifest()['pictures_mirror']['copied'] ?? null, $newestManifest()['pictures_mirror']['failed'] ?? null]);
$t->ok('and says so', str_contains($first['out'], 'pictures in the bucket: 2'), $first['out']);
sleep(1);
$before = $fake->requests();
$second = $run('backup.php', [], $s3Env);
$t->check('the next night copies nothing new', 0, $newestManifest()['pictures_mirror']['copied'] ?? null);
$reads = array_filter(
    array_map(static fn (string $l) => json_decode($l, true), array_slice(file("{$fake->dir}/requests.log", FILE_IGNORE_NEW_LINES) ?: [], $before)),
    static fn ($r): bool => is_array($r) && ($r['method'] ?? '') === 'GET' && str_contains((string) ($r['path'] ?? ''), 'prod_')
);
$t->check('and downloads no picture again', 0, count($reads));
$t->check('a new picture is picked up', true, (function () use ($bucket, $source, $run, $s3Env, $mirrorDir): bool {
    $bucket->put('prod_20260101_000002_bbbbbbbb.webp', $source, 'image/webp', App\Storage\StorageKey::cacheControl('prod_20260101_000002_bbbbbbbb.webp'));
    $run('backup.php', [], $s3Env);
    return is_file("{$mirrorDir}/prod_20260101_000002_bbbbbbbb.webp");
})());

$t->check('a picture deleted from the bucket by mistake', true, $bucket->delete($pictureA));
$third = $run('backup.php', [], $s3Env);
$t->ok('stays in the copy', is_file("{$mirrorDir}/{$pictureA}"));
$t->check('and the report says so', 1, $newestManifest()['pictures_mirror']['only_in_copy'] ?? null);
$back = $run('migrate_uploads.php', ['--apply', "--from={$mirrorDir}", "--keys={$pictureA}"], $s3Env);
$t->check('it can be put back', [0, true], [$back['code'], $bucket->exists($pictureA)]);
$t->check('identical', file_get_contents($source), $bucket->read($pictureA));
$folders = glob("{$mirrorBackups}/2*", GLOB_ONLYDIR) ?: [];
sort($folders);
$all = $run('migrate_uploads.php', ['--apply', '--all', '--backup=' . end($folders), "--from={$mirrorDir}"], $s3Env);
$t->ok('a whole bucket can be refilled from it, using a backup made with pictures in a bucket', $all['code'] === 0, $all['out']);
$t->ok('backup_to_bucket ignores the copy and picks the dated backup', str_contains($copy([], $s3Env)['out'], basename((string) end($folders))));

$offBackups = "{$fake->dir}/off-backups";
@mkdir($offBackups, 0700, true);
$offEnv = $fake->envFile('env-off', array_merge($fake->envLines(), ["BACKUP_DIR={$offBackups}", 'BACKUP_PICTURES=false']));
$off = $run('backup.php', [], $offEnv);
$t->ok('BACKUP_PICTURES=false: the database is still backed up, no pictures copied, and it says so', $off['code'] === 0 && !is_dir("{$offBackups}/pictures") && str_contains($off['out'], 'BACKUP_PICTURES=false'), $off['out']);

$fake->mode('500');
$broken = $run('backup.php', [], $s3Env);
$fake->mode('ok');
$t->ok('the bucket failing: the database backup still succeeds', $broken['code'] === 0, $broken['out']);
$t->ok('with a clear warning', str_contains($broken['out'], 'picture copy is incomplete'), $broken['out']);
$bucket->delete($pictureA);
$bucket->delete($pictureB);
$bucket->delete('prod_20260101_000002_bbbbbbbb.webp');

// -----------------------------------------------------------------------------
$t->section('Bucket problems are clear and never show the keys');
$r = $copy([], $fake->envFile('env-nos3', ["BACKUP_DIR={$backups}", 'S3_ENDPOINT=', 'S3_BUCKET=', 'S3_ACCESS_KEY_ID=', 'S3_SECRET_ACCESS_KEY=']));
$t->ok('no bucket settings: says which are needed', $r['code'] === 1 && str_contains($r['out'], 'S3_ENDPOINT') && str_contains($r['out'], 'Nothing was copied'), $r['out']);
$makeBackup('2099-04-01_000000', 'SELECT 4;');
$r = $copy([], $fake->envFile('env-wrong', array_merge($fake->envLines(), ["BACKUP_DIR={$backups}", "S3_SECRET_ACCESS_KEY={$fake->secret}x"])));
$t->ok('a wrong secret: refused, saying which settings to check', $r['code'] === 1 && str_contains($r['out'], '403') && str_contains($r['out'], 'S3_SECRET_ACCESS_KEY'), $r['out']);
$fake->mode('500');
$r = $copy();
$t->ok('the provider failing: stops and says to run it again', $r['code'] === 1 && str_contains($r['out'], 'Run this again'), $r['out']);
$fake->mode('ok');
$r = $copy();
$t->ok('and the next run finishes the copy', $r['code'] === 0 && $object('backups/2099-04-01_000000/manifest.json') !== null, $r['out']);
$t->ok('no output ever contained the access key or secret', !str_contains($allOutput, $fake->secret) && !str_contains($allOutput, $fake->access));

exit($t->finish());
