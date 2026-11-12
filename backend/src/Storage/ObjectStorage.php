<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Where uploaded pictures are kept. Code that saves, lists or removes pictures goes through this
 * interface only, so the store can move from the server's disk (LocalStorage) to an S3-compatible
 * bucket by configuration, without touching the callers.
 *
 * Keys are stable, server-generated names such as "prod_20261022_102734_27f8e528.webp" or
 * "catalog/banner-formal.webp" (see StorageKey). The database stores the public path
 * "/uploads/<key>", never a provider address or a temporary signed link, so the same key keeps
 * working whichever storage holds the file.
 */
interface ObjectStorage
{
    /** A short name for System status and logs ("local", "s3"). */
    public function name(): string;

    /**
     * Store the file at $sourcePath under $key (replacing an existing object with that key).
     *
     * @throws StorageError when it cannot be stored
     */
    public function put(string $key, string $sourcePath, string $contentType, string $cacheControl): void;

    /** The file's contents, or null when there is no such object. */
    public function read(string $key): ?string;

    public function exists(string $key): bool;

    /** Remove the object. Returns false when there was nothing to remove. */
    public function delete(string $key): bool;

    /**
     * Every object whose key starts with $prefix.
     *
     * @return iterable<array{key: string, size: int, modified: int}>
     */
    public function list(string $prefix = ''): iterable;

    /** The public path the shop links to ("/uploads/<key>"). */
    public function url(string $key): string;

    /** Whether new pictures can be stored right now (for System status). */
    public function writable(): bool;
}
