<?php

declare(strict_types=1);

/**
 * Live payment status stream (roadmap Prompt 33, docs/SSE_CONTRACT.md).
 *
 * The main test API has SSE off (the default). This suite starts four more test APIs on the same
 * test database with SSE on, short streams (6 s), a 2 s heartbeat and room for 3 streams, so
 * several streams can be open at once (php -S serves one request at a time per server). Payments
 * are confirmed in this process through PaymentFinalizer, the same gate the webhooks use.
 *
 *   php backend/tests/run.php LiveStatus
 */

require __DIR__ . '/support/TestKit.php';

use App\Services\LiveStatus;
use App\Services\PaymentFinalizer;
use App\Support\RateLimiter;

$t = new TestKit('LiveStatus');
$db = $t->db;
$finalizer = new PaymentFinalizer($db);
$tmp = sys_get_temp_dir() . '/shopco-live-' . getmypid();
@mkdir($tmp, 0700, true);
$basePort = (int) (getenv('TEST_LIVE_API_PORT') ?: 8110);
$ports = [$basePort, $basePort + 1, $basePort + 2, $basePort + 3];

// -----------------------------------------------------------------------------
$t->section('Switched off by default');
$off = $t->http('GET', '/checkout/orders/ORD-20260101-AAAAAA/events');
$t->check('stream answers 404 when SSE is off', 404, $off['status']);
$t->check('with the code SSE_DISABLED', 'SSE_DISABLED', TestKit::get($off, 'error.code') ?? TestKit::get($off, 'code'));
$t->check('payment methods tell the page it is off', false, TestKit::get($t->http('GET', '/checkout/payment-methods'), 'data.live_payment_status'));

// -----------------------------------------------------------------------------
// Four more APIs with SSE on.
$envFile = "{$tmp}/env";
$lines = array_values(array_filter(
    file((string) getenv('SHOPCO_ENV_FILE'), FILE_IGNORE_NEW_LINES) ?: [],
    static fn (string $l): bool => !preg_match('/^(SSE_[A-Z_]+)=/', $l)
));
$lines = array_merge($lines, ['SSE_ENABLED=true', 'SSE_STREAM_SECONDS=6', 'SSE_HEARTBEAT_SECONDS=2', 'SSE_MAX_STREAMS=3']);
file_put_contents($envFile, implode("\n", $lines) . "\n");
$procs = [];
foreach ($ports as $port) {
    $procs[] = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', BASE_PATH . '/public'],
        [1 => ['file', "{$tmp}/api-{$port}.log", 'w'], 2 => ['file', "{$tmp}/api-{$port}.log", 'a']],
        $pipes,
        BASE_PATH,
        array_merge(getenv(), ['SHOPCO_ENV_FILE' => $envFile])
    );
}
$t->cleanup(function () use ($procs, $tmp): void {
    foreach ($procs as $p) {
        proc_terminate($p);
    }
    array_map('unlink', glob("{$tmp}/*") ?: []);
    @rmdir($tmp);
});
$up = 0;
foreach ($ports as $port) {
    for ($i = 0; $i < 50; $i++) {
        if (@file_get_contents("http://127.0.0.1:{$port}/api/v1/health") !== false) {
            $up++;
            break;
        }
        usleep(200000);
    }
}
$t->check('four SSE test APIs started', 4, $up);
$api = static fn (int $port): string => "http://127.0.0.1:{$port}/api/v1";
$t->check('payment methods tell the page it is on', true, TestKit::get($t->http('GET', $api($ports[0]) . '/checkout/payment-methods'), 'data.live_payment_status'));

// -----------------------------------------------------------------------------
// Helpers: an unpaid guest order with a WhatsApp attempt, and streams read with curl_multi.

/** @return array{number: string, id: int, token: string} */
$newOrder = static function () use ($t): array {
    $variant = $t->variant(2);
    $email = $t->uniq('live') . '@example.test';
    $res = $t->http('POST', '/checkout/orders', [
        'headers' => ['Idempotency-Key: ' . bin2hex(random_bytes(16))],
        'json' => [
            'email' => $email,
            'items' => [['product_id' => (int) $variant['product_id'], 'variant_id' => (int) $variant['variant_id'], 'quantity' => 1]],
            'shipping_address' => ['full_name' => 'Live Buyer', 'phone' => '08000000007', 'address_line1' => '7 Test Close', 'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria'],
        ],
    ]);
    $number = (string) TestKit::get($res, 'data.order_number');
    $token = (string) TestKit::get($res, 'data.guest_access_token');
    $id = (int) $t->scalar("SELECT `id` FROM `orders` WHERE `order_number` = ?", 's', [$number]);
    $t->http('POST', "/checkout/orders/{$number}/initialize-payment", ['headers' => ["X-Guest-Access-Token: {$token}"], 'json' => ['gateway' => 'whatsapp']]);
    $t->cleanup(function () use ($t, $id, $email): void {
        foreach (['refunds', 'shipments', 'payments', 'order_status_history', 'order_items'] as $table) {
            $t->sql("DELETE FROM `{$table}` WHERE `order_id` = ?", 'i', [$id]);
        }
        $t->sql("DELETE FROM `orders` WHERE `id` = ?", 'i', [$id]);
        $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$email]);
    });
    return ['number' => $number, 'id' => $id, 'token' => $token];
};

$open = static function (int $port, string $orderNumber, array $headers = []) use ($api): array {
    $buf = new ArrayObject(['data' => '', 'headers' => '']);
    $ch = curl_init($api($port) . "/checkout/orders/{$orderNumber}/events");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => array_merge(['Accept: text/event-stream', 'Accept-Encoding: gzip'], $headers),
        CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use ($buf): int {
            $buf['data'] .= $chunk;
            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => static function ($c, string $h) use ($buf): int {
            $buf['headers'] .= $h;
            return strlen($h);
        },
        CURLOPT_TIMEOUT => 30,
    ]);
    $mh = curl_multi_init();
    curl_multi_add_handle($mh, $ch);
    return ['ch' => $ch, 'mh' => $mh, 'buf' => $buf, 'opened' => microtime(true)];
};
/** Read for up to $seconds, or until $until(data) is true. Returns whether $until was met (or the stream ended). */
$pump = static function (array $s, float $seconds, ?callable $until = null): bool {
    $end = microtime(true) + $seconds;
    do {
        curl_multi_exec($s['mh'], $running);
        if ($until !== null && $until($s['buf']['data'])) {
            return true;
        }
        if (!$running) {
            return $until === null;
        }
        curl_multi_select($s['mh'], 0.05);
    } while (microtime(true) < $end);
    return false;
};
$close = static function (array $s): void {
    curl_multi_remove_handle($s['mh'], $s['ch']);
    curl_close($s['ch']);
    curl_multi_close($s['mh']);
};
/** @return list<array{event: string, id: ?string, data: mixed, comment: bool}> */
$events = static function (string $data): array {
    $out = [];
    foreach (preg_split("/\r?\n\r?\n/", $data) as $block) {
        if (trim($block) === '') {
            continue;
        }
        $e = ['event' => 'message', 'id' => null, 'data' => null, 'comment' => false];
        foreach (preg_split("/\r?\n/", $block) as $line) {
            if (str_starts_with($line, ':')) {
                $e['comment'] = true;
            } elseif (str_starts_with($line, 'event: ')) {
                $e['event'] = substr($line, 7);
            } elseif (str_starts_with($line, 'id: ')) {
                $e['id'] = substr($line, 4);
            } elseif (str_starts_with($line, 'data: ')) {
                $e['data'] = json_decode(substr($line, 6), true);
            }
        }
        if ($e['data'] !== null || $e['comment']) {
            $out[] = $e;
        }
    }
    return $out;
};
$payments = static fn (string $data): array => array_values(array_filter($events($data), static fn (array $e): bool => $e['event'] === 'payment'));
$has = static fn (string $status) => static fn (string $data): bool => in_array($status, array_column(array_column($payments($data), 'data'), 'status'), true);
$ended = static fn (string $data): bool => str_contains($data, "event: end\n");
$slots = static fn (int $orderId): int => (int) $t->scalar("SELECT COUNT(*) FROM `rate_limits` WHERE `id` LIKE ?", 's', ["sse:{$orderId}:%"]);
$clearOpenLimit = static fn () => RateLimiter::clear('sse_open', '127.0.0.1');

// -----------------------------------------------------------------------------
$t->section('Who may listen: the same rules as verify-payment');
$a = $newOrder();
$b = $newOrder();
$noAccess = $t->http('GET', $api($ports[0]) . "/checkout/orders/{$a['number']}/events");
$t->check('no access key -> 404', 404, $noAccess['status']);
$t->check('same answer as verify-payment', TestKit::get($t->http('GET', "/checkout/orders/{$a['number']}/verify-payment"), 'error.code') ?? 'ORDER_NOT_FOUND', TestKit::get($noAccess, 'error.code') ?? 'ORDER_NOT_FOUND');
$t->check('wrong access key -> 404', 404, $t->http('GET', $api($ports[0]) . "/checkout/orders/{$a['number']}/events", ['headers' => ['X-Guest-Access-Token: ' . str_repeat('ab', 32)]])['status']);
$t->check("another order's key -> 404", 404, $t->http('GET', $api($ports[0]) . "/checkout/orders/{$a['number']}/events", ['headers' => ["X-Guest-Access-Token: {$b['token']}"]])['status']);
$t->check('unknown order -> 404', 404, $t->http('GET', $api($ports[0]) . '/checkout/orders/ORD-20260101-ZZZZZZ/events', ['headers' => ["X-Guest-Access-Token: {$a['token']}"]])['status']);

// -----------------------------------------------------------------------------
$t->section('Current state first, then a committed payment within seconds');
$clearOpenLimit();
$s = $open($ports[0], $a['number'], ["X-Guest-Access-Token: {$a['token']}"]);
$gotFirst = $pump($s, 3, static fn (string $d): bool => $payments($d) !== []);
$t->ok('first message arrives within 3 s', $gotFirst && microtime(true) - $s['opened'] < 3);
$t->check('stream answers 200', 200, curl_getinfo($s['ch'], CURLINFO_RESPONSE_CODE));
$t->ok('content type text/event-stream', (bool) preg_match('/^content-type: text\/event-stream/mi', $s['buf']['headers']));
$t->ok('never compressed', !preg_match('/^content-encoding:/mi', $s['buf']['headers']));
$t->ok('not cached, and not to be compressed or changed by proxies', (bool) preg_match('/^cache-control: no-store, no-transform/mi', $s['buf']['headers']));
$t->ok('reconnect delay sent', str_contains($s['buf']['data'], "retry: 3000\n"));
$first = $payments($s['buf']['data'])[0] ?? ['data' => [], 'id' => null];
$t->check('first message: the current state', ['order_number' => $a['number'], 'status' => 'pending', 'order_status' => 'pending_payment'], $first['data']);
$t->ok('payload holds only the three fields (no amount, name, email or address)', array_keys((array) $first['data']) === ['order_number', 'status', 'order_status']);
$t->ok('message has an id', ($first['id'] ?? '') !== '');
$t->check('one slot taken for the order', 1, $slots($a['id']));

$finalizer->recordManualPayment($a['id'], 'LIVE-' . bin2hex(random_bytes(4)), null, 'Live status test');
$committedAt = microtime(true);
$announced = $pump($s, 5, $has('successful'));
$t->ok('payment announced within 3 s of the commit', $announced && microtime(true) - $committedAt < 3, sprintf('%.2f s', microtime(true) - $committedAt));
$t->ok('then the stream ends as final', $pump($s, 3, $ended) && str_contains($s['buf']['data'], '"reason":"final"'));
$last = array_slice($payments($s['buf']['data']), -1)[0]['data'] ?? [];
$t->check('final state: paid', ['order_number' => $a['number'], 'status' => 'successful', 'order_status' => 'paid'], $last);
$close($s);
usleep(300000);
$t->check('slot released when the stream ends', 0, $slots($a['id']));
$t->ok('a new stream for a paid order sends the state and ends at once', (function () use ($open, $pump, $ports, $a, $ended, $has, $close): bool {
    $s = $open($ports[1], $a['number'], ["X-Guest-Access-Token: {$a['token']}"]);
    $ok = $pump($s, 3, $ended) && $has('successful')($s['buf']['data']);
    $close($s);
    return $ok;
})());

// -----------------------------------------------------------------------------
$t->section('Uncommitted and rolled-back changes are never announced');
$clearOpenLimit();
$c = $newOrder();
$s = $open($ports[0], $c['number'], ["X-Guest-Access-Token: {$c['token']}"]);
$pump($s, 3, static fn (string $d): bool => $payments($d) !== []);
$seenWhileOpen = null;
$db->transaction(function () use ($finalizer, $c, $pump, $s, $has, &$seenWhileOpen): void {
    $finalizer->recordManualPayment($c['id'], 'LIVE-' . bin2hex(random_bytes(4)), null, 'Uncommitted');
    // Paid inside this open transaction: the stream reads committed data only.
    $seenWhileOpen = $pump($s, 2.5, $has('successful'));
});
$t->check('a payment inside an open transaction is not announced', false, $seenWhileOpen);
$t->ok('after the commit it is', $pump($s, 4, $has('successful')));
$close($s);

$d = $newOrder();
$s = $open($ports[0], $d['number'], ["X-Guest-Access-Token: {$d['token']}"]);
$pump($s, 3, static fn (string $x): bool => $payments($x) !== []);
try {
    $db->transaction(function () use ($finalizer, $d, $pump, $s): void {
        $finalizer->recordManualPayment($d['id'], 'LIVE-' . bin2hex(random_bytes(4)), null, 'Rolled back');
        $pump($s, 1.5);
        throw new RuntimeException('roll back');
    });
} catch (RuntimeException) {
}
$t->ok('heartbeats keep a quiet stream alive', $pump($s, 8, $ended) && count(array_filter($events($s['buf']['data']), static fn (array $e): bool => $e['comment'])) >= 1);
$t->ok('a rolled-back payment is never announced', !$has('successful')($s['buf']['data']));
$t->ok('the stream ends at its time limit', str_contains($s['buf']['data'], '"reason":"time_limit"'));
$t->ok('within the limit (6 s here, 110 s by default)', microtime(true) - $s['opened'] < 9);
$close($s);
usleep(300000);
$t->check('slot released after the time limit', 0, $slots($d['id']));

// -----------------------------------------------------------------------------
$t->section('Limits: per order and per server');
$clearOpenLimit();
$e = $newOrder();
$f = $newOrder();
$e1 = $open($ports[0], $e['number'], ["X-Guest-Access-Token: {$e['token']}"]);
$pump($e1, 3, static fn (string $x): bool => $payments($x) !== []);
$e2 = $open($ports[1], $e['number'], ["X-Guest-Access-Token: {$e['token']}"]);
$pump($e2, 3, static fn (string $x): bool => $payments($x) !== []);
$t->check('two streams for one order (two tabs)', 2, $slots($e['id']));
$third = $t->http('GET', $api($ports[3]) . "/checkout/orders/{$e['number']}/events", ['headers' => ["X-Guest-Access-Token: {$e['token']}"]]);
$t->check('a third stream for the same order -> 503', 503, $third['status']);
$t->check('with the code SSE_BUSY', 'SSE_BUSY', TestKit::get($third, 'error.code') ?? TestKit::get($third, 'code'));
$t->ok('and Retry-After', isset($third['headers']['retry-after']));
$f1 = $open($ports[2], $f['number'], ["X-Guest-Access-Token: {$f['token']}"]);
$pump($f1, 3, static fn (string $x): bool => $payments($x) !== []);
$t->check('server full: a stream for another order -> 503', 503, $t->http('GET', $api($ports[3]) . "/checkout/orders/{$f['number']}/events", ['headers' => ["X-Guest-Access-Token: {$f['token']}"]])['status']);
foreach ([$e1, $e2, $f1] as $st) {
    $pump($st, 8, $ended);
    $close($st);
}
usleep(300000);
$t->check('all slots free again', 0, (int) $t->scalar("SELECT COUNT(*) FROM `rate_limits` WHERE `id` LIKE 'sse:%'"));

$t->section('Limits in this process: per visitor, and slots of killed processes expire');
$live = new LiveStatus($db);
$taken = [];
for ($i = 0; $i < LiveStatus::PER_VISITOR; $i++) {
    $taken[] = $live->claimSlot(900000 + $i, '203.0.113.9');
}
$t->ok('a visitor may hold ' . LiveStatus::PER_VISITOR . ' streams', !in_array(null, $taken, true));
$t->check('one more for the same visitor is refused', null, $live->claimSlot(900100, '203.0.113.9'));
$live->releaseSlot((string) $taken[0]);
$again = $live->claimSlot(900100, '203.0.113.9');
$t->ok('a released slot can be taken again', $again !== null);
foreach (array_merge(array_slice($taken, 1), [$again]) as $slot) {
    $live->releaseSlot((string) $slot);
}
$t->sql("INSERT INTO `rate_limits` (`id`, `hits`, `window_start`) VALUES (?, 1, ?), (?, 1, ?)", 'sisi', [
    'sse:900200:aaaaaaaaaaaaaaaa:0000000000000001', time() - (LiveStatus::SLOT_TTL_SECONDS + 1),
    'sse:900200:aaaaaaaaaaaaaaaa:0000000000000002', time() - (LiveStatus::SLOT_TTL_SECONDS + 1),
]);
$fresh = $live->claimSlot(900200, '203.0.113.10');
$t->ok('slots nobody renews (a killed process, a restarted server) stop counting after ' . LiveStatus::SLOT_TTL_SECONDS . ' s, not the stream length', $fresh !== null && LiveStatus::SLOT_TTL_SECONDS < LiveStatus::streamSeconds());
$t->check('and are removed', 1, $slots(900200));
$live->releaseSlot((string) $fresh);
$a = $live->claimSlot(900300, '203.0.113.11');
$t->sql("UPDATE `rate_limits` SET `window_start` = ? WHERE `id` = ?", 'is', [time() - (LiveStatus::SLOT_TTL_SECONDS - 2), (string) $a]);
$live->renewSlot((string) $a);
$b = $live->claimSlot(900300, '203.0.113.12');
$t->ok('a renewed slot keeps counting (the order limit still holds)', $b !== null && $live->claimSlot(900300, '203.0.113.13') === null);
$live->releaseSlot((string) $a);
$live->renewSlot((string) $a);
$t->check('a late renewal puts a cleared slot back, so a running stream is never left uncounted', 2, $slots(900300));
$live->releaseSlot((string) $a);
$live->releaseSlot((string) $b);

// -----------------------------------------------------------------------------
$t->section('A running stream renews its slot');
$clearOpenLimit();
$g = $newOrder();
$s = $open($ports[0], $g['number'], ["X-Guest-Access-Token: {$g['token']}"]);
$pump($s, 3, static fn (string $x): bool => $payments($x) !== []);
$t->sql("UPDATE `rate_limits` SET `window_start` = ? WHERE `id` LIKE ?", 'is', [time() - (LiveStatus::SLOT_TTL_SECONDS - 5), "sse:{$g['id']}:%"]);
// These APIs beat every 2 s, so the stream renews within 2 s (well inside its 6 s length).
$pump($s, 3);
$age = time() - (int) $t->scalar("SELECT MAX(`window_start`) FROM `rate_limits` WHERE `id` LIKE ?", 's', ["sse:{$g['id']}:%"]);
$t->ok('within one heartbeat the stream marks its slot as live again (' . $age . ' s old)', $age <= 3);
$close($s);
$until = microtime(true) + 6;
while (microtime(true) < $until && $slots($g['id']) > 0) {
    usleep(250000);
}

// -----------------------------------------------------------------------------
$t->section('A closed tab frees its worker');
$clearOpenLimit();
$g = $newOrder();
$s = $open($ports[0], $g['number'], ["X-Guest-Access-Token: {$g['token']}"]);
$pump($s, 3, static fn (string $x): bool => $payments($x) !== []);
$close($s);
$freed = false;
$until = microtime(true) + 5;
while (microtime(true) < $until && !$freed) {
    usleep(250000);
    $freed = $slots($g['id']) === 0;
}
$t->ok('slot released within two heartbeats of the tab closing (PHP notices at the second write)', $freed);
$startedAt = microtime(true);
$health = @file_get_contents($api($ports[0]) . '/health');
$t->ok('the same server answers other requests again', $health !== false && microtime(true) - $startedAt < 2);

// -----------------------------------------------------------------------------
$t->section('Housekeeping');
$t->check('streams are not written to the slow-request log', 0, (int) $t->scalar("SELECT COUNT(*) FROM `api_slow_requests` WHERE `route` LIKE '%/events'"));
$t->ok('the stream never changed an unpaid order', $t->scalar("SELECT `order_status` FROM `orders` WHERE `id` = ?", 'i', [$g['id']]) === 'pending_payment');

exit($t->finish());
