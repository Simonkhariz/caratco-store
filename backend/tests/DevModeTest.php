<?php

declare(strict_types=1);

/**
 * Dev mode and the tech switches (Support/DevSwitches.php, admin → Store settings, Tech switches).
 * Dev mode pages must be refused by the API when it is off (the database alert stays reachable),
 * turning it on must need the password, and every switch must really change what the shop does.
 *
 *   php backend/tests/run.php DevMode
 */

require __DIR__ . '/support/TestKit.php';

use App\Config\Env;
use App\Services\MailService;
use App\Support\Cache;
use App\Support\DevSwitches;
use App\Support\Redis;
use App\Support\SlowRequestLog;

$t = new TestKit('DevMode');
$file = DevSwitches::fileForTests();
$vars = new ReflectionProperty(Env::class, 'vars');
$originalEnv = $vars->getValue();
$setEnv = static fn (array $values) => $vars->setValue(null, array_merge($vars->getValue(), $values));

// Later suites run with the test runner's default again (dev mode on, no choices saved).
$forget = static function () use ($t, $file): void {
    $t->sql("DELETE FROM `store_settings` WHERE `key_name` IN ('dev_mode', 'dev_switches')");
    @unlink($file);
    DevSwitches::resetForTests();
};
$forget();
$t->cleanup(function () use ($forget, $vars, $originalEnv): void {
    $vars->setValue(null, $originalEnv);
    $forget();
});

$admin = $t->adminSession('devmode');
$as = ['jar' => $admin['jar'], 'csrf' => $admin['csrf']];
$setMode = static fn (array $body) => $t->http('POST', '/admin/dev-mode', ['json' => $body] + $as);
$setSwitch = static fn (string $key, mixed $on) => $t->http('PUT', '/admin/dev-mode/switches', ['json' => ['key' => $key, 'on' => $on]] + $as);
$turnOn = static fn () => $setMode(['on' => true, 'password' => $admin['password']]);
$audit = static fn (string $action): int => (int) $t->scalar('SELECT COUNT(*) FROM `admin_audit_logs` WHERE `admin_id` = ? AND `action` = ?', 'is', [$admin['id'], $action]);

// -----------------------------------------------------------------------------
$t->section('Dev mode is off on a real shop until someone turns it on');
$setEnv(['DEV_MODE_DEFAULT' => 'false']);
DevSwitches::resetForTests();
@unlink($file);
$t->check('off by default', false, DevSwitches::devMode());
$setEnv(['DEV_MODE_DEFAULT' => 'true']);
DevSwitches::resetForTests();
@unlink($file);

// -----------------------------------------------------------------------------
$t->section('Who can change it');
$t->check('not signed in: refused', 401, $t->http('GET', '/admin/dev-mode')['status']);
$t->check('turning on while not signed in: refused', 401, $t->http('POST', '/admin/dev-mode', ['json' => ['on' => true]])['status']);
$noCsrf = $t->http('POST', '/admin/dev-mode', ['json' => ['on' => false], 'jar' => $admin['jar']]);
$t->check('without the CSRF token: refused', 403, $noCsrf['status']);

// -----------------------------------------------------------------------------
$t->section('When dev mode is off, its pages are refused, not just hidden');
$off = $setMode(['on' => false]);
$t->check('turning off needs no password', 200, $off['status']);
$t->check('answers off', false, TestKit::get($off, 'data.on'));
$t->check('audit log records it', 1, $audit('dev_mode.turned_off'));
$shown = $t->http('GET', '/admin/dev-mode', $as);
$t->check('reports off', false, TestKit::get($shown, 'data.on'));
$t->check('and gives no technical details', null, TestKit::get($shown, 'data.switches'));

$refused = [
    ['GET', '/admin/system-status', null],
    ['GET', '/admin/data', null],
    ['GET', '/admin/data/records?rule=abandoned_carts', null],
    ['POST', '/admin/data/cleanup/verify', ['password' => $admin['password']]],
    ['PUT', '/admin/data/settings', ['storage_alert_mb' => 500]],
    ['POST', '/admin/data/cleanup', ['code' => '000000']],
];
foreach ($refused as [$method, $path, $body]) {
    $res = $t->http($method, $path, ($body !== null ? ['json' => $body] : []) + $as);
    $t->check("{$method} {$path}: refused", [403, 'DEV_MODE_OFF'], [$res['status'], TestKit::get($res, 'error.code')]);
}
$t->check('the switches are refused too', 403, $setSwitch(DevSwitches::GZIP, false)['status']);
$t->check('the database alert still reaches the admin', 200, $t->http('GET', '/admin/data/alert', $as)['status']);
$outboxStatus = $t->http('GET', '/admin/notifications/outbox/status', $as);
$t->check('the Email outbox page keeps its counts and warnings', [200, true], [$outboxStatus['status'], is_int(TestKit::get($outboxStatus, 'data.outbox_pending')) && is_string(TestKit::get($outboxStatus, 'data.mode'))]);
$t->check('and its Send queued now button works', 200, $t->http('POST', '/admin/notifications/outbox/process', $as)['status']);

// -----------------------------------------------------------------------------
$t->section('Turning it on needs the password');
$wrong = $setMode(['on' => true, 'password' => 'not-the-password']);
$t->check('a wrong password: refused', [422, 'That password is not right.'], [$wrong['status'], TestKit::get($wrong, 'error.details.password')]);
$t->check('and it stays off', false, TestKit::get($t->http('GET', '/admin/dev-mode', $as), 'data.on'));
$t->check('no password: refused', 422, $setMode(['on' => true])['status']);
$t->check('failed tries are in the audit log', 2, $audit('dev_mode.password_failed'));
$t->check('a value that is not on or off: refused', 422, $setMode(['on' => 'yes', 'password' => $admin['password']])['status']);
$on = $turnOn();
$t->check('the right password: on', [200, true], [$on['status'], TestKit::get($on, 'data.on')]);
$t->check('audit log records it', 1, $audit('dev_mode.turned_on'));
$t->check('the pages open again', 200, $t->http('GET', '/admin/system-status', $as)['status']);
$t->check('Data & storage too', 200, $t->http('GET', '/admin/data', $as)['status']);
$t->ok('the password is never in the audit log', (int) $t->scalar("SELECT COUNT(*) FROM `admin_audit_logs` WHERE `admin_id` = ? AND CONCAT(COALESCE(`before_state`, ''), COALESCE(`after_state`, '')) LIKE ?", 'is', [$admin['id'], '%' . $admin['password'] . '%']) === 0);

$other = $t->adminSession('devmode-limit');
$otherAs = ['jar' => $other['jar'], 'csrf' => $other['csrf']];
for ($i = 0; $i < 5; $i++) {
    $t->http('POST', '/admin/dev-mode', ['json' => ['on' => true, 'password' => 'wrong']] + $otherAs);
}
$limited = $t->http('POST', '/admin/dev-mode', ['json' => ['on' => true, 'password' => $other['password']]] + $otherAs);
$t->check('five wrong passwords: wait, even with the right one', 429, $limited['status']);

// -----------------------------------------------------------------------------
$t->section('The switches page');
$page = $t->http('GET', '/admin/dev-mode', $as);
$list = TestKit::get($page, 'data.switches');
$byKey = array_column((array) $list, null, 'key');
$t->check('lists every switch', array_keys(DevSwitches::definitions()), array_column((array) $list, 'key'));
$t->ok('every switch explains itself', array_filter((array) $list, static fn (array $s): bool => $s['label'] === '' || $s['what'] === '' || $s['when_off'] === '') === []);
$t->check('on by default', true, $byKey[DevSwitches::CATALOG_CACHE]['on']);
$t->check('except live payment status', [false, false], [$byKey[DevSwitches::LIVE_STATUS]['on'], $byKey[DevSwitches::LIVE_STATUS]['recommended']]);
$t->check('Redis says when it is not set up', false, $byKey[DevSwitches::REDIS]['available']);
$t->ok('and why', str_contains((string) $byKey[DevSwitches::REDIS]['note'], 'REDIS_URL'));
$fixedNames = array_column((array) TestKit::get($page, 'data.fixed'), 'name');
$t->ok('pictures and payment safety have no switch, and say so', in_array('Picture storage', $fixedNames, true) && in_array('Payment and stock protection', $fixedNames, true));
$t->check('an unknown switch: refused', 422, $setSwitch('payments', false)['status']);
$t->check('a value that is not on or off: refused', 422, $setSwitch(DevSwitches::GZIP, 'off')['status']);
$saved = $setSwitch(DevSwitches::GZIP, false);
$t->check('saved', 200, $saved['status']);
$t->check('and shown', false, array_column((array) TestKit::get($saved, 'data.switches'), 'on', 'key')[DevSwitches::GZIP]);
$t->check('audit log records it', 1, $audit('dev_mode.switch_changed'));
$reset = $t->http('PUT', '/admin/dev-mode/switches', ['json' => ['reset' => true]] + $as);
$t->check('reset brings the default back', true, array_column((array) TestKit::get($reset, 'data.switches'), 'on', 'key')[DevSwitches::GZIP]);

// -----------------------------------------------------------------------------
$t->section('Each switch really changes what the shop does');
$gzipHeader = static fn (): ?string => $t->http('GET', '/products?per_page=12', ['headers' => ['Accept-Encoding: gzip']])['headers']['content-encoding'] ?? null;
$t->check('compression on: answers are compressed', 'gzip', $gzipHeader());
$setSwitch(DevSwitches::GZIP, false);
$t->check('compression off: they are not', null, $gzipHeader());
$setSwitch(DevSwitches::GZIP, true);

$liveFlag = static fn () => TestKit::get($t->http('GET', '/checkout/payment-methods'), 'data.live_payment_status');
$t->check('live payment status off: the checkout is told', false, $liveFlag());
$setSwitch(DevSwitches::LIVE_STATUS, true);
$t->check('on: the checkout is told', true, $liveFlag());
$warned = (array) TestKit::get($t->http('GET', '/admin/dev-mode', $as), 'data.warnings');
$t->ok('and the page warns what it costs', str_contains(implode(' ', $warned), 'PHP worker'));
$setSwitch(DevSwitches::LIVE_STATUS, false);

$setEnv(['APP_ENV' => 'development', 'CATALOG_CACHE_SECONDS' => '60']);
DevSwitches::resetForTests();
$t->check('catalogue cache on: pages are kept', 60, Cache::ttl());
$setSwitch(DevSwitches::CATALOG_CACHE, false);
DevSwitches::resetForTests();
$t->check('off: they are not', 0, Cache::ttl());
$setSwitch(DevSwitches::CATALOG_CACHE, true);
$setEnv(['APP_ENV' => 'testing']);

$setEnv(['REDIS_URL' => 'redis://:secret@127.0.0.1:1/0']);
$markerDir = sys_get_temp_dir() . '/shopco-devmode-' . getmypid();
@mkdir($markerDir, 0700, true);
Redis::resetForTests($markerDir);
$setSwitch(DevSwitches::REDIS, false);
DevSwitches::resetForTests();
$t->check('Redis off: no connection is tried', null, Redis::shared());
$t->ok('so it is not reported as down', !is_file("{$markerDir}/redis.down") && glob("{$markerDir}/*") === []);
$status = Redis::status();
$t->check('System status says it is switched off', [true, true, null], [$status['configured'], $status['switched_off'], $status['ok']]);
$setSwitch(DevSwitches::REDIS, true);
DevSwitches::resetForTests();
Redis::resetForTests($markerDir);
Redis::shared();
$t->ok('Redis on: a connection is tried', glob("{$markerDir}/*") !== []);
array_map('unlink', glob("{$markerDir}/*") ?: []);
@rmdir($markerDir);
Redis::resetForTests(null);
$setEnv(['REDIS_URL' => '']);

$setSwitch(DevSwitches::SLOW_REQUEST_LOG, false);
DevSwitches::resetForTests();
$t->check('slow request log off: nothing is counted', 0, SlowRequestLog::thresholdMs());
$setSwitch(DevSwitches::SLOW_REQUEST_LOG, true);
DevSwitches::resetForTests();
$t->ok('on: slow requests are counted', SlowRequestLog::thresholdMs() > 0);

$stubLog = (string) getenv('REVALIDATE_STUB_LOG');
$refreshes = static fn (): int => count(@file($stubLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
$adminWrite = static fn () => $t->http('PUT', '/admin/data/settings', ['json' => ['storage_alert_mb' => 500]] + $as);
$setSwitch(DevSwitches::INSTANT_REFRESH, false);
$t->http('GET', '/health');
$before = $refreshes();
$t->check('an admin change is saved', 200, $adminWrite()['status']);
$t->http('GET', '/health');
$t->check('instant refresh off: the storefront is not called', $before, $refreshes());
$setSwitch(DevSwitches::INSTANT_REFRESH, true);
$t->http('GET', '/health');
$before = $refreshes();
$adminWrite();
$t->http('GET', '/health');
$t->check('on: the storefront is called', $before + 1, $refreshes());

// Emails: park others that are due, so only this test's own are in play.
$parked = array_map('intval', array_column($t->db->fetchAll(
    "SELECT `id` FROM `email_outbox` WHERE `status` = 'pending' AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= NOW())"
), 'id'));
if ($parked !== []) {
    $t->sql('UPDATE `email_outbox` SET `next_attempt_at` = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE `id` IN (' . implode(',', $parked) . ')');
    $t->cleanup(fn () => $t->sql("UPDATE `email_outbox` SET `next_attempt_at` = NULL WHERE `status` = 'pending' AND `id` IN (" . implode(',', $parked) . ')'));
}
$mailer = new MailService($t->db);
$emailStatus = static fn (string $to): ?string => $t->scalar('SELECT `status` FROM `email_outbox` WHERE `recipient_email` = ? ORDER BY `id` DESC LIMIT 1', 's', [strtolower($to)]);
$queue = static function (bool $now) use ($t, $mailer): string {
    $to = $t->uniq('devswitch') . '@example.test';
    $mailer->queue($to, 'payment_claimed', ['order_number' => 'ORD-DEV', 'amount' => 1000], null, $now);
    $t->cleanup(fn () => $t->sql('DELETE FROM `email_outbox` WHERE `recipient_email` = ?', 's', [strtolower($to)]));
    return $to;
};
$setSwitch(DevSwitches::MAIL_AFTER_RESPONSE, false);
DevSwitches::resetForTests();
$t->check('email after request off: the email waits in the outbox', 'pending', $emailStatus($queue(true)));
$setSwitch(DevSwitches::MAIL_AFTER_RESPONSE, true);
DevSwitches::resetForTests();
$t->ok('on: it goes straight away', $emailStatus($queue(true)) !== 'pending');
$t->sql("UPDATE `email_outbox` SET `next_attempt_at` = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE `status` = 'pending' AND `recipient_email` LIKE 'devswitch%'");

$freeSlot = static fn () => $t->sql("UPDATE `store_settings` SET `value` = '2000-01-01 00:00:00' WHERE `key_name` = 'outbox_last_sweep'");
$t->cleanup(fn () => $t->sql("DELETE FROM `store_settings` WHERE `key_name` = 'outbox_last_sweep'"));
$setSwitch(DevSwitches::MAIL_AUTO_RETRY, false);
$due = $queue(false);
$freeSlot();
$t->http('GET', '/categories');
$t->http('GET', '/health'); // the test server finishes one request before starting the next
$t->check('automatic retries off: a due email stays waiting', 'pending', $emailStatus($due));
$warned = (array) TestKit::get($setSwitch(DevSwitches::MAIL_AFTER_RESPONSE, false), 'data.warnings');
$t->ok('both email switches off: the page warns that email may stop', str_contains(implode(' ', $warned), 'process_outbox'));
$setSwitch(DevSwitches::MAIL_AFTER_RESPONSE, true);
$setSwitch(DevSwitches::MAIL_AUTO_RETRY, true);
$freeSlot();
$t->http('GET', '/categories');
$t->http('GET', '/health');
$t->ok('on: ordinary requests send it', $emailStatus($due) !== 'pending', (string) $emailStatus($due));

// -----------------------------------------------------------------------------
$t->section('Reading the switches never slows or breaks a request');
$setSwitch(DevSwitches::GZIP, true);
DevSwitches::resetForTests();
$t->check('a recent file is trusted: the database is not asked on every request', true, DevSwitches::enabled(DevSwitches::GZIP));
$t->sql("UPDATE `store_settings` SET `value` = ? WHERE `key_name` = 'dev_switches'", 's', [json_encode([DevSwitches::GZIP => false])]);
DevSwitches::resetForTests();
$t->check('so a change made directly in the database is not seen yet', true, DevSwitches::enabled(DevSwitches::GZIP));
touch($file, time() - 61);
clearstatcache(true, $file); // PHP remembers file times within one process; a request starts fresh
DevSwitches::resetForTests();
$t->check('a second server follows within a minute', false, DevSwitches::enabled(DevSwitches::GZIP));
$setSwitch(DevSwitches::GZIP, true);

file_put_contents($file, 'not json');
DevSwitches::resetForTests();
$t->check('a damaged file is rebuilt from the database', true, DevSwitches::enabled(DevSwitches::GZIP));
$t->ok('and is valid again', is_array(json_decode((string) file_get_contents($file), true)));

$envFile = tempnam(sys_get_temp_dir(), 'devenv');
$script = tempnam(sys_get_temp_dir(), 'devphp');
file_put_contents($envFile, "DB_HOST=127.0.0.1\nDB_PORT=1\nDB_DATABASE=devmode_unreachable_" . getmypid() . "\nAPP_ENV=production\n");
file_put_contents($script, '<?php define("BASE_PATH", ' . var_export(BASE_PATH, true) . '); require BASE_PATH . "/src/autoload.php"; App\Config\Env::load(BASE_PATH . "/.env"); echo json_encode([App\Support\DevSwitches::enabled("gzip"), App\Support\DevSwitches::enabled("live_status"), App\Support\DevSwitches::devMode()]);');
// A real server has no DEV_MODE_DEFAULT: leave out the value this test process inherited.
$childEnv = array_diff_key(array_merge(getenv(), ['SHOPCO_ENV_FILE' => $envFile]), ['DEV_MODE_DEFAULT' => '']);
$process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, $childEnv);
$out = (string) stream_get_contents($pipes[1]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($process);
@unlink($envFile);
@unlink($script);
$t->check('database down: defaults apply (dev mode off) and nothing breaks', '[true,false,false]', $out);

exit($t->finish());
