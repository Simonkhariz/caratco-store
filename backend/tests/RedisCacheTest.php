<?php

declare(strict_types=1);

/**
 * Optional Redis for the catalogue cache (Support/Redis.php, Support/Cache.php; docs/REDIS.md),
 * with the file cache as the fallback. Runs against a stand-in Redis (support/fake_redis.php),
 * or a real one: REDIS_TEST_URL=redis://:password@127.0.0.1:6379/15 (its keys under a test
 * prefix are removed afterwards; outage checks need the stand-in and are skipped then).
 *
 *   php backend/tests/run.php RedisCache
 */

require __DIR__ . '/support/TestKit.php';

use App\Config\Env;
use App\Support\Cache;
use App\Support\Redis;

$t = new TestKit('RedisCache');

$dir = sys_get_temp_dir() . '/shopco-redis-test-' . getmypid();
$serverA = "{$dir}/server-a";
$serverB = "{$dir}/server-b";
@mkdir($serverA, 0700, true);
@mkdir($serverB, 0700, true);
$prefix = 'shopco-test-' . getmypid() . ':';

$realUrl = (string) getenv('REDIS_TEST_URL');
$port = (int) (getenv('TEST_REDIS_PORT') ?: 6398);
$password = 'fake-' . bin2hex(random_bytes(6));
$fake = null;
$startFake = static function () use (&$fake, $port, $password, $dir): bool {
    $fake = proc_open([PHP_BINARY, __DIR__ . '/support/fake_redis.php', (string) $port], [1 => ['file', "{$dir}/redis.log", 'a'], 2 => ['file', "{$dir}/redis.log", 'a']], $pipes, BASE_PATH, array_merge(getenv(), ['FAKE_REDIS_PASSWORD' => $password, 'FAKE_REDIS_DIR' => $dir]));
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        if (($s = @fsockopen('127.0.0.1', $port)) !== false) {
            fclose($s);
            return true;
        }
    }
    return false;
};
$stopFake = static function () use (&$fake): void {
    if (is_resource($fake)) {
        proc_terminate($fake);
        proc_close($fake);
    }
    $fake = null;
    usleep(300000);
};
$mode = static fn (string $m) => file_put_contents("{$dir}/mode", $m);

$vars = (new ReflectionProperty(Env::class, 'vars'));
$original = $vars->getValue();
$setEnv = static fn (array $values) => $vars->setValue(null, array_merge($vars->getValue(), $values));
$url = $realUrl !== '' ? $realUrl : "redis://:{$password}@127.0.0.1:{$port}/0";

$t->cleanup(function () use ($stopFake, $vars, $original, $dir, $url, $prefix, $setEnv): void {
    // Remove this run's keys (on a real Redis, nothing else is touched).
    $setEnv(['REDIS_URL' => $url, 'REDIS_PREFIX' => $prefix]);
    try {
        $r = Redis::fromEnv();
        $r->connect();
        foreach ((array) $r->command('KEYS', $prefix . '*') as $k) {
            $r->command('DEL', (string) $k);
        }
        $r->close();
    } catch (Throwable $e) {
    }
    $stopFake();
    $vars->setValue(null, $original);
    Cache::configureForTests(null, null);
    Redis::resetForTests(null);
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
});

if ($realUrl === '') {
    $t->check('stand-in Redis started', true, $startFake());
}
$setEnv(['REDIS_URL' => $url, 'REDIS_PREFIX' => $prefix, 'REDIS_TIMEOUT_MS' => '200', 'REDIS_RETRY_SECONDS' => '2']);

// A "request" on one server: fresh static state, that server's cache folder.
$request = static function (string $server) use ($dir): void {
    Cache::configureForTests($server, 60);
    Redis::resetForTests($server);
};
$version = static fn (): string => (string) (new ReflectionMethod(Cache::class, 'version'))->invoke(null);
$direct = static function () use ($url, $prefix, $setEnv): Redis {
    $r = Redis::fromEnv();
    $r->connect();
    return $r;
};
$calls = 0;
$compute = function () use (&$calls): array {
    $calls++;
    return ['n' => $calls];
};

// -----------------------------------------------------------------------------
$t->section('Connection');
$status = Redis::status();
$t->check('System status: set up and answering', [true, true], [$status['configured'], $status['ok']], (string) $status['error']);
$r = $direct();
$t->check('a value written is read back unchanged (binary-safe)', "a\r\nb\0c", ($r->command('SET', $prefix . 'probe', "a\r\nb\0c") === 'OK') ? $r->command('GET', $prefix . 'probe') : null);
$t->check('a missing key reads as nothing', null, $r->command('GET', $prefix . 'missing'));
$r->close();

// -----------------------------------------------------------------------------
$t->section('The catalogue version is shared by all servers');
$request($serverA);
$vA = $version();
$request($serverB);
$vB = $version();
$t->ok('two servers read the same version from Redis', $vA === $vB && str_starts_with($vA, 'r'), "{$vA} / {$vB}");
$request($serverA);
$t->check('server A caches a listing', ['n' => 1], Cache::remember('products:x', $compute));
$t->check('and reuses it', ['n' => 1], Cache::remember('products:x', $compute));
$request($serverA);
Cache::bumpCatalog(); // an admin change on server A
$request($serverB);
$t->ok('after an admin change on server A, server B sees the new version', $version() !== $vB);
$request($serverA);
$t->check('and the listing is built again', ['n' => 2], Cache::remember('products:x', $compute));
$r = $direct();
$keys = array_values(array_filter((array) $r->command('KEYS', $prefix . '*'), fn ($k) => $k !== $prefix . 'probe'));
$t->ok('Redis holds only the version and cached listing pages', $keys !== [] && array_filter($keys, fn ($k) => $k !== $prefix . 'catalog:version' && !str_starts_with((string) $k, $prefix . 'cache:')) === [], implode(', ', $keys));
$r->close();

// -----------------------------------------------------------------------------
$t->section('Listing pages are shared through Redis (one namespace)');
$r = $direct();
$r->command('FLUSHDB');
$r->close();
$request($serverA);
$n = $calls;
$listing = Cache::remember('products:shared', $compute);
$t->check('cold start: the first request builds the page', $n + 1, $calls);
$request($serverB);
$t->check('another server reuses it instead of building it again', $listing, Cache::remember('products:shared', $compute));
$t->check('built once for both servers', $n + 1, $calls);
$r = $direct();
$ttl = (int) $r->command('TTL', $prefix . 'cache:' . sha1($version() . '|products:shared'));
$t->ok('stored with the cache lifetime (60 s here), so old versions expire by themselves', $ttl > 50 && $ttl <= 60, (string) $ttl);
$r->close();

$n = $calls;
$request($serverA);
Cache::remember('facets:t-shirts', $compute);
Cache::remember('admin-storage-alert', $compute);
$request($serverB);
Cache::remember('facets:t-shirts', $compute);
Cache::remember('admin-storage-alert', $compute);
$t->check('every other cache (filters, admin data) stays in each server\'s files', $n + 4, $calls);
$r = $direct();
$t->check('and never reaches Redis', 1, count((array) $r->command('KEYS', $prefix . 'cache:*')));
$r->close();

$request($serverA);
$rich = ['name' => "Tee \u{1F455} \"crew\"", 'price' => 12500.5, 'tags' => ['a', null, true], 'empty' => []];
Cache::remember('products:rich', static fn () => $rich);
$request($serverB);
$t->check('values come back exactly as stored (text, numbers, nulls, nesting)', $rich, Cache::remember('products:rich', static fn () => 'not used'));

$request($serverA);
Cache::bumpCatalog();
$request($serverB);
$n = $calls;
Cache::remember('products:shared', $compute);
$t->check('after an admin change the shared page is built again (no stale entry)', $n + 1, $calls);

$request($serverA);
$failing = static function (): never {
    throw new RuntimeException('database error while building the page');
};
try {
    Cache::remember('products:boom', $failing);
    $t->ok('an error while building a page reaches the caller', false);
} catch (RuntimeException $e) {
    $t->ok('an error while building a page reaches the caller', $e->getMessage() === 'database error while building the page');
}
$t->check('and is not mistaken for a Redis outage', false, is_file("{$serverA}/redis.down"));

if ($realUrl === '') {
    // -------------------------------------------------------------------------
    $t->section('Redis stops answering');
    $request($serverA);
    $before = $calls;
    Cache::remember('products:y', $compute); // cached under the Redis version
    $mode('hang');
    $request($serverA);
    $started = microtime(true);
    $value = Cache::remember('products:y', $compute);
    $took = microtime(true) - $started;
    $t->ok('a hanging Redis costs at most its timeout, then the file cache answers', $took < 0.6 && is_array($value), sprintf('%.0f ms', $took * 1000));
    $t->check('the outage is remembered by the whole server', true, is_file("{$serverA}/redis.down"));
    $request($serverA);
    $started = microtime(true);
    Cache::remember('products:y', $compute);
    $t->ok('later requests skip Redis without waiting', microtime(true) - $started < 0.05, sprintf('%.1f ms', (microtime(true) - $started) * 1000));
    $request($serverA);
    Cache::bumpCatalog(); // an admin change while Redis is away: only the file knows
    $request($serverA);
    $afterChange = Cache::remember('products:y', $compute);

    $t->section('Redis comes back');
    $mode('ok');
    sleep(3); // past REDIS_RETRY_SECONDS
    $request($serverA);
    $vBack = $version();
    $t->ok('it is used again', str_starts_with($vBack, 'r') && !is_file("{$serverA}/redis.down"), $vBack);
    $t->ok('with a new version, so nothing cached before the outage is served', $vBack !== $vA);
    $request($serverA);
    $n = $calls;
    $fresh = Cache::remember('products:y', $compute);
    $t->ok('the listing changed during the outage is built again, not taken from before it', $calls === $n + 1 && $fresh !== $afterChange, json_encode([$afterChange, $fresh]));

    $t->section('Redis restarts empty');
    $stopFake();
    $startFake();
    $request($serverA);
    $v = $version();
    $t->ok('a new version is chosen and the cache starts afresh', str_starts_with($v, 'r') && $v !== $vBack, $v);

    $t->section('Redis switched off or wrongly set up');
    $stopFake();
    $request($serverA);
    $n = $calls;
    $t->check('Redis gone: the shop still answers from the file cache', true, is_array(Cache::remember('products:z', $compute)));
    $t->check('System status reports it', false, Redis::status()['ok']);
    $startFake();
    $setEnv(['REDIS_URL' => "redis://:wrong@127.0.0.1:{$port}/0"]);
    $request($serverB);
    $t->check('a wrong password: file cache, no error for the visitor', true, is_array(Cache::remember('products:z', $compute)));
    $t->ok('and System status names the problem', str_contains((string) Redis::status()['error'], 'WRONGPASS'), (string) Redis::status()['error']);
    $setEnv(['REDIS_URL' => 'ftp://nowhere']);
    $t->ok('a malformed REDIS_URL is reported, not fatal', str_contains((string) Redis::status()['error'], 'must start with'), (string) Redis::status()['error']);
    $setEnv(['REDIS_URL' => '']);
    $request($serverB);
    @unlink("{$serverB}/redis.down");
    Cache::remember('products:off', $compute);
    $t->check('without REDIS_URL nothing tries Redis', [false, false], [Redis::isConfigured(), is_file("{$serverB}/redis.down")]);
    $setEnv(['REDIS_URL' => $url]);
}

exit($t->finish());
