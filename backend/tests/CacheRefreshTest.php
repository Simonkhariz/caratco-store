<?php

declare(strict_types=1);

/**
 * The public catalogue cache (Support/Cache.php) and the storefront refresh after admin changes
 * (Support/StorefrontRefresh.php, called by index.php). run.php points the test API at a
 * stand-in refresh endpoint (support/revalidate_stub.php) that logs every call it receives.
 *
 *   php backend/tests/run.php CacheRefresh
 */

require __DIR__ . '/support/TestKit.php';

use App\Support\Cache;
use App\Support\StorefrontRefresh;

$t = new TestKit('CacheRefresh');

// -----------------------------------------------------------------------------
$t->section('Catalogue cache');
$dir = sys_get_temp_dir() . '/shopco-cache-test-' . getmypid();
@mkdir($dir, 0700, true);
$t->cleanup(function () use ($dir): void {
    Cache::configureForTests(null, null);
    array_map('unlink', glob($dir . '/*') ?: []);
    @rmdir($dir);
});
Cache::configureForTests($dir, 60);

$calls = 0;
$compute = function () use (&$calls): array {
    $calls++;
    return ['n' => $calls];
};
$t->check('a miss computes the value', ['n' => 1], Cache::remember('products:a', $compute));
$t->check('a hit returns the stored value without computing', ['n' => 1], Cache::remember('products:a', $compute));
$t->check('computed once for two reads', 1, $calls);
$t->check('another key is stored separately', ['n' => 2], Cache::remember('products:b', $compute));
$t->check('a longer lifetime factor still hits', ['n' => 3], Cache::remember('facets:', $compute, 10));
$t->check('second read of the long-lived key hits', ['n' => 3], Cache::remember('facets:', $compute, 10));

Cache::bumpCatalog();
$t->check('after an admin change (version bump) the value is computed again', ['n' => 4], Cache::remember('products:a', $compute));
$t->check('and then cached under the new version', ['n' => 4], Cache::remember('products:a', $compute));

$version = trim((string) file_get_contents($dir . '/catalog.version'));
file_put_contents($dir . '/' . sha1($version . '|products:a') . '.cache', 'not a serialized entry');
$t->check('a damaged cache file is ignored and replaced', ['n' => 5], Cache::remember('products:a', $compute));
$t->check('the replacement is used', ['n' => 5], Cache::remember('products:a', $compute));
$t->check('no temporary files are left behind', [], glob($dir . '/*.tmp') ?: []);

Cache::configureForTests($dir, 1);
Cache::remember('products:short', $compute);
$before = $calls;
sleep(2);
Cache::remember('products:short', $compute);
$t->check('an expired entry is computed again', $before + 1, $calls);

Cache::configureForTests($dir, 0);
$before = $calls;
Cache::remember('products:off', $compute);
Cache::remember('products:off', $compute);
$t->check('a lifetime of 0 turns caching off', $before + 2, $calls);

// However many different requests arrive, the folder stays bounded (oldest entries go first).
array_map('unlink', glob($dir . '/*.cache') ?: []);
Cache::configureForTests($dir, 60, 5);
for ($i = 1; $i <= 12; $i++) {
    Cache::remember("products:flood{$i}", $compute);
    touch($dir . '/' . sha1(trim((string) file_get_contents($dir . '/catalog.version')) . "|products:flood{$i}") . '.cache', time() - 100 + $i);
}
$t->check('the cache folder never holds more than the limit', 5, count(glob($dir . '/*.cache') ?: []));
$before = $calls;
Cache::remember('products:flood12', $compute);
$t->check('the newest entries are the ones kept', $before, $calls);
Cache::configureForTests(null, null);

$t->section('Catalogue cache keys');
$listKey = new ReflectionMethod(App\Controllers\CatalogController::class, 'listCacheKey');
$keyFor = static fn (array $filters, int $page = 1, int $perPage = 12): ?string => $listKey->invoke(null, $filters + ['product_type' => 'physical', 'sort' => 'newest', 'on_sale' => false], $page, $perPage);
$t->check('the same colours in another order or case share one entry', $keyFor(['colors' => 'Blue,black']), $keyFor(['colors' => 'black,BLUE']));
$t->check('page sizes over 100 share the entry of 100 (the most the API returns)', $keyFor([], 1, 100), $keyFor([], 1, 5000));
$t->check('prices are compared as numbers', $keyFor(['min_price' => '10']), $keyFor(['min_price' => '10.0']));
$t->check('a different filter is a different entry', false, $keyFor(['category' => 'shirts']) === $keyFor(['category' => 'jeans']));
$t->check('deep pages are not cached', null, $keyFor([], 21));
$t->check('a price that is not a number is not cached', null, $keyFor(['min_price' => 'abc']));
$t->check('an unusual filter value is not cached', null, $keyFor(['category' => str_repeat('x', 65)]));
$t->check('more than 20 colours is not cached', null, $keyFor(['colors' => implode(',', range(1, 21))]));
$t->check('a colour list given as an array of arrays is not cached', null, $keyFor(['colors' => [['x']]]));

// -----------------------------------------------------------------------------
$t->section('Storefront refresh after admin changes');
$stubLog = (string) getenv('REVALIDATE_STUB_LOG');
$stubSecret = (string) getenv('REVALIDATE_STUB_SECRET');
$t->cleanup(fn () => @unlink($stubLog . '.mode'));
$stubCalls = static function () use ($stubLog): array {
    $lines = @file($stubLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_map(static fn (string $l): array => (array) json_decode($l, true), $lines);
};
// The shutdown hook runs after the API has answered, so give it a moment to arrive.
$waitForCalls = static function (int $count) use ($stubCalls): array {
    for ($i = 0; $i < 30 && count($stubCalls()) < $count; $i++) {
        usleep(100000);
    }
    return $stubCalls();
};

$admin = $t->adminSession('refresh');
$A = static fn (string $method, string $path, array $opts = []): array
    => $t->http($method, $path, $opts + ['jar' => $admin['jar'], 'csrf' => $admin['csrf']]);

// The outcome is recorded just after the stand-in answers, so poll System status briefly.
$lastRefresh = static function (bool $expectOk) use ($A): ?array {
    $last = null;
    for ($i = 0; $i < 20; $i++) {
        $last = TestKit::get($A('GET', '/admin/system-status'), 'data.storefront_refresh.last');
        if (is_array($last) && $last['ok'] === $expectOk) {
            break;
        }
        usleep(100000);
    }
    return is_array($last) ? $last : null;
};

$status = $A('GET', '/admin/system-status');
$t->check('System status reports the refresh as set up', true, TestKit::get($status, 'data.storefront_refresh.configured'));
$t->ok('System status never contains the shared secret', !str_contains($status['body'], $stubSecret));

$start = count($stubCalls());
$A('GET', '/admin/categories');
usleep(500000);
$t->check('an admin read does not trigger a refresh', $start, count($stubCalls()));

$slug = 'refresh-cat-' . bin2hex(random_bytes(3));
$res = $A('POST', '/admin/categories', ['json' => ['name' => 'Refresh Cat', 'slug' => $slug]]);
$t->check('admin change succeeds', 201, $res['status']);
$cid = (int) TestKit::get($res, 'data.id');
$t->cleanup(fn () => $t->sql("DELETE FROM `categories` WHERE `id` = ?", 'i', [$cid]));
$after = $waitForCalls($start + 1);
$t->check('a successful admin change sends one refresh', $start + 1, count($after));
$last = end($after) ?: [];
$t->check('the refresh is a POST to /api/revalidate', ['POST', '/api/revalidate'], [$last['method'] ?? null, $last['path'] ?? null]);
$t->check('the refresh carries the shared secret', true, $last['secret_ok'] ?? null);
$t->check('System status shows the last refresh worked', true, $lastRefresh(true)['ok'] ?? null);

$start = count($stubCalls());
$t->check('a refused admin change (duplicate slug)', 422, $A('POST', '/admin/categories', ['json' => ['name' => 'Again', 'slug' => $slug]])['status']);
usleep(500000);
$t->check('a refused admin change sends no refresh', $start, count($stubCalls()));

file_put_contents($stubLog . '.mode', 'fail');
$start = count($stubCalls());
$res = $A('PUT', "/admin/categories/{$cid}", ['json' => ['name' => 'Refresh Cat renamed']]);
$t->check('the admin change still succeeds when the storefront fails', 200, $res['status']);
$waitForCalls($start + 1);
$failed = $lastRefresh(false);
$t->check('a failed refresh is recorded', false, $failed['ok'] ?? null);
$t->ok('with the reason', str_contains((string) ($failed['error'] ?? ''), '503'), (string) ($failed['error'] ?? ''));
@unlink($stubLog . '.mode');

// -----------------------------------------------------------------------------
$t->section('Refresh call itself (in this process)');
$t->check('configured from the test environment', true, StorefrontRefresh::isConfigured());
$outcome = StorefrontRefresh::send();
$t->check('send() reports success from the storefront', [true, null], [$outcome['ok'], $outcome['error']]);
$t->ok('and is quick', $outcome['ms'] < 2000, "{$outcome['ms']} ms");

exit($t->finish());
