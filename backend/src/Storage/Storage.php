<?php

declare(strict_types=1);

namespace App\Storage;

use App\Config\Env;

/**
 * The storage for uploaded pictures, chosen by STORAGE_DRIVER: "local" (default, the server's
 * disk) or "s3" (an S3-compatible bucket such as Cloudflare R2; see S3Storage). Callers only ever
 * see ObjectStorage.
 */
final class Storage
{
    private static ?ObjectStorage $uploads = null;

    public static function uploads(): ObjectStorage
    {
        if (self::$uploads !== null) {
            return self::$uploads;
        }
        $driver = strtolower(trim((string) Env::get('STORAGE_DRIVER', 'local'))) ?: 'local';
        return self::$uploads = match ($driver) {
            'local' => new LocalStorage(self::localRoot()),
            's3' => S3Storage::fromEnv(),
            default => throw new StorageError("Unknown STORAGE_DRIVER '{$driver}' (expected: local or s3)."),
        };
    }

    /** The folder served at /uploads: backend/public/uploads, or UPLOAD_DIR. */
    public static function localRoot(): string
    {
        $override = trim((string) Env::get('UPLOAD_DIR', ''));
        return $override !== '' ? rtrim(str_replace('\\', '/', $override), '/') : BASE_PATH . '/public/uploads';
    }

    /** Tests only: use a given storage, or null to choose from the settings again. */
    public static function useForTests(?ObjectStorage $storage): void
    {
        self::$uploads = $storage;
    }
}
