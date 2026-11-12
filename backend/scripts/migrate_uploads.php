<?php

declare(strict_types=1);

/**
 * Copy pictures between the server's disk and the S3-compatible bucket (Cloudflare R2), and prove
 * each copy: same bytes (SHA-256), same size, content type and caching in the bucket, and, with
 * --verify-url, the shop's own /uploads/<key> link serving the same picture. Nothing is ever
 * deleted or moved: the originals stay where they are, and a file that already exists at the
 * destination with different content is left alone and reported. Running it again is safe;
 * files already copied are only checked again.
 *
 * The shop keeps using STORAGE_DRIVER as it is: this script only needs the S3_* settings. Switch
 * STORAGE_DRIVER=s3 only after every picture has been copied and checked (docs/STORAGE.md).
 *
 * Usage:
 *   php backend/scripts/migrate_uploads.php                     plan only: lists what would be copied
 *   php backend/scripts/migrate_uploads.php --apply             copy a test batch of 5 (pictures in use first)
 *   php backend/scripts/migrate_uploads.php --apply --limit=10  a batch of 10
 *   php backend/scripts/migrate_uploads.php --apply --keys=a.webp,catalog/b.webp
 *   php backend/scripts/migrate_uploads.php --apply --all --backup=<backup folder>
 *       every picture; needs a backup made by scripts/backup.php that still checks out
 *   --verify-url=<API address>  also fetch <address>/uploads/<key> from an API running with
 *                               STORAGE_DRIVER=s3 and compare
 *   --to=local                  copy back from the bucket to the disk (rollback after a switch)
 *   --from=<folder>             take pictures from this folder instead of the uploads folder, such
 *                               as the backup's copy, to put back a deleted one:
 *                               --apply --from=backend/storage/backups/pictures --keys=<name>
 *
 * Each copying run writes a report to backend/storage/migrations/ (or MIGRATION_REPORT_DIR).
 * Exit code 1 when anything failed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/src/autoload.php';

use App\Config\Env;
use App\Database\Database;
use App\Storage\LocalStorage;
use App\Storage\ObjectStorage;
use App\Storage\S3Storage;
use App\Storage\Storage;
use App\Storage\StorageKey;
use App\Support\UploadFiles;

Env::load(BASE_PATH . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Africa/Lagos') ?: 'Africa/Lagos');

const PICTURE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

$fail = static function (string $message): never {
    fwrite(STDERR, "[migrate_uploads] {$message}\n");
    exit(1);
};

$options = getopt('', ['apply', 'all', 'limit:', 'keys:', 'backup:', 'verify-url:', 'to:', 'from:']);
$apply = isset($options['apply']);
$all = isset($options['all']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 5;
$keys = isset($options['keys']) ? array_values(array_filter(array_map('trim', explode(',', (string) $options['keys'])))) : null;
$verifyUrl = isset($options['verify-url']) ? rtrim((string) $options['verify-url'], '/') : null;
$direction = (string) ($options['to'] ?? 's3');
if (!in_array($direction, ['s3', 'local'], true)) {
    $fail('--to must be s3 or local.');
}
if ($limit < 1) {
    $fail('--limit must be at least 1.');
}
foreach ($keys ?? [] as $key) {
    if (!StorageKey::isValid($key)) {
        $fail("Not a valid picture name: {$key}");
    }
}

// A full copy needs a backup that still matches its manifest.
if ($apply && $all) {
    $backup = rtrim((string) ($options['backup'] ?? ''), '/\\');
    $manifest = $backup !== '' ? json_decode((string) @file_get_contents("{$backup}/manifest.json"), true) : null;
    if (!is_array($manifest)) {
        $fail('--all needs --backup=<folder made by scripts/backup.php> (its manifest.json was not found).');
    }
    foreach (['database_dump', 'uploads_archive'] as $part) {
        $entry = $manifest[$part] ?? null;
        // A backup made while pictures were in a bucket has no pictures archive, by design.
        if ($part === 'uploads_archive' && $entry === null && ($manifest['uploads_storage'] ?? 'local') !== 'local') {
            continue;
        }
        $path = is_array($entry) ? "{$backup}/" . basename((string) $entry['file']) : '';
        if ($path === '' || !is_file($path) || hash_file('sha256', $path) !== ($entry['sha256'] ?? '')) {
            $fail("The backup's {$part} is missing or does not match its manifest; make a new backup first.");
        }
    }
    echo "Backup checked: {$backup} (made {$manifest['created_at']}).\n";
}

try {
    $from = isset($options['from']) ? rtrim((string) $options['from'], '/\\') : null;
    if ($from !== null && !is_dir($from)) {
        $fail("--from: no such folder: {$from}");
    }
    $local = new LocalStorage($from ?? Storage::localRoot());
    $bucket = S3Storage::fromEnv();
} catch (Throwable $e) {
    $fail($e->getMessage());
}
[$source, $target] = $direction === 's3' ? [$local, $bucket] : [$bucket, $local];

// Where each picture is used (pictures in use are copied first, and the report names them).
try {
    $usage = UploadFiles::usage(Database::getInstance());
} catch (Throwable $e) {
    $usage = [];
    echo "Note: the database could not be read ({$e->getMessage()}); pictures are taken by name.\n";
}

$available = [];
try {
    foreach ($source->list() as $object) {
        $available[$object['key']] = $object;
    }
} catch (Throwable $e) {
    $fail($e->getMessage());
}
if ($keys !== null) {
    $batch = $keys;
} else {
    $names = array_keys($available);
    usort($names, static fn (string $a, string $b): int => [!isset($usage[$a]), $a] <=> [!isset($usage[$b]), $b]);
    $batch = $all ? $names : array_slice($names, 0, $limit);
}

printf(
    "%s: %d picture(s) on %s, %d in use by the shop; %s %d.\n",
    $apply ? 'Copying' : 'Plan only (add --apply to copy)',
    count($available),
    $source->name() === 'local' ? 'the server disk' : 'the bucket',
    count(array_intersect_key($usage, $available)),
    $apply ? 'this run copies' : 'a run would copy',
    count($batch),
);

$results = [];
foreach ($batch as $key) {
    $row = ['key' => $key, 'used_by' => $usage[$key] ?? [], 'status' => 'planned', 'checks' => []];
    if (!isset($available[$key])) {
        $row['status'] = 'failed';
        $row['error'] = 'not found at the source';
        $results[] = $row;
        continue;
    }
    if (!$apply) {
        $row['size'] = $available[$key]['size'];
        $results[] = $row;
        continue;
    }
    try {
        $row = copyOne($source, $target, $key, $verifyUrl) + $row;
    } catch (Throwable $e) {
        $row['status'] = 'failed';
        $row['error'] = $e->getMessage();
    }
    $results[] = $row;
}

foreach ($results as $row) {
    $detail = $row['error'] ?? implode(', ', array_map(static fn ($k, $v) => $k . ($v ? ' ok' : ' FAILED'), array_keys($row['checks']), $row['checks']));
    printf("  %-10s %s%s%s\n", $row['status'], $row['key'], $row['used_by'] !== [] ? ' (' . implode(', ', $row['used_by']) . ')' : '', $detail !== '' ? " - {$detail}" : '');
}

$counts = array_count_values(array_column($results, 'status'));
$failed = $counts['failed'] ?? 0;
$targetCount = null;
if ($apply) {
    try {
        $targetCount = iterator_count($target->list());
    } catch (Throwable $e) {
        echo "Note: the destination could not be counted ({$e->getMessage()}).\n";
    }
}
$report = [
    'created_at' => date('c'),
    'direction' => $direction === 's3' ? 'disk to bucket' : 'bucket to disk',
    'applied' => $apply,
    'source_count' => count($available),
    'target_count' => $targetCount,
    'counts' => $counts,
    'files' => $results,
];
if ($apply) {
    $dir = rtrim((string) (Env::get('MIGRATION_REPORT_DIR', '') ?: BASE_PATH . '/storage/migrations'), '/\\');
    @mkdir($dir, 0750, true);
    $file = $dir . '/' . date('Y-m-d_His') . "-to-{$direction}.json";
    file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    printf(
        "Done: %d copied, %d already there, %d failed. %s now holds %s picture(s); the originals were not touched.\nReport: %s\n",
        $counts['copied'] ?? 0,
        $counts['already there'] ?? 0,
        $failed,
        $direction === 's3' ? 'The bucket' : 'The disk',
        $targetCount ?? 'an unknown number of',
        $file,
    );
}
exit($failed > 0 ? 1 : 0);

/**
 * Copy one picture (unless the same file is already there) and check the copy.
 *
 * @return array{status: string, size: int, sha256: string, content_type: string, checks: array<string, bool>, error?: string}
 */
function copyOne(ObjectStorage $source, ObjectStorage $target, string $key, ?string $verifyUrl): array
{
    $bytes = $source->read($key);
    if ($bytes === null) {
        throw new RuntimeException('not found at the source');
    }
    $type = (string) (@getimagesizefromstring($bytes)['mime'] ?? 'unknown');
    if (!in_array($type, PICTURE_TYPES, true)) {
        return ['status' => 'skipped', 'size' => strlen($bytes), 'sha256' => '', 'content_type' => $type, 'checks' => [], 'error' => "not a picture ({$type})"];
    }
    $sha = hash('sha256', $bytes);
    $cacheControl = StorageKey::cacheControl($key);

    $existing = $target->read($key);
    if ($existing !== null && hash('sha256', $existing) !== $sha) {
        return ['status' => 'failed', 'size' => strlen($bytes), 'sha256' => $sha, 'content_type' => $type, 'checks' => [], 'error' => 'a different file with this name is already at the destination; left alone'];
    }
    $status = 'already there';
    if ($existing === null) {
        $tmp = tempnam(sys_get_temp_dir(), 'shopco-migrate-');
        try {
            file_put_contents($tmp, $bytes);
            $target->put($key, $tmp, $type, $cacheControl);
        } finally {
            @unlink($tmp);
        }
        $status = 'copied';
    }

    $checks = ['content' => hash('sha256', (string) $target->read($key)) === $sha];
    if ($target instanceof S3Storage) {
        $head = $target->head($key);
        $checks['size'] = ($head['size'] ?? -1) === strlen($bytes);
        $checks['type'] = ($head['content_type'] ?? '') === $type;
        $checks['caching'] = ($head['cache_control'] ?? '') === $cacheControl;
    }
    if ($verifyUrl !== null) {
        $ch = curl_init($verifyUrl . '/uploads/' . $key);
        $source = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$source): int {
                if (stripos($line, 'X-Upload-Source:') === 0) {
                    $source = strtolower(trim(substr($line, 16)));
                }
                return strlen($line);
            },
        ]);
        $served = curl_exec($ch);
        $ok = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 && is_string($served) && hash('sha256', $served) === $sha
            && str_starts_with((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE), $type);
        curl_close($ch);
        // While the original is still on the disk the web server may hand that out before the shop's
        // code runs; then the link check proves the link, not the bucket (the bucket copy itself was
        // read back and compared above).
        $checks[$source === 's3' ? 'link' : 'link (answered by the disk copy, not the bucket)'] = $ok;
    }
    $result = ['status' => $status, 'size' => strlen($bytes), 'sha256' => $sha, 'content_type' => $type, 'checks' => $checks];
    if (in_array(false, $checks, true)) {
        $result['status'] = 'failed';
        $result['error'] = 'copied, but these checks failed: ' . implode(', ', array_keys(array_filter($checks, static fn (bool $v): bool => !$v)));
    }
    return $result;
}
