<?php

declare(strict_types=1);

/**
 * Copy a backup made by scripts/backup.php to the S3-compatible bucket (Cloudflare R2), so a
 * lost server costs at most a day of orders. Run it right after backup.php:
 *
 *   php backend/scripts/backup_to_bucket.php                       the newest backup
 *   php backend/scripts/backup_to_bucket.php --dir=2026-11-02_023000   one particular backup
 *   cron (daily 02:30):  30 2 * * *  php .../backup.php && php .../backup_to_bucket.php
 *
 * The files go to <BACKUP_S3_PREFIX>/<backup folder>/ (default "backups/"), beside the pictures
 * but never inside their folder (S3_PREFIX, default "uploads/"): the shop lists and serves only
 * that folder, so a backup is never shown as a picture, never offered for clean-up and never
 * downloadable from the shop. The bucket itself stays private.
 *
 * Every file is checked against the backup's manifest.json before it is sent (a half-written or
 * damaged backup is refused) and its size is checked in the bucket afterwards. manifest.json goes
 * last, so a backup folder in the bucket that has its manifest is complete. Files already in the
 * bucket with the same size are left as they are, so running it twice is safe. Nothing is ever
 * deleted, here or in the bucket: remove old backups yourself (R2 dashboard), after checking.
 *
 * Settings (backend/.env): S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_ACCESS_KEY_ID,
 * S3_SECRET_ACCESS_KEY (the same as for the pictures), optional BACKUP_S3_PREFIX and BACKUP_DIR.
 * Works whatever STORAGE_DRIVER is. Restore: download the folder from the bucket, then
 * docs/BACKUPS.md.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/src/autoload.php';

use App\Config\Env;
use App\Storage\S3Storage;
use App\Storage\StorageError;
use App\Storage\StorageKey;

Env::load(BASE_PATH . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Africa/Lagos') ?: 'Africa/Lagos');

// One PUT per file, read into memory: plenty for a database dump. Bigger files (a disk picture
// archive of a large shop) are better copied with the provider's own tools (rclone).
const MAX_FILE_BYTES = 1024 * 1024 * 1024;
const FOLDER_PATTERN = '/^\d{4}-\d{2}-\d{2}_\d{6}$/';

$fail = static function (string $message): never {
    fwrite(STDERR, "[backup_to_bucket] {$message}\n");
    exit(1);
};

// 1. Which backup --------------------------------------------------------------------------
$root = rtrim((string) (Env::get('BACKUP_DIR', '') ?: BASE_PATH . '/storage/backups'), '/\\');
$chosen = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $chosen = substr($arg, 6);
    } else {
        $fail("Unknown option {$arg}. Use --dir=<backup folder> or nothing for the newest backup.");
    }
}
if ($chosen === null) {
    $folders = array_values(array_filter(
        glob($root . '/*', GLOB_ONLYDIR) ?: [],
        static fn (string $d): bool => preg_match(FOLDER_PATTERN, basename($d)) === 1 && is_file("{$d}/manifest.json")
    ));
    sort($folders);
    if ($folders === []) {
        $fail("No finished backup in {$root} (run scripts/backup.php first).");
    }
    $folder = end($folders);
} else {
    $folder = is_dir($chosen) ? $chosen : $root . '/' . $chosen;
    if (!is_dir($folder)) {
        $fail("No backup folder {$chosen} in {$root}.");
    }
}
$folder = rtrim($folder, '/\\');
$name = basename($folder);
if (preg_match(FOLDER_PATTERN, $name) !== 1) {
    $fail("{$name} is not a backup folder made by scripts/backup.php (YYYY-mm-dd_HHMMSS).");
}

// 2. Check the backup against its manifest -------------------------------------------------
$manifest = json_decode((string) @file_get_contents("{$folder}/manifest.json"), true);
if (!is_array($manifest) || !is_array($manifest['database_dump'] ?? null)) {
    $fail("{$name}/manifest.json is missing or unreadable: the backup did not finish. Nothing was copied.");
}
$files = [];
foreach (['database_dump', 'uploads_archive'] as $part) {
    $entry = $manifest[$part] ?? null;
    if (!is_array($entry)) {
        continue;
    }
    $file = (string) ($entry['file'] ?? '');
    $path = "{$folder}/{$file}";
    if (!StorageKey::isValid($file) || str_contains($file, '/') || !is_file($path)) {
        $fail("{$name}: {$file} (listed in manifest.json) is missing. Nothing was copied.");
    }
    if (filesize($path) !== (int) ($entry['bytes'] ?? -1) || hash_file('sha256', $path) !== (string) ($entry['sha256'] ?? '')) {
        $fail("{$name}: {$file} does not match manifest.json (damaged or changed). Nothing was copied.");
    }
    if (filesize($path) > MAX_FILE_BYTES) {
        $fail("{$name}: {$file} is over 1 GB; copy it with the provider's own tools (rclone). Nothing was copied.");
    }
    $files[] = $file;
}
$files[] = 'manifest.json'; // last: its presence in the bucket means the copy is complete

// 3. The bucket, in its own folder away from the pictures ----------------------------------
$prefix = trim((string) (Env::get('BACKUP_S3_PREFIX', 'backups') ?: 'backups'), '/');
$pictures = trim((string) (Env::get('S3_PREFIX', 'uploads/') ?? 'uploads/'), '/');
$overlaps = static fn (string $a, string $b): bool => str_starts_with("{$a}/", "{$b}/");
if (!StorageKey::isValid($prefix)) {
    $fail('BACKUP_S3_PREFIX is not a valid folder name.');
}
if ($pictures === '' || $overlaps($prefix, $pictures) || $overlaps($pictures, $prefix)) {
    $fail('Backups need a bucket folder of their own, separate from the pictures (BACKUP_S3_PREFIX vs S3_PREFIX), or the shop would list them as pictures. Nothing was copied.');
}
try {
    $bucket = S3Storage::fromEnv($prefix . '/');
} catch (StorageError $e) {
    $fail($e->getMessage() . ' Nothing was copied.');
}

// 4. Copy -----------------------------------------------------------------------------------
$sent = $kept = [];
try {
    foreach ($files as $file) {
        $path = "{$folder}/{$file}";
        $key = "{$name}/{$file}";
        $size = (int) filesize($path);
        $there = $bucket->head($key);
        if ($there !== null && $there['size'] === $size) {
            $kept[] = $file;
            continue;
        }
        $type = str_ends_with($file, '.json') ? 'application/json' : 'application/gzip';
        $bucket->put($key, $path, $type, 'private, no-store');
        $check = $bucket->head($key);
        if ($check === null || $check['size'] !== $size) {
            $fail("{$key}: the bucket does not have the full file after sending it. Run this again.");
        }
        $sent[] = sprintf('%s (%.1f KB)', $file, $size / 1024);
    }
} catch (StorageError $e) {
    $hint = preg_match('/\b403\b|SignatureDoesNotMatch|AccessDenied|InvalidAccessKeyId/', $e->getMessage())
        ? ' Check S3_ACCESS_KEY_ID and S3_SECRET_ACCESS_KEY, and that the key may read and write this bucket.'
        : ' Run this again; files already in the bucket are kept.';
    $fail("Copying {$name} stopped: " . $e->getMessage() . $hint);
}

printf(
    "[%s] backup %s is in the bucket under %s/%s/: %s%s\n",
    date('Y-m-d H:i:s'),
    $name,
    $prefix,
    $name,
    $sent === [] ? 'nothing new to send' : 'sent ' . implode(', ', $sent),
    $kept === [] ? '' : '; already there: ' . implode(', ', $kept)
);
