<?php

declare(strict_types=1);

namespace App\Storage;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * A growing copy of the pictures in the bucket, kept in the backups folder on the server's disk
 * (scripts/backup.php, nightly). Pictures rarely change, so only new or changed ones are fetched:
 * a dated full copy every night would multiply the storage for nothing.
 *
 * Nothing is ever removed from it, even when a picture has gone from the bucket: that is exactly
 * what makes a picture deleted by mistake recoverable (migrate_uploads.php --from=<this folder>).
 */
final class PictureMirror
{
    /**
     * @return array{in_bucket: int, copied: int, already_here: int, only_here: int, failed: list<string>, error: ?string}
     */
    public static function sync(ObjectStorage $bucket, string $dir): array
    {
        $result = ['in_bucket' => 0, 'copied' => 0, 'already_here' => 0, 'only_here' => 0, 'failed' => [], 'error' => null];
        $root = rtrim($dir, '/\\');
        if (!is_dir($root) && !@mkdir($root, 0750, true) && !is_dir($root)) {
            $result['error'] = "Cannot create {$root}.";
            return $result;
        }

        $seen = [];
        try {
            foreach ($bucket->list() as $object) {
                $key = $object['key'];
                $result['in_bucket']++;
                $seen[$key] = true;
                $path = "{$root}/{$key}";
                if (is_file($path) && filesize($path) === $object['size'] && filemtime($path) >= $object['modified']) {
                    $result['already_here']++;
                    continue;
                }
                self::copy($bucket, $key, $path, $object, $result);
            }
        } catch (Throwable $e) {
            // Whatever was copied stays; the next night carries on from there.
            $result['error'] = $e->getMessage();
            return $result;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $name = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->isFile() && !isset($seen[$name]) && !str_ends_with($name, '.part')) {
                $result['only_here']++;
            }
        }
        return $result;
    }

    /** @param array{key: string, size: int, modified: int} $object */
    private static function copy(ObjectStorage $bucket, string $key, string $path, array $object, array &$result): void
    {
        $body = $bucket->read($key);
        if ($body === null || strlen($body) !== $object['size']) {
            $result['failed'][] = $key;
            return;
        }
        $folder = dirname($path);
        if (!is_dir($folder) && !@mkdir($folder, 0750, true) && !is_dir($folder)) {
            $result['failed'][] = $key;
            return;
        }
        // Whole files only: a crash part-way never leaves half a picture under the real name.
        $temp = "{$path}.part";
        if (@file_put_contents($temp, $body) === false || !@rename($temp, $path)) {
            @unlink($temp);
            $result['failed'][] = $key;
            return;
        }
        if ($object['modified'] > 0) {
            @touch($path, $object['modified']);
        }
        $result['copied']++;
    }
}
