<?php

declare(strict_types=1);

/**
 * S3-compatible picture storage (roadmap Prompt 23; Cloudflare R2 in production). The request
 * signer is checked against AWS's published Signature V4 test suite; the storage is exercised
 * against a local stand-in S3 API (support/fake_s3.php) that verifies every signature and body
 * checksum. A second test API with STORAGE_DRIVER=s3 checks uploads and /uploads serving.
 * Test configuration only: nothing is sent to Cloudflare or any other provider.
 *
 *   php backend/tests/run.php S3Storage
 */

require __DIR__ . '/support/TestKit.php';
require __DIR__ . '/support/FakeS3.php';

use App\Storage\S3Signer;
use App\Storage\S3Storage;
use App\Storage\StorageError;
use App\Storage\StorageKey;

$t = new TestKit('S3Storage');

// -----------------------------------------------------------------------------
$t->section("Signature V4 matches AWS's test suite");
// botocore tests/unit/auth/aws4_testsuite (get-vanilla, get-vanilla-query-order-key-case,
// post-x-www-form-urlencoded): credentials and expected values are AWS's published examples.
$aws = new S3Signer('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'service');
$date = '20150830T123600Z';
$base = ['Host' => 'example.amazonaws.com', 'X-Amz-Date' => $date];
$sig = static fn (string $auth): string => substr($auth, -64);
$t->check('get-vanilla', '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31', $sig($aws->authorization('GET', '/', [], $base, hash('sha256', ''), $date)));
$t->check('get-vanilla-query-order-key-case', 'b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500', $sig($aws->authorization('GET', '/', ['Param2' => 'value2', 'Param1' => 'value1'], $base, hash('sha256', ''), $date)));
$t->check('post-x-www-form-urlencoded', 'ff11897932ad3f4e8b18135d722051e5ac45fc38421b1da7b9d196a0fe09473a', $sig($aws->authorization('POST', '/', [], $base + ['Content-Type' => 'application/x-www-form-urlencoded'], hash('sha256', 'Param1=value1'), $date)));

// -----------------------------------------------------------------------------
$s3Port = (int) (getenv('TEST_S3_PORT') ?: 8111);
$fake = new FakeS3($t, $s3Port, 2);
[$dir, $bucket, $access, $secret] = [$fake->dir, $fake->bucket, $fake->access, $fake->secret];
$tmp = "{$dir}/work";
$requests = static fn (): int => $fake->requests();
$mode = static fn (string $m) => $fake->mode($m);
$storage = new S3Storage("http://127.0.0.1:{$s3Port}", 'auto', $bucket, $access, $secret, 'uploads/', true, 2);
$throws = static function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (StorageError $e) {
        return $e->getMessage();
    }
};
$src = "{$tmp}/photo.webp";
copy(__DIR__ . '/fixtures/meta.webp', $src);

// -----------------------------------------------------------------------------
$t->section('Store, read, list, delete in the bucket');
$key = StorageKey::forUpload('webp');
$storage->put($key, $src, 'image/webp', StorageKey::cacheControl($key));
$meta = json_decode((string) file_get_contents("{$dir}/objects/" . rawurlencode("uploads/{$key}") . '.meta'), true);
$t->check('stored under the uploads/ prefix', true, is_file("{$dir}/objects/" . rawurlencode("uploads/{$key}")));
$t->check('with its content type and caching', ['image/webp', 'public, max-age=31536000, immutable'], [$meta['content_type'] ?? null, $meta['cache_control'] ?? null]);
$t->check('exists', true, $storage->exists($key));
$t->check('reads back the same bytes', file_get_contents($src), $storage->read($key));
$t->check('a missing object reads as nothing', [null, false], [$storage->read('prod_missing.webp'), $storage->exists('prod_missing.webp')]);
$t->check('public path never names the provider', "/uploads/{$key}", $storage->url($key));
foreach (['catalog/a.webp', 'catalog/b.webp', 'catalog/c.webp'] as $k) {
    $storage->put($k, $src, 'image/webp', 'public, max-age=86400');
}
file_put_contents("{$dir}/objects/" . rawurlencode('backups/db.sql.gz'), 'private');
$listed = array_column(iterator_to_array($storage->list(), false), 'key');
sort($listed);
$t->check('lists every picture across pages (2 per page)', ['catalog/a.webp', 'catalog/b.webp', 'catalog/c.webp', $key], $listed);
$t->ok('other data in the bucket (outside uploads/) is never listed as a picture', !in_array('backups/db.sql.gz', $listed, true) && !in_array('db.sql.gz', $listed, true));
$t->check('prefix filter', ['catalog/a.webp', 'catalog/b.webp', 'catalog/c.webp'], array_column(iterator_to_array($storage->list('catalog/'), false), 'key'));
$t->check('delete removes it', true, $storage->delete('catalog/c.webp'));
$t->check('deleting again reports nothing removed', false, $storage->delete('catalog/c.webp'));
$t->check('bucket reachable', true, $storage->writable());

// -----------------------------------------------------------------------------
$t->section('Unsafe keys never reach the bucket');
$before = $requests();
foreach (['../x.webp', '/abs.webp', '.env', 'a//b.webp', 'a\\b.webp'] as $bad) {
    $t->check("refused: {$bad}", [true, null, false, false], [
        $throws(fn () => $storage->put($bad, $src, 'image/webp', 'x')) !== null,
        $storage->read($bad),
        $storage->exists($bad),
        $storage->delete($bad),
    ]);
}
$t->check('no request was sent for any of them', $before, $requests());

// -----------------------------------------------------------------------------
$t->section('Failures are clear and never leak the keys');
$wrong = new S3Storage("http://127.0.0.1:{$s3Port}", 'auto', $bucket, $access, $secret . 'x', 'uploads/', true);
$message = $throws(fn () => $wrong->put('prod_x.webp', $src, 'image/webp', 'x'));
$t->ok('a wrong secret is refused (SignatureDoesNotMatch)', str_contains((string) $message, 'SignatureDoesNotMatch'), (string) $message);
$t->ok('the message holds neither key', !str_contains((string) $message, $secret) && !str_contains((string) $message, $access), (string) $message);
$mode('500');
$t->ok('a provider error is reported with its code', str_contains((string) $throws(fn () => $storage->read($key)), 'InternalError'));
$t->check('and the bucket counts as not reachable', false, $storage->writable());
$mode('ok');
$offline = new S3Storage('http://127.0.0.1:1', 'auto', $bucket, $access, $secret);
$t->ok('an unreachable provider is a clear error', str_contains((string) $throws(fn () => $offline->read($key)), 'could not be reached'));
$t->ok('missing settings are a clear error', str_contains((string) $throws(fn () => new S3Storage('', 'auto', '', '', '')), 'S3_ENDPOINT'));

// -----------------------------------------------------------------------------
$t->section('The shop on STORAGE_DRIVER=s3: uploads and /uploads serving');
$apiPort = (int) (getenv('TEST_S3_API_PORT') ?: 8112);
$api = "http://127.0.0.1:{$apiPort}/api/v1";
$fake->startApi($apiPort, $fake->envFile('env', array_merge($fake->envLines(), ['STOREFRONT_REVALIDATE_URL='])));

$admin = $t->adminSession('s3');
$login = $t->http('POST', "{$api}/auth/login", ['json' => ['email' => $admin['email'], 'password' => $admin['password']], 'jar' => 's3-admin']);
$csrf = (string) TestKit::get($login, 'data.csrf_token');
$ch = curl_init("{$api}/admin/upload-image");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => ['image' => new CURLFile(__DIR__ . '/fixtures/gps-rotated.jpg', 'image/jpeg', 'gps-rotated.jpg')],
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-CSRF-Token: ' . $csrf],
    CURLOPT_COOKIEFILE => $t->cookieFile('s3-admin'),
    CURLOPT_COOKIEJAR => $t->cookieFile('s3-admin'),
]);
$upload = json_decode((string) curl_exec($ch), true) ?: [];
$uploadStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
$newKey = (string) ($upload['data']['file_name'] ?? '');
$t->check('admin upload accepted', 201, $uploadStatus);
$t->check('the shop links to /uploads/<key>', "/uploads/{$newKey}", $upload['data']['image_url'] ?? null);
$stored = (string) @file_get_contents("{$dir}/objects/" . rawurlencode("uploads/{$newKey}"));
$t->ok('the picture is in the bucket', $stored !== '');
$t->check('and its GPS location was removed before storing', false, str_contains($stored, 'GPS') || str_contains($stored, 'TestCam'));
$t->ok('nothing was written to the local uploads folder', !is_file(rtrim((string) getenv('UPLOAD_DIR') ?: BASE_PATH . '/storage/test-uploads', '/') . "/{$newKey}"));

$served = $t->http('GET', "http://127.0.0.1:{$apiPort}/uploads/{$newKey}");
$t->check('/uploads/<key> is served from the bucket', 200, $served['status']);
$t->check('as the same bytes', $stored, $served['body']);
$t->check('with a picture type', 'image/jpeg', $served['headers']['content-type'] ?? null);
$t->check('kept a year (admin uploads never change)', 'public, max-age=31536000, immutable', $served['headers']['cache-control'] ?? null);
$t->check('repeat visit: not changed (304)', 304, $t->http('GET', "http://127.0.0.1:{$apiPort}/uploads/{$newKey}", ['headers' => ['If-None-Match: ' . ($served['headers']['etag'] ?? '')]])['status']);
$t->check('missing picture: 404', 404, $t->http('GET', "http://127.0.0.1:{$apiPort}/uploads/prod_20260101_000000_deadbeef.webp")['status']);
$t->check('other bucket data is never served', 404, $t->http('GET', "http://127.0.0.1:{$apiPort}/uploads/../backups/db.sql.gz")['status']);
$t->check('non-picture keys are never served', 404, $t->http('GET', "http://127.0.0.1:{$apiPort}/uploads/catalog/notes.txt")['status']);
$status = $t->http('GET', "{$api}/admin/system-status", ['jar' => 's3-admin']);
$t->check('System status shows the bucket', ['s3', true], [TestKit::get($status, 'data.storage.driver'), TestKit::get($status, 'data.storage.uploads_writable')]);
$t->ok('and never the keys', !str_contains($status['body'], $secret) && !str_contains($status['body'], $access));
$t->check('started with the router: no warning about it', false, str_contains($status['body'], 'dev-router'));

// The mistake that made every picture disappear on a PC: `php -S` without backend/dev-router.php.
$bareEnv = $fake->envFile('env-bare', array_merge($fake->envLines(), ['STOREFRONT_REVALIDATE_URL=']));
$barePort = (int) (getenv('TEST_S3_BARE_PORT') ?: 8113);
$bare = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$barePort}", '-t', BASE_PATH . '/public'], [1 => ['file', "{$dir}/bare.log", 'w'], 2 => ['file', "{$dir}/bare.log", 'a']], $barePipes, BASE_PATH, array_merge(getenv(), ['SHOPCO_ENV_FILE' => $bareEnv]));
$t->cleanup(static function () use ($bare): void {
    if (is_resource($bare)) {
        proc_terminate($bare);
    }
});
$bareUp = false;
for ($i = 0; $i < 50 && !$bareUp; $i++) {
    usleep(200000);
    $bareUp = @file_get_contents("http://127.0.0.1:{$barePort}/api/v1/health") !== false;
}
$t->check('without the router: a picture in the bucket answers 404', 404, $t->http('GET', "http://127.0.0.1:{$barePort}/uploads/{$newKey}")['status']);
$bareStatus = $t->http('GET', "http://127.0.0.1:{$barePort}/api/v1/admin/system-status", ['jar' => 's3-admin']);
$t->ok('and System status says why, and how to fix it', str_contains($bareStatus['body'], 'dev-router.php') && str_contains($bareStatus['body'], 'npm run api'), $bareStatus['body']);

// -----------------------------------------------------------------------------
$t->section('A bucket outage does not break Data & storage or the daily check');
$outageEnv = $fake->envFile('env-outage', array_merge($fake->envLines(), ['STOREFRONT_REVALIDATE_URL=']));
$t->cleanup(fn () => $t->sql("DELETE FROM `email_outbox` WHERE `template` = 'storage_alert'"));
$hasNote = static fn (array $issues): bool => in_array('pictures_not_checked', array_column($issues, 'code'), true);
$mode('500');
$alert = $t->http('GET', "{$api}/admin/data/alert", ['jar' => 's3-admin']);
$t->check('the alert bell still answers', 200, $alert['status']);
$t->ok('and says pictures could not be checked', $hasNote((array) TestKit::get($alert, 'data.issues')), $alert['body']);
$dataPage = $t->http('GET', "{$api}/admin/data", ['jar' => 's3-admin']);
$t->check('Data & storage still answers', 200, $dataPage['status']);
$t->check('pictures are left out of what is due', false, array_key_exists('unused_images', (array) TestKit::get($dataPage, 'data.health.cleanup.due')));
$t->check('and the page is told why', [false, true, true], [
    TestKit::get($dataPage, 'data.health.cleanup.pictures_checked'),
    str_contains((string) TestKit::get($dataPage, 'data.health.cleanup.note'), 'could not be reached'),
    $hasNote((array) TestKit::get($dataPage, 'data.health.issues')),
]);
$t->check('the list of pictures says the same instead of failing', 422, $t->http('GET', "{$api}/admin/data/records?rule=unused_images&as_of=" . urlencode(date('Y-m-d H:i:s')), ['jar' => 's3-admin'])['status']);
$proc = proc_open([PHP_BINARY, BASE_PATH . '/scripts/check_database.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $checkPipes, BASE_PATH, array_merge(getenv(), ['SHOPCO_ENV_FILE' => $outageEnv]));
$checkOutput = stream_get_contents($checkPipes[1]) . stream_get_contents($checkPipes[2]);
$t->ok('the daily check still runs', proc_close($proc) === 0, $checkOutput);
$t->ok('and says pictures could not be checked', str_contains($checkOutput, 'Pictures could not be checked'), $checkOutput);
$mode('ok');
$dataPage = $t->http('GET', "{$api}/admin/data", ['jar' => 's3-admin']);
$t->check('when the bucket is back pictures are checked again', [true, null, true], [
    TestKit::get($dataPage, 'data.health.cleanup.pictures_checked'),
    TestKit::get($dataPage, 'data.health.cleanup.note'),
    array_key_exists('unused_images', (array) TestKit::get($dataPage, 'data.health.cleanup.due')),
]);

exit($t->finish());
