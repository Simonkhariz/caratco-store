<?php

declare(strict_types=1);

/**
 * Email never makes the shopper or admin wait (roadmap Prompt 17): during a web request the
 * delivery attempt runs after the response has been handed over. This suite starts its own
 * fake SMTP server (with an adjustable delay) and a second test API that sends through it.
 *
 *   php backend/tests/run.php MailAsync
 */

require __DIR__ . '/support/TestKit.php';

use App\Services\MailService;

$t = new TestKit('MailAsync');
$tmp = sys_get_temp_dir() . '/shopco-mailasync-' . getmypid();
@mkdir($tmp, 0700, true);
$delayFile = "{$tmp}/delay";
$countFile = "{$tmp}/count";
file_put_contents($delayFile, '0');
file_put_contents($countFile, '');
$smtpPort = 2600 + getmypid() % 300;
$apiPort = (int) (getenv('TEST_MAIL_API_PORT') ?: 8102);
$api = "http://127.0.0.1:{$apiPort}/api/v1";

// A local SMTP stand-in: waits <delay> seconds before greeting, accepts every message.
file_put_contents("{$tmp}/smtp.php", <<<'PHP'
<?php
[$self, $port, $delayFile, $countFile] = $argv;
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
echo $server ? "ready\n" : "failed {$errstr}\n";
while ($server && ($conn = @stream_socket_accept($server, -1))) {
    $delay = (float) trim((string) @file_get_contents($delayFile));
    if ($delay > 0) usleep((int) ($delay * 1_000_000));
    $say = static fn (string $l) => @fwrite($conn, $l . "\r\n");
    $say('220 fake ESMTP');
    $data = false;
    $rcpt = '';
    while (($line = fgets($conn)) !== false) {
        if ($data) {
            if (rtrim($line, "\r\n") === '.') { $data = false; file_put_contents($countFile, $rcpt . "\n", FILE_APPEND); $say('250 OK'); }
            continue;
        }
        $cmd = strtoupper(substr(trim($line), 0, 4));
        if ($cmd === 'RCPT' && preg_match('/<([^>]+)>/', $line, $m)) { $rcpt = strtolower($m[1]); }
        if ($cmd === 'EHLO' || $cmd === 'HELO') { $say('250-fake'); $say('250 8BITMIME'); }
        elseif ($cmd === 'DATA') { $data = true; $say('354 go'); }
        elseif ($cmd === 'QUIT') { $say('221 bye'); break; }
        else { $say('250 OK'); }
    }
    fclose($conn);
}
PHP);
$smtp = proc_open([PHP_BINARY, "{$tmp}/smtp.php", (string) $smtpPort, $delayFile, $countFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $smtpPipes);
$t->check('fake SMTP server started', 'ready', trim((string) fgets($smtpPipes[1])));

// A second test API on the same test database, sending through the fake server with a short
// mail timeout (2 s).
$envFile = "{$tmp}/env";
$lines = array_values(array_filter(
    file((string) getenv('SHOPCO_ENV_FILE'), FILE_IGNORE_NEW_LINES) ?: [],
    static fn (string $l): bool => !preg_match('/^(MAIL_[A-Z_]+|STOREFRONT_REVALIDATE_URL|REVALIDATE_SECRET)=/', $l)
));
$lines = array_merge($lines, [
    'MAIL_MAILER=smtp', 'MAIL_HOST=127.0.0.1', "MAIL_PORT={$smtpPort}", 'MAIL_ENCRYPTION=none', 'MAIL_USERNAME=', 'MAIL_PASSWORD=',
    'MAIL_FROM_ADDRESS=store@example.test', 'MAIL_SEND_IMMEDIATELY=true', 'MAIL_TIMEOUT_SECONDS=2', 'MAIL_LOG_PATH=storage/logs/mail.testing.log',
    'STOREFRONT_REVALIDATE_URL=', 'REVALIDATE_SECRET=',
]);
file_put_contents($envFile, implode("\n", $lines) . "\n");
$apiProc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$apiPort}", '-t', BASE_PATH . '/public'],
    [1 => ['file', "{$tmp}/api.log", 'w'], 2 => ['file', "{$tmp}/api.log", 'a']],
    $apiPipes,
    BASE_PATH,
    array_merge(getenv(), ['SHOPCO_ENV_FILE' => $envFile])
);
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(200000);
    $up = @file_get_contents("{$api}/health") !== false;
}
$t->check('second test API started', true, $up);

$t->cleanup(function () use ($smtp, $apiProc, $tmp): void {
    proc_terminate($apiProc);
    proc_terminate($smtp);
    array_map('unlink', glob("{$tmp}/*") ?: []);
    @rmdir($tmp);
});

$messages = static fn (): int => count(file($countFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
$waitFor = static function (callable $condition, float $seconds): bool {
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        if ($condition()) {
            return true;
        }
        usleep(100000);
    }
    return (bool) $condition();
};
$variant = $t->variant(10);
$placeOrder = function (string $email) use ($t, $api, $variant): array {
    $t->sql("DELETE FROM `rate_limits`");
    $start = microtime(true);
    $res = $t->http('POST', "{$api}/checkout/orders", [
        'headers' => ['Idempotency-Key: ' . bin2hex(random_bytes(16))],
        'json' => [
            'email' => $email,
            'items' => [['product_id' => (int) $variant['product_id'], 'variant_id' => (int) $variant['variant_id'], 'quantity' => 1]],
            'shipping_address' => ['full_name' => 'Mail Async', 'phone' => '08000000011', 'address_line1' => '11 Test Way', 'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria'],
        ],
    ]);
    $onum = (string) TestKit::get($res, 'data.order_number');
    $t->cleanup(function () use ($t, $onum, $email): void {
        $oid = (int) $t->scalar("SELECT `id` FROM `orders` WHERE `order_number` = ?", 's', [$onum]);
        foreach (['payments', 'order_status_history', 'order_items'] as $table) {
            $t->sql("DELETE FROM `{$table}` WHERE `order_id` = ?", 'i', [$oid]);
        }
        $t->sql("DELETE FROM `orders` WHERE `id` = ?", 'i', [$oid]);
        $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$email]);
    });
    return ['status' => $res['status'], 'seconds' => microtime(true) - $start, 'order' => $onum, 'headers' => $res['headers'], 'body' => $res['body']];
};
$outbox = static fn (string $email): ?array => $t->db->fetchOne(
    "SELECT `status`, `attempt_count`, `last_error` FROM `email_outbox` WHERE `recipient_email` = ? AND `template` = 'order_received' ORDER BY `id` DESC LIMIT 1",
    's',
    [$email]
);

// -----------------------------------------------------------------------------
$t->section('A slow mail server does not slow checkout');
if ($variant === null) {
    $t->ok('a published variant with stock exists', false);
} else {
    file_put_contents($delayFile, '1.5');
    $before = $messages();
    $email = $t->uniq('slow') . '@example.test';
    $order = $placeOrder($email);
    $t->check('order placed', 201, $order['status']);
    $t->ok('checkout answers without waiting for the 1.5 s mail server', $order['seconds'] < 1.0, sprintf('%.2f s', $order['seconds']));
    $t->ok('the order email is delivered right after', $waitFor(fn () => ($outbox($email)['status'] ?? null) === 'sent', 6), json_encode($outbox($email)));
    $t->check('the mail server received it once', $before + 1, $messages());
    $length = $order['headers']['content-length'] ?? null;
    $t->check('the response states its length', (string) strlen($order['body']), $length);

    // ---------------------------------------------------------------------------
    $t->section('A stuck mail server costs at most the mail timeout, after the response');
    file_put_contents($delayFile, '6');
    $email = $t->uniq('stuck') . '@example.test';
    $order = $placeOrder($email);
    $t->check('order placed', 201, $order['status']);
    $t->ok('checkout still answers at once', $order['seconds'] < 1.0, sprintf('%.2f s', $order['seconds']));
    $t->ok('the attempt gives up after about 2 s and is kept for a retry', $waitFor(fn () => (int) ($outbox($email)['attempt_count'] ?? 0) === 1, 6), json_encode($outbox($email)));
    $row = $outbox($email);
    $t->check('still pending, to be retried by the worker', 'pending', $row['status'] ?? null);
    file_put_contents($delayFile, '0');
    sleep(5); // let the fake server finish the slow connection it was holding

    // ---------------------------------------------------------------------------
    $t->section('Tidying up expired orders adds no wait to checkout');
    file_put_contents($delayFile, '1');
    $expired = [];
    for ($i = 1; $i <= 2; $i++) {
        $num = 'MAEXP' . getmypid() . $i;
        $mail = strtolower($num) . '@example.test';
        $t->db->insert(
            "INSERT INTO `orders` (`order_number`, `guest_email`, `guest_access_token_hash`, `subtotal`, `grand_total`, `order_status`, `payment_status`, `expires_at`)
             VALUES (?, ?, SHA2(?, 256), 5000, 5000, 'pending_payment', 'pending', DATE_SUB(NOW(), INTERVAL 1 HOUR))",
            'sss',
            [$num, $mail, $num]
        );
        $expired[$num] = $mail;
    }
    $t->cleanup(function () use ($t, $expired): void {
        foreach ($expired as $num => $mail) {
            $oid = (int) $t->scalar("SELECT `id` FROM `orders` WHERE `order_number` = ?", 's', [$num]);
            $t->sql("DELETE FROM `order_status_history` WHERE `order_id` = ?", 'i', [$oid]);
            $t->sql("DELETE FROM `orders` WHERE `id` = ?", 'i', [$oid]);
            $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$mail]);
        }
    });
    $before = $messages();
    $email = $t->uniq('sweep') . '@example.test';
    $order = $placeOrder($email);
    $t->check('order placed', 201, $order['status']);
    $t->ok('checkout answers at once although 3 emails (1 s each) follow', $order['seconds'] < 1.0, sprintf('%.2f s', $order['seconds']));
    $t->check('the expired orders were cancelled', ['cancelled', 'cancelled'], array_map(
        fn (string $num) => $t->scalar("SELECT `order_status` FROM `orders` WHERE `order_number` = ?", 's', [$num]),
        array_keys($expired)
    ));
    $t->ok('all three emails are delivered after the response', $waitFor(fn () => $messages() >= $before + 3, 10), (string) ($messages() - $before));
    file_put_contents($delayFile, '0');
}

// -----------------------------------------------------------------------------
$t->section('Due emails are retried by ordinary requests, without a scheduler');
// The sweep sends the oldest due emails first (5 at a time). Emails other suites left waiting
// (admin alerts now go to the outbox worker) are parked for this section, then put back.
$parked = array_map('intval', array_column($t->db->fetchAll(
    "SELECT `id` FROM `email_outbox` WHERE `status` = 'pending' AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= NOW())"
), 'id'));
if ($parked !== []) {
    $t->sql('UPDATE `email_outbox` SET `next_attempt_at` = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE `id` IN (' . implode(',', $parked) . ')');
    $t->cleanup(fn () => $t->sql("UPDATE `email_outbox` SET `next_attempt_at` = NULL WHERE `status` = 'pending' AND `id` IN (" . implode(',', $parked) . ')'));
}
// The test server (php -S) answers one request at a time, so a send still running from the last
// section would hold up the timed request below and look like checkout-style waiting. Wait for it.
$t->http('GET', "{$api}/health");
$received = static fn (string $to): int => count(array_filter(
    file($countFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    static fn (string $l): bool => $l === strtolower($to)
));
$retryMailer = new MailService($t->db);
$dueEmail = static function (string $to) use ($t, $retryMailer): int {
    $retryMailer->queue($to, 'payment_claimed', ['order_number' => 'ORD-RETRY', 'amount' => 1000], null, false);
    $t->cleanup(fn () => $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [strtolower($to)]));
    return (int) $t->scalar("SELECT `id` FROM `email_outbox` WHERE `recipient_email` = ? ORDER BY `id` DESC LIMIT 1", 's', [strtolower($to)]);
};
$statusOf = static fn (int $id): ?string => $t->scalar("SELECT `status` FROM `email_outbox` WHERE `id` = ?", 'i', [$id]);
$freeSlot = static fn () => $t->sql("UPDATE `store_settings` SET `value` = '2000-01-01 00:00:00' WHERE `key_name` = 'outbox_last_sweep'");
$t->cleanup(fn () => $t->sql("DELETE FROM `store_settings` WHERE `key_name` = 'outbox_last_sweep'"));

file_put_contents($delayFile, '1.5');
$first = $t->uniq('retry1') . '@example.test';
$firstId = $dueEmail($first);
$freeSlot();
$start = microtime(true);
$res = $t->http('GET', "{$api}/categories");
$took = microtime(true) - $start;
$t->check('an ordinary request answers', 200, $res['status']);
$t->ok('without waiting for the 1.5 s mail server', $took < 1.0, sprintf('%.2f s', $took));
$t->ok('the due email is sent after the response', $waitFor(fn () => $statusOf($firstId) === 'sent', 6), (string) $statusOf($firstId));
$t->check('received once', 1, $received($first));

$second = $t->uniq('retry2') . '@example.test';
$secondId = $dueEmail($second);
$t->http('GET', "{$api}/categories");
sleep(3);
$t->check('at most one retry run a minute: the next request leaves it for later', 'pending', $statusOf($secondId));
$t->sql("UPDATE `store_settings` SET `value` = DATE_FORMAT(NOW() - INTERVAL 2 MINUTE, '%Y-%m-%d %H:%i:%s') WHERE `key_name` = 'outbox_last_sweep'");
$t->http('GET', "{$api}/categories");
$t->ok('a minute later a request sends it', $waitFor(fn () => $statusOf($secondId) === 'sent', 6), (string) $statusOf($secondId));

// The request retry run and the scheduled worker at the same moment never send one email twice.
file_put_contents($delayFile, '0.3');
$batch = [];
for ($i = 1; $i <= 8; $i++) {
    $to = $t->uniq("race{$i}") . '@example.test';
    $batch[$to] = $dueEmail($to);
}
$freeSlot();
$worker = proc_open([PHP_BINARY, BASE_PATH . '/scripts/process_outbox.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $workerPipes, BASE_PATH, array_merge(getenv(), ['SHOPCO_ENV_FILE' => $envFile]));
$t->http('GET', "{$api}/categories");
$workerOut = stream_get_contents($workerPipes[1]) . stream_get_contents($workerPipes[2]);
proc_close($worker);
$t->ok('with the worker running at the same time, every email is sent', $waitFor(fn () => count(array_filter($batch, fn (int $id) => $statusOf($id) === 'sent')) === count($batch), 15), trim($workerOut));
$twice = array_filter(array_keys($batch), fn (string $to) => $received($to) !== 1);
$t->check('and each recipient received it exactly once', [], array_values($twice));
file_put_contents($delayFile, '0');

// -----------------------------------------------------------------------------
$t->section('The scheduled worker: several batches per run, and a sending pace');
$runWorker = static function (array $args) use ($envFile): array {
    $start = microtime(true);
    $proc = proc_open(array_merge([PHP_BINARY, BASE_PATH . '/scripts/process_outbox.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, array_merge(getenv(), ['SHOPCO_ENV_FILE' => $envFile]));
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($proc);
    return [trim($out), microtime(true) - $start];
};
$queueSome = static function (string $name, int $n) use ($t, $dueEmail): array {
    $ids = [];
    for ($i = 1; $i <= $n; $i++) {
        $ids[$t->uniq("{$name}{$i}") . '@example.test'] = 0;
    }
    foreach (array_keys($ids) as $to) {
        $ids[$to] = $dueEmail($to);
    }
    return $ids;
};
$allSentOnce = static fn (array $ids): bool => count(array_filter($ids, fn (int $id) => $statusOf($id) === 'sent')) === count($ids)
    && array_filter(array_keys($ids), fn (string $to) => $received($to) !== 1) === [];

$batches = $queueSome('batches', 5);
[$out] = $runWorker(['--limit=2']);
$t->ok('without --max-seconds one run sends one batch', str_contains($out, 'processed=2 ') && count(array_filter($batches, fn (int $id) => $statusOf($id) === 'pending')) >= 3, $out);
[$out] = $runWorker(['--limit=2', '--max-seconds=20']);
$t->ok('with --max-seconds the run keeps taking batches until the queue is empty', $allSentOnce($batches), $out);

$paced = $queueSome('paced', 5);
[$out, $took] = $runWorker(['--limit=5', '--rate=4']);
$t->ok('--rate=4 sends 5 emails in no less than 1 second', $allSentOnce($paced) && $took >= 1.0, sprintf('%.2f s; %s', $took, $out));

$capped = $queueSome('capped', 6);
[$out, $took] = $runWorker(['--limit=6', '--rate=2', '--max-seconds=1']);
$left = count(array_filter($capped, fn (int $id) => $statusOf($id) === 'pending'));
$t->ok('--max-seconds also stops a paced run in time, leaving the rest queued', $took < 4 && $left >= 3 && $left < 6, sprintf('%.2f s, %d left; %s', $took, $left, $out));

// -----------------------------------------------------------------------------
$t->section('The same email is never queued twice');
$key = 'mailasync-dedup:' . bin2hex(random_bytes(4));
$to = $t->uniq('dedup') . '@example.test';
$t->cleanup(fn () => $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$to]));
$mailer = new MailService($t->db);
$t->check('first queue call', true, $mailer->queue($to, 'payment_claimed', ['order_number' => 'ORD-TEST', 'amount' => 1000], $key, false));
$t->check('second call with the same key reports it as queued', true, $mailer->queue($to, 'payment_claimed', ['order_number' => 'ORD-TEST', 'amount' => 1000], $key, false));
$t->check('one row for the key', 1, (int) $t->scalar("SELECT COUNT(*) FROM `email_outbox` WHERE `dedup_key` = ?", 's', [$key]));
$duplicate = null;
try {
    $t->db->insert(
        "INSERT INTO `email_outbox` (`recipient_email`, `subject`, `template`, `dedup_key`, `payload`, `status`, `attempt_count`) VALUES (?, 's', 'payment_claimed', ?, '{}', 'pending', 0)",
        'ss',
        [$to, $key]
    );
} catch (Throwable $e) {
    $duplicate = (int) $e->getCode();
}
$t->check('the database refuses a second row with the same key (what two simultaneous requests would hit)', 1062, $duplicate);

exit($t->finish());
