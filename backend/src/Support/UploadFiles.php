<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Env;
use App\Database\Database;
use App\Storage\Storage;
use App\Storage\StorageKey;

/**
 * Picture files nothing uses any more: replaced product photos, banners that were changed, uploads
 * from forms that were never saved. Unused files are only ever listed for the admin to review in
 * Data & storage and removed when the admin confirms the clean-up (with password and emailed code,
 * like every other clean-up); never automatically. Works on whichever storage holds the pictures
 * (App\Storage\Storage); a file is identified as "uploads/<key>".
 *
 * UPLOAD_DIR overrides the local folder (the test runner points it at a throw-away folder). In
 * testing without it, nothing is listed or removed, so a test can never touch real pictures.
 */
final class UploadFiles
{
    /** Files changed within this time are left alone: an admin may still be filling in a form. */
    private const MIN_AGE_SECONDS = 86400;

    /** Largest picture the shop accepts. */
    public const MAX_PICTURE_BYTES = 5 * 1024 * 1024;

    /**
     * The largest upload PHP on this server lets through: the smaller of upload_max_filesize and
     * post_max_size (0 = no limit). Many hosts default to 2 MB, below the shop's own 5 MB.
     */
    public static function serverLimitBytes(): int
    {
        $limits = array_filter(
            [self::iniBytes((string) ini_get('upload_max_filesize')), self::iniBytes((string) ini_get('post_max_size'))],
            static fn (int $b): bool => $b > 0
        );
        return $limits === [] ? 0 : min($limits);
    }

    /** "8M", "512K", "1G" or bytes, as php.ini writes sizes. */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(\d+)\s*([KMG]?)/i', $value, $m)) {
            return 0;
        }
        return (int) $m[1] * match (strtoupper($m[2])) {
            'G' => 1 << 30,
            'M' => 1 << 20,
            'K' => 1 << 10,
            default => 1,
        };
    }

    /** "2 MB" for messages. */
    public static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / (1 << 20), 1, '.', ''), '0'), '.') . ' MB';
    }

    /** The local uploads folder (used for the disk storage, backups and System status). */
    public static function directory(): string
    {
        return Storage::localRoot();
    }

    /** Listing and removing unused files is off in tests unless they use their own folder. */
    private static function guarded(): bool
    {
        return Env::get('APP_ENV', 'production') === 'testing' && trim((string) Env::get('UPLOAD_DIR', '')) === '';
    }

    /**
     * Files ("uploads/<key>") older than a day before $asOf that no product, banner, category or
     * store setting refers to, oldest first.
     *
     * @return list<string>
     */
    public static function unused(Database $db, string $asOf): array
    {
        if (self::guarded()) {
            return [];
        }
        $cutoff = (strtotime($asOf) ?: time()) - self::MIN_AGE_SECONDS;
        $used = self::referenced($db);

        $found = [];
        foreach (Storage::uploads()->list() as $object) {
            if (isset($used[$object['key']]) || $object['modified'] > $cutoff) {
                continue;
            }
            $found['uploads/' . $object['key']] = $object['modified'];
        }
        asort($found);
        return array_keys($found);
    }

    /**
     * What the admin sees for each file.
     *
     * @param list<string> $paths
     * @return list<array{file: string, size_kb: float, modified: string}>
     */
    public static function describe(array $paths): array
    {
        $wanted = array_flip(array_filter(array_map([self::class, 'keyOf'], $paths)));
        $found = [];
        foreach (Storage::uploads()->list() as $object) {
            if (isset($wanted[$object['key']])) {
                $found[$object['key']] = $object;
            }
        }
        $out = [];
        foreach ($paths as $path) {
            $key = self::keyOf($path);
            if ($key !== null && isset($found[$key])) {
                $out[] = [
                    'file' => '/' . $path,
                    'size_kb' => round($found[$key]['size'] / 1024, 1),
                    'modified' => date('Y-m-d H:i:s', $found[$key]['modified']),
                ];
            }
        }
        return $out;
    }

    /**
     * Delete the given files from the storage. Returns how many were removed.
     *
     * @param list<string> $paths
     */
    public static function delete(array $paths): int
    {
        if (self::guarded()) {
            return 0;
        }
        $storage = Storage::uploads();
        $removed = 0;
        foreach ($paths as $path) {
            $key = self::keyOf($path);
            if ($key !== null && $storage->delete($key)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** The storage key in "uploads/<key>", or null when it is not a valid key. */
    private static function keyOf(string $path): ?string
    {
        if (!str_starts_with($path, 'uploads/')) {
            return null;
        }
        $key = substr($path, strlen('uploads/'));
        return StorageKey::isValid($key) ? $key : null;
    }

    /** @return array<string, true> every storage key the database refers to */
    private static function referenced(Database $db): array
    {
        return array_map(static fn (): bool => true, self::usage($db));
    }

    /**
     * Every storage key the database refers to, with where it is used ("product_images",
     * "banners", "categories", "store_settings"), for the media migration report.
     *
     * @return array<string, list<string>>
     */
    public static function usage(Database $db): array
    {
        $sources = [
            'product_images' => "SELECT `image_url` AS v FROM `product_images` WHERE `image_url` LIKE '%/uploads/%'",
            'banners' => "SELECT `image_url` AS v FROM `banners` WHERE `image_url` LIKE '%/uploads/%'",
            'categories' => "SELECT `image_url` AS v FROM `categories` WHERE `image_url` LIKE '%/uploads/%'",
            'store_settings' => "SELECT `value` AS v FROM `store_settings` WHERE `value` LIKE '%/uploads/%'",
        ];
        $used = [];
        foreach ($sources as $source => $query) {
            foreach (array_column($db->fetchAll($query), 'v') as $value) {
                if (preg_match_all('#/uploads/[A-Za-z0-9_./-]+#', (string) $value, $m)) {
                    foreach ($m[0] as $path) {
                        $key = StorageKey::fromPublicPath($path);
                        if ($key !== null && !in_array($source, $used[$key] ?? [], true)) {
                            $used[$key][] = $source;
                        }
                    }
                }
            }
        }
        return $used;
    }
}
