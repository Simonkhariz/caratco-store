<?php

declare(strict_types=1);

/**
 * Back up the database and the uploaded pictures into one dated folder:
 *
 *   <BACKUP_DIR>/<YYYY-mm-dd_His>/database.sql.gz   (mysqldump, one consistent snapshot)
 *   <BACKUP_DIR>/<YYYY-mm-dd_His>/uploads.tar.gz    (everything in the uploads folder)
 *   <BACKUP_DIR>/<YYYY-mm-dd_His>/manifest.json     (sizes and SHA-256 of both files)
 *   <BACKUP_DIR>/pictures/                          (pictures in a bucket: a growing copy, below)
 *
 * Pictures in an S3-compatible bucket are not archived each night: only new or changed ones are
 * copied into one growing folder, and nothing is ever removed from it, so a picture deleted from
 * the bucket by mistake can be put back (migrate_uploads.php --from=<that folder> --keys=<name>).
 * BACKUP_PICTURES=false leaves it out, for hosting with tight disk or file limits.
 *
 * BACKUP_DIR defaults to backend/storage/backups. Old backups are never deleted by this script:
 * copy them off the server (another machine, or a separate MinIO/S3 bucket) and remove old ones
 * there. mysqldump is found on PATH, in XAMPP's folder, or at MYSQLDUMP_PATH (its password goes
 * in the environment, never on the command line). Where PHP may not run programs (common on
 * cPanel) or mysqldump is missing, PHP writes the same consistent dump itself; manifest.json
 * records which way was used.
 *
 * Usage:
 *   php backend/scripts/backup.php
 *   php backend/scripts/backup.php --php-dump      (always the PHP way)
 *   cron (daily 02:30):  30 2 * * *  php /var/www/shopco/backend/scripts/backup.php
 *
 * Restore (see docs/BACKUPS.md): php backend/scripts/restore_database.php (no shell tools
 * needed) or gunzip < database.sql.gz | mysql <database>; then unpack uploads.tar.gz into the
 * uploads folder.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/src/autoload.php';

use App\Config\Env;
use App\Storage\LocalStorage;
use App\Storage\ObjectStorage;
use App\Storage\PictureMirror;
use App\Storage\Storage;
use App\Support\GzipFile;
use App\Support\SqlDump;

Env::load(BASE_PATH . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Africa/Lagos') ?: 'Africa/Lagos');

$fail = static function (string $message): never {
    fwrite(STDERR, "[backup] {$message}\n");
    exit(1);
};
// Some hosts build PHP without these; fail before creating anything, with the reason.
if (!function_exists('gzopen')) {
    $fail('PHP\'s zlib extension is not enabled; it is needed to compress the backup.');
}
if (Storage::uploads() instanceof LocalStorage && !class_exists('PharData')) {
    $fail('PHP\'s phar extension is not enabled; it is needed to archive the pictures.');
}

$root = rtrim((string) (Env::get('BACKUP_DIR', '') ?: BASE_PATH . '/storage/backups'), '/\\');
$target = $root . '/' . date('Y-m-d_His');
if (!@mkdir($target, 0750, true) && !is_dir($target)) {
    $fail("Cannot create {$target}.");
}

// 1. Database -----------------------------------------------------------------------------
// mysqldump when PHP may run it; otherwise (shared/cPanel hosts often disable proc_open, or have
// no mysqldump) the same consistent snapshot written by PHP itself (App\Support\SqlDump).
// --php-dump forces the PHP way.
$database = (string) Env::get('DB_DATABASE', '');
if ($database === '') {
    $fail('DB_DATABASE is not set.');
}
$dbFile = "{$target}/database.sql.gz";
$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
$canRun = function_exists('proc_open') && !in_array('proc_open', $disabled, true);
$method = in_array('--php-dump', $argv, true) || !$canRun ? 'php' : 'mysqldump';
$note = $canRun ? '' : ' (this host does not let PHP run programs)';
$tableRows = null; // rows per table, known when PHP writes the dump (restore_database.php checks them)

if ($method === 'mysqldump') {
    $mysqldump = (string) Env::get('MYSQLDUMP_PATH', '');
    if ($mysqldump === '') {
        $mysqldump = PHP_OS_FAMILY === 'Windows' && is_file('C:/xampp/mysql/bin/mysqldump.exe') ? 'C:/xampp/mysql/bin/mysqldump.exe' : 'mysqldump';
    }
    $command = [
        $mysqldump,
        '--host=' . Env::get('DB_HOST', '127.0.0.1'),
        '--port=' . Env::int('DB_PORT', 3306),
        '--user=' . Env::get('DB_USERNAME', 'root'),
        '--single-transaction',      // one consistent snapshot without locking the shop
        '--quick',
        '--routines',
        '--triggers',
        '--default-character-set=utf8mb4',
        $database,
    ];
    $env = array_merge(getenv(), ['MYSQL_PWD' => (string) Env::get('DB_PASSWORD', '')]);
    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, $env);
    if (!is_resource($process)) {
        $method = 'php';
        $note = " (mysqldump could not be started: {$mysqldump})";
    } else {
        $writeError = null;
        $dumped = 0;
        try {
            $gz = new GzipFile($dbFile);
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 1 << 20);
                if ($chunk === false) {
                    break;
                }
                $dumped += strlen($chunk);
                $gz->write($chunk);
            }
            $gz->close();
        } catch (RuntimeException $e) {
            $writeError = $e->getMessage();
        }
        if ($writeError !== null) {
            // Stop mysqldump first: it may be waiting for this script to read more.
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($process);
            proc_close($process);
            @unlink($dbFile);
            $fail('The database dump failed: ' . $writeError);
        }
        $errors = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code === 127 || ($code === 1 && $dumped === 0 && preg_match('/not (found|recognized)/i', $errors))) {
            // The program is not installed here: the PHP way works anywhere PHP reaches MySQL.
            @unlink($dbFile);
            $method = 'php';
            $note = ' (mysqldump is not installed here; set MYSQLDUMP_PATH to use it)';
        } elseif ($code !== 0 || $dumped === 0) {
            @unlink($dbFile);
            $fail("mysqldump failed (exit {$code}): " . mb_substr($errors, 0, 300));
        }
    }
}

if ($method === 'php') {
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        // A connection of its own: the dump holds one transaction open while it reads.
        $conn = new mysqli(
            (string) Env::get('DB_HOST', '127.0.0.1'),
            (string) Env::get('DB_USERNAME', 'root'),
            (string) Env::get('DB_PASSWORD', ''),
            $database,
            Env::int('DB_PORT', 3306)
        );
        $conn->set_charset('utf8mb4');
        $gz = new GzipFile($dbFile);
        $tableRows = SqlDump::dump($conn, static function (string $sql) use ($gz): void {
            $gz->write($sql);
        });
        $gz->close();
        $conn->close();
    } catch (Throwable $e) {
        @unlink($dbFile);
        $fail('The database dump failed: ' . $e->getMessage());
    }
}

// 2. Uploaded pictures ----------------------------------------------------------------------
// On the server's disk they are archived here; from an S3-compatible bucket, new and changed
// ones are copied to <BACKUP_DIR>/pictures. A problem there is reported but never costs the
// database backup, which is written by now.
$storage = Storage::uploads();
$uploads = $storage instanceof LocalStorage ? $storage->root() : null;
$uploadsFile = "{$target}/uploads.tar.gz";
$files = 0;
if ($uploads !== null && is_dir($uploads)) {
    $tarPath = "{$target}/uploads.tar";
    try {
        $tar = new PharData($tarPath);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                $tar->addFile($file->getPathname(), str_replace('\\', '/', substr($file->getPathname(), strlen($uploads) + 1)));
                $files++;
            }
        }
        if ($files > 0) {
            $tar->compress(Phar::GZ);
        }
        unset($tar);
        // An empty uploads folder (a new shop) writes no archive at all: nothing to remove.
        if (is_file($tarPath)) {
            Phar::unlinkArchive($tarPath);
        }
    } catch (Throwable $e) {
        $fail('Could not archive the uploads folder: ' . $e->getMessage());
    }
}

$mirror = null;
if ($uploads === null && $storage instanceof ObjectStorage && Env::bool('BACKUP_PICTURES', true)) {
    $mirror = PictureMirror::sync($storage, "{$root}/pictures");
    $mirror['failed_count'] = count($mirror['failed']);
}

// 3. Manifest -------------------------------------------------------------------------------
$entry = static fn (string $path): ?array => is_file($path)
    ? ['file' => basename($path), 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)]
    : null;
$manifest = [
    'created_at' => date('c'),
    'database' => $database,
    'database_dump' => $entry($dbFile),
    'database_dump_method' => $method,
    'table_rows' => $tableRows,
    'uploads_archive' => $entry($uploadsFile),
    'upload_files' => $files,
    'uploads_storage' => $storage->name(),
    'pictures_mirror' => $mirror === null ? null : [
        'folder' => 'pictures',
        'in_bucket' => $mirror['in_bucket'],
        'copied' => $mirror['copied'],
        'only_in_copy' => $mirror['only_here'],
        'failed' => $mirror['failed_count'],
    ],
];
if (file_put_contents("{$target}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
    @unlink("{$target}/manifest.json");
    $fail("Could not write {$target}/manifest.json (is the disk full?).");
}

printf(
    "[%s] backup written to %s: database %.1f KB (%s%s), %d picture files (%.1f KB)\n",
    date('Y-m-d H:i:s'),
    $target,
    filesize($dbFile) / 1024,
    $method === 'php' ? 'written by PHP' : 'mysqldump',
    $note,
    $files,
    is_file($uploadsFile) ? filesize($uploadsFile) / 1024 : 0
);
if ($uploads === null && $mirror === null) {
    echo "[backup] pictures in the bucket were not copied (BACKUP_PICTURES=false)\n";
}
if ($mirror !== null) {
    printf(
        "[backup] pictures in the bucket: %d, of which %d new or changed copied to %s/pictures%s\n",
        $mirror['in_bucket'],
        $mirror['copied'],
        $root,
        $mirror['only_here'] > 0 ? "; {$mirror['only_here']} in the copy are no longer in the bucket (kept)" : ''
    );
    if ($mirror['error'] !== null || $mirror['failed'] !== []) {
        $detail = $mirror['error'] ?? ($mirror['failed_count'] . ' could not be copied: ' . implode(', ', array_slice($mirror['failed'], 0, 5)));
        fwrite(STDERR, "[backup] WARNING: the picture copy is incomplete (the database backup is fine; the next run tries again). {$detail}\n");
    }
}
