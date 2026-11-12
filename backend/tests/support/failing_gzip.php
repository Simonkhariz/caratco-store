<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Loaded with php -d auto_prepend_file=... by BackupBucketTest: these replace gzwrite and gzclose
 * inside App\Support, so scripts/backup.php meets a full disk without one being needed.
 * FAKE_GZIP_FAILURE: "short" (a write fails), "lost" (a write claims success but stores nothing,
 * as when only the final flush fails), "close" (closing fails).
 */
function gzwrite($stream, string $data): int|false
{
    return match (getenv('FAKE_GZIP_FAILURE')) {
        'short' => 0,
        'lost' => strlen($data),
        default => \gzwrite($stream, $data),
    };
}

function gzclose($stream): bool
{
    $closed = \gzclose($stream);
    return getenv('FAKE_GZIP_FAILURE') === 'close' ? false : $closed;
}
