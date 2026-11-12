<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Env;

/**
 * Short-lived cache for public catalogue reads (homepage, filters, categories, listing pages),
 * so a busy shop does not run the same queries for every visitor. Files live in
 * storage/cache; no extra server software is needed.
 *
 * Entries are tied to a "catalogue version" that changes after every admin change
 * (index.php bumps it once an admin write has finished), so edits show up straight away.
 * Stock and sales counts can be up to CATALOG_CACHE_SECONDS old; checkout always re-checks
 * live stock and prices. CATALOG_CACHE_SECONDS=0 (and APP_ENV=testing) turns caching off.
 */
final class Cache
{
    private static ?string $version = null;
    private static ?string $testDir = null;
    private static ?int $testTtl = null;
    private static ?int $testMaxEntries = null;

    public static function ttl(): int
    {
        if (self::$testTtl !== null) {
            return self::$testTtl;
        }
        if (Env::get('APP_ENV', 'production') === 'testing') {
            return 0;
        }
        return DevSwitches::enabled(DevSwitches::CATALOG_CACHE) ? self::configuredSeconds() : 0;
    }

    /** The lifetime set in backend/.env, whatever the admin's switch says. */
    public static function configuredSeconds(): int
    {
        return max(0, Env::int('CATALOG_CACHE_SECONDS', 60));
    }

    /**
     * Tests only (CacheTest): a private folder and a fixed lifetime, so the cache itself can be
     * tested without touching storage/cache. $maxEntries also prunes after every write.
     * Passing nulls restores the normal behaviour.
     */
    public static function configureForTests(?string $dir, ?int $ttl, ?int $maxEntries = null): void
    {
        self::$testDir = $dir;
        self::$testTtl = $ttl;
        self::$testMaxEntries = $maxEntries;
        self::$version = null;
    }

    /**
     * Return the cached value for $key, or compute, store and return it.
     * $ttlFactor keeps data that only admin changes affect (filters, categories) longer.
     *
     * @template T
     * @param callable(): T $compute
     * @return T
     */
    public static function remember(string $key, callable $compute, int $ttlFactor = 1): mixed
    {
        $ttl = self::ttl() * max(1, $ttlFactor);
        if ($ttl === 0) {
            return $compute();
        }

        $id = sha1(self::version() . '|' . $key);
        $redis = self::inRedis($key) ? Redis::shared() : null;
        if ($redis !== null) {
            $stored = self::fromRedis($redis, $id, $compute, $ttl);
            if ($stored !== null) {
                return $stored[0];
            }
            // Redis failed (now marked down): the file cache answers as usual.
        }

        $file = self::dir() . '/' . $id . '.cache';
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $entry = @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($entry) && ($entry['expires'] ?? 0) > time()) {
                return $entry['value'];
            }
        }

        $value = $compute();
        self::write($file, serialize(['expires' => time() + $ttl, 'value' => $value]));
        if (self::$testMaxEntries !== null || random_int(1, self::PRUNE_EVERY) === 1) {
            self::prune();
        }
        return $value;
    }

    /**
     * Cache namespaces kept in Redis when it is set up (roadmap Prompt 28: one namespace). Only
     * public catalogue data may ever be listed here. Product listing pages are the most varied
     * reads and the ones worth sharing between servers; everything else stays in files.
     */
    private const REDIS_NAMESPACES = ['products:'];

    private static function inRedis(string $key): bool
    {
        foreach (self::REDIS_NAMESPACES as $namespace) {
            if (str_starts_with($key, $namespace)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read the entry from Redis, or compute it and store it there with the cache's lifetime.
     * Returns [value], or null when Redis failed (it is then marked down). The computation itself
     * runs outside the Redis calls, so its own errors are never mistaken for a Redis outage.
     *
     * @return array{0: mixed}|null
     */
    private static function fromRedis(Redis $redis, string $id, callable $compute, int $ttl): ?array
    {
        $key = $redis->prefix . 'cache:' . $id;
        try {
            $raw = $redis->command('GET', $key);
        } catch (\Throwable $e) {
            Redis::markDown($e->getMessage());
            return null;
        }
        if (is_string($raw)) {
            $entry = @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($entry) && array_key_exists('value', $entry)) {
                return [$entry['value']];
            }
        }
        $value = $compute();
        try {
            $redis->command('SET', $key, serialize(['value' => $value]), 'EX', (string) $ttl);
        } catch (\Throwable $e) {
            Redis::markDown($e->getMessage());
        }
        return [$value];
    }

    /**
     * Invalidate every catalogue entry (called after admin changes). The new version goes to the
     * file and, when REDIS_URL is set, to Redis, where every server sees it (a file is seen only by
     * the server it is on).
     */
    public static function bumpCatalog(): void
    {
        if (self::ttl() === 0) {
            return;
        }
        $new = bin2hex(random_bytes(8));
        self::write(self::dir() . '/catalog.version', $new);
        self::$version = $new;
        $redis = Redis::shared();
        if ($redis !== null) {
            try {
                $redis->command('SET', $redis->prefix . self::VERSION_KEY, $new);
                self::$version = 'r' . $new;
            } catch (\Throwable $e) {
                // Recorded as an outage: when Redis is back, its version is replaced (shared()).
                Redis::markDown($e->getMessage());
            }
        }
    }

    /** The catalogue version in Redis (shared by all servers). */
    private const VERSION_KEY = 'catalog:version';
    private const STOREFRONT_KEY = 'storefront:version';

    /**
     * Changes after every successful admin change, whatever the cache settings. Open storefront
     * pages ask for it every few seconds (GET /api/v1/storefront/version) and reload their
     * content when it moves, so the owner's edits show without shoppers refreshing. Kept in
     * Redis when it is set up (shared by all servers), else in a file.
     */
    public static function storefrontVersion(): string
    {
        $redis = Redis::shared();
        if ($redis !== null) {
            try {
                $v = $redis->command('GET', $redis->prefix . self::STOREFRONT_KEY);
                if (is_string($v) && $v !== '') {
                    return 'r' . $v;
                }
            } catch (\Throwable $e) {
                Redis::markDown($e->getMessage());
            }
        }
        $v = @file_get_contents(self::dir() . '/storefront.version');
        return is_string($v) && $v !== '' ? trim($v) : '0';
    }

    /** Shutdown hook for admin writes: only a change that succeeded moves the version. */
    public static function bumpStorefront(): void
    {
        $status = http_response_code();
        if (!is_int($status) || $status >= 400) {
            return;
        }
        $new = bin2hex(random_bytes(8));
        self::write(self::dir() . '/storefront.version', $new);
        $redis = Redis::shared();
        if ($redis !== null) {
            try {
                $redis->command('SET', $redis->prefix . self::STOREFRONT_KEY, $new);
            } catch (\Throwable $e) {
                Redis::markDown($e->getMessage());
            }
        }
    }

    /**
     * The current catalogue version: from Redis when it answers (prefixed "r", so entries made
     * while it was away are never mistaken for current ones), else from the file.
     */
    private static function version(): string
    {
        if (self::$version !== null) {
            return self::$version;
        }
        $redis = Redis::shared();
        if ($redis !== null) {
            try {
                $key = $redis->prefix . self::VERSION_KEY;
                if (Redis::takeRecovered()) {
                    // Back after an outage: admin changes made meanwhile only reached the file.
                    $redis->command('SET', $key, bin2hex(random_bytes(8)));
                }
                $v = $redis->command('GET', $key);
                if ($v === null) {
                    // First use, or Redis restarted empty or evicted it: any new value will do.
                    $redis->command('SET', $key, bin2hex(random_bytes(8)), 'NX');
                    $v = $redis->command('GET', $key);
                }
                if (is_string($v) && $v !== '') {
                    return self::$version = 'r' . $v;
                }
            } catch (\Throwable $e) {
                Redis::markDown($e->getMessage());
            }
        }
        $v = @file_get_contents(self::dir() . '/catalog.version');
        return self::$version = is_string($v) && $v !== '' ? trim($v) : '0';
    }

    private static function dir(): string
    {
        $dir = self::$testDir ?? BASE_PATH . '/storage/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /** Write via a temporary file and rename, so readers never see half a file. */
    private static function write(string $file, string $contents): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $contents) === false) {
            return;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    /**
     * Remove entries from old catalogue versions and stale temporary files, then the oldest
     * entries beyond MAX_ENTRIES, so the folder stays bounded however many different requests
     * arrive (cache files only; they are rebuilt from the database when needed).
     */
    private static function prune(): void
    {
        $cutoff = time() - max(3600, self::ttl() * 10);
        $files = array_merge(glob(self::dir() . '/*.cache') ?: [], glob(self::dir() . '/*.tmp') ?: []);
        $kept = [];
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime === false) {
                continue;
            }
            if ($mtime < $cutoff) {
                @unlink($file);
            } else {
                $kept[$file] = $mtime;
            }
        }
        $max = self::$testMaxEntries ?? self::MAX_ENTRIES;
        if (count($kept) > $max) {
            asort($kept);
            foreach (array_slice(array_keys($kept), 0, count($kept) - $max) as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Most entries kept. Entries are 8-30 KB (about 65 MB for 5,000); the 4,000-product load
     * test used 822.
     */
    public const MAX_ENTRIES = 5000;
    /** One prune every this many writes. */
    private const PRUNE_EVERY = 200;
}
