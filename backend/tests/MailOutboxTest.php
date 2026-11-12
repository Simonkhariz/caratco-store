<?php

declare(strict_types=1);

/**
 * Email outbox and SMTP transport checks (run against the dev database; cleans up after itself).
 *
 *   php backend/tests/MailOutboxTest.php
 *
 * A tiny fake SMTP server runs in a child process so the real protocol code is exercised:
 * accepted mail, a rejected recipient, retries with backoff, giving up after 5 attempts,
 * log mode, dedup, newsletter unsubscribes, and that sign-in links are never re-sent.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/src/autoload.php';

use App\Config\Env;
use App\Database\Database;
use App\Services\MailService;
use App\Services\MailTemplates;

Env::load(BASE_PATH . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Africa/Lagos') ?: 'Africa/Lagos');

if (getenv('SHOPCO_ENV_FILE') === false && getenv('ALLOW_DEV_DB') !== '1') {
    fwrite(STDERR, "Run this through php backend/tests/run.php (throw-away database), or set ALLOW_DEV_DB=1 to use the configured database on purpose.
");
    exit(2);
}

$pass = 0;
$fail = 0;
function check(string $label, $expected, $actual): void
{
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        echo "PASS  {$label}\n";
    } else {
        $fail++;
        echo "FAIL  {$label} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n";
    }
}

function setEnv(array $values): void
{
    $prop = new ReflectionProperty(Env::class, 'vars');
    $prop->setAccessible(true);
    $prop->setValue(null, array_merge($prop->getValue(), $values));
}

$db = Database::getInstance();
$domain = 'mailtest.example';
$cleanup = static function () use ($db, $domain): void {
    $db->execute("DELETE FROM `email_outbox` WHERE `recipient_email` LIKE ?", 's', ["%@{$domain}"]);
    $db->execute("DELETE FROM `newsletter_subscribers` WHERE `email` LIKE ?", 's', ["%@{$domain}"]);
};
$cleanup();

$row = static fn (int $id): array => $db->fetchOne("SELECT * FROM `email_outbox` WHERE `id` = ?", 'i', [$id]);
$lastId = static fn (string $email): int => (int) ($db->fetchOne("SELECT MAX(`id`) AS id FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$email])['id'] ?? 0);

// -----------------------------------------------------------------------------
// Fake SMTP server
// -----------------------------------------------------------------------------
$port = 2525 + random_int(0, 400);
$captureFile = sys_get_temp_dir() . '/shopco-smtp-' . getmypid() . '.eml';
@unlink($captureFile);
$serverCode = <<<'PHP'
$port = (int) $argv[1]; $capture = $argv[2];
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (!$server) { fwrite(STDERR, "listen failed\n"); exit(1); }
echo "ready\n"; fflush(STDOUT);
while ($conn = @stream_socket_accept($server, 30)) {
    fwrite($conn, "220 fake ESMTP\r\n");
    $data = false; $message = '';
    while (($line = fgets($conn)) !== false) {
        if ($data) {
            if ($line === ".\r\n") { file_put_contents($capture, $message); fwrite($conn, "250 queued\r\n"); $data = false; continue; }
            $message .= $line; continue;
        }
        $cmd = strtoupper(substr($line, 0, 4));
        if ($cmd === 'EHLO') { fwrite($conn, "250-fake\r\n250 OK\r\n"); }
        elseif ($cmd === 'MAIL') { fwrite($conn, "250 sender ok\r\n"); }
        elseif ($cmd === 'RCPT') { fwrite($conn, str_contains($line, 'reject') ? "550 no such user\r\n" : "250 rcpt ok\r\n"); }
        elseif ($cmd === 'DATA') { fwrite($conn, "354 go ahead\r\n"); $data = true; }
        elseif ($cmd === 'QUIT') { fwrite($conn, "221 bye\r\n"); break; }
        else { fwrite($conn, "250 OK\r\n"); }
    }
    fclose($conn);
}
PHP;
$serverFile = sys_get_temp_dir() . '/shopco-fake-smtp-' . getmypid() . '.php';
file_put_contents($serverFile, "<?php\n" . $serverCode);
$proc = proc_open([PHP_BINARY, $serverFile, (string) $port, $captureFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$ready = trim((string) fgets($pipes[1]));
check('fake SMTP server started', 'ready', $ready);

try {
    // -------------------------------------------------------------------------
    echo "== SMTP delivery\n";
    setEnv([
        'MAIL_MAILER' => 'smtp', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => (string) $port, 'MAIL_ENCRYPTION' => 'none',
        'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '', 'MAIL_FROM_ADDRESS' => "store@{$domain}", 'MAIL_FROM_NAME' => '',
        'MAIL_SEND_IMMEDIATELY' => 'true',
    ]);
    $mail = new MailService($db);
    check('mode is smtp', 'smtp', MailService::mode());

    $to = "buyer@{$domain}";
    $db->execute("INSERT INTO `newsletter_subscribers` (`email`, `status`, `source`, `unsubscribe_token`) VALUES (?, 'active', 'test', ?)", 'ss', [$to, bin2hex(random_bytes(16))]);
    $mail->queue($to, 'marketing_broadcast', ['subject' => 'Naira deals ₦ <b>today</b>', 'content' => "Line one\n\n<script>alert(1)</script>", 'unsubscribe_url' => 'https://shop.example/unsubscribe?token=abc'], null);
    $sent = $row($lastId($to));
    check('accepted message is marked sent', 'sent', $sent['status']);
    check('transport recorded as smtp', 'smtp', $sent['transport']);
    $eml = (string) @file_get_contents($captureFile);
    preg_match('/^Subject: (.+(?:
[ 	].+)*)/m', $eml, $sm);
    check('non-ASCII subject is RFC 2047 encoded', true, str_contains($sm[1] ?? '', '=?UTF-8?B?'));
    check('subject decodes back exactly', 'Naira deals ₦ <b>today</b>', mb_decode_mimeheader(trim($sm[1] ?? '')));
    check('List-Unsubscribe header present', true, str_contains($eml, 'List-Unsubscribe: <https://shop.example/unsubscribe?token=abc>'));
    preg_match('/Content-Type: text\/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/', $eml, $m);
    $html = base64_decode(str_replace("\r\n", '', $m[1] ?? ''));
    check('HTML body escapes script content', true, str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'));

    echo "== Rejected recipient: retry with backoff, then give up\n";
    $bad = "reject@{$domain}";
    $db->execute("INSERT INTO `newsletter_subscribers` (`email`, `status`, `source`, `unsubscribe_token`) VALUES (?, 'active', 'test', ?)", 'ss', [$bad, bin2hex(random_bytes(16))]);
    $mail->queue($bad, 'marketing_broadcast', ['subject' => 'Hi', 'content' => 'x', 'unsubscribe_url' => 'https://shop.example/u'], null);
    $id = $lastId($bad);
    $r = $row($id);
    check('refused recipient is NOT marked sent', 'pending', $r['status']);
    check('first failure counted', 1, (int) $r['attempt_count']);
    check('server reply kept as the error', true, str_contains((string) $r['last_error'], '550'));
    check('next attempt scheduled in the future', true, strtotime((string) $r['next_attempt_at']) > time());
    check('not due yet: worker skips it', null, $mail->deliver($id));
    for ($i = 0; $i < 4; $i++) {
        $db->execute("UPDATE `email_outbox` SET `next_attempt_at` = NULL WHERE `id` = ?", 'i', [$id]);
        $mail->deliver($id);
    }
    $r = $row($id);
    check('gives up after 5 attempts', 'failed', $r['status']);
    check('attempt count is 5', 5, (int) $r['attempt_count']);

    echo "== Connection failure\n";
    setEnv(['MAIL_PORT' => '1']);
    $down = "down@{$domain}";
    $db->execute("INSERT INTO `newsletter_subscribers` (`email`, `status`, `source`, `unsubscribe_token`) VALUES (?, 'active', 'test', ?)", 'ss', [$down, bin2hex(random_bytes(16))]);
    $mail->queue($down, 'marketing_broadcast', ['subject' => 'Hi', 'content' => 'x', 'unsubscribe_url' => 'https://shop.example/u'], null);
    check('unreachable server leaves the email pending', 'pending', $row($lastId($down))['status']);

    // -------------------------------------------------------------------------
    echo "== Log mode\n";
    setEnv(['MAIL_MAILER' => 'log']);
    check('mode is log_only', 'log_only', MailService::mode());
    $logged = "logged@{$domain}";
    $db->execute("INSERT INTO `newsletter_subscribers` (`email`, `status`, `source`, `unsubscribe_token`) VALUES (?, 'active', 'test', ?)", 'ss', [$logged, bin2hex(random_bytes(16))]);
    $mail->queue($logged, 'marketing_broadcast', ['subject' => 'Logged', 'content' => 'x', 'unsubscribe_url' => 'https://shop.example/u'], 'test-dedup-' . getmypid());
    $r = $row($lastId($logged));
    check('log mode records transport log', 'log', $r['transport']);
    check('log mode marks sent', 'sent', $r['status']);
    $mail->queue($logged, 'marketing_broadcast', ['subject' => 'Logged', 'content' => 'x', 'unsubscribe_url' => 'https://shop.example/u'], 'test-dedup-' . getmypid());
    check('dedup key prevents a second copy', 1, (int) $db->fetchOne("SELECT COUNT(*) AS c FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$logged])['c']);

    echo "== Newsletter respects unsubscribes at send time\n";
    $gone = "gone@{$domain}";
    $db->execute("INSERT INTO `newsletter_subscribers` (`email`, `status`, `source`, `unsubscribe_token`) VALUES (?, 'unsubscribed', 'test', ?)", 'ss', [$gone, bin2hex(random_bytes(16))]);
    $mail->queueBroadcast($gone, 'Hi', 'x', 'https://shop.example/u');
    check('broadcast is queued, not sent inline', 'pending', $row($lastId($gone))['status']);
    $mail->processQueue(500);
    $r = $row($lastId($gone));
    check('unsubscribed recipient is skipped', 'failed', $r['status']);
    check('skip reason recorded', true, str_contains((string) $r['last_error'], 'unsubscribed'));

    echo "== Retry rules\n";
    $mail->sendAccountVerification("signin@{$domain}", bin2hex(random_bytes(16)));
    $magic = $row($lastId("signin@{$domain}"));
    check('sign-in email recorded without the token', false, str_contains((string) $magic['payload'], 'auth/verify'));
    check('sign-in link cannot be re-sent', false, $mail->retryOutboxItem((int) $magic['id'])['ok']);
    $db->execute(
        "INSERT INTO `email_outbox` (`recipient_email`, `subject`, `template`, `payload`, `status`) VALUES (?, 'Old', 'payment_verified', '{\"order_number\":\"X\"}', 'failed')",
        's',
        ["legacy@{$domain}"]
    );
    check('legacy payload cannot be re-sent', false, $mail->retryOutboxItem($lastId("legacy@{$domain}"))['ok']);
    $db->execute("UPDATE `email_outbox` SET `status` = 'failed' WHERE `id` = ?", 'i', [$lastId($down)]);
    check('a failed renderable email can be retried', true, $mail->retryOutboxItem($lastId($down))['ok']);
    check('retried email reuses the same row', 1, (int) $db->fetchOne("SELECT COUNT(*) AS c FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$down])['c']);

    echo "== Templates\n";
    $t = new MailTemplates($db);
    $order = [
        'v' => 2, 'order_number' => 'ORD-T-1', 'customer_name' => '<img src=x onerror=alert(1)> Ada', 'currency' => 'NGN',
        'subtotal' => 10000, 'discount_amount' => 1000, 'promo_code' => 'SAVE10', 'shipping_fee' => 2500, 'grand_total' => 11500,
        'zone_name' => 'Lagos', 'items' => [['name' => 'Tee', 'options' => 'M / Black', 'quantity' => 1, 'line_total' => 10000]],
        'address' => ['full_name' => 'Ada', 'address_line1' => '1 Road', 'city' => 'Ikeja', 'state' => 'Lagos'],
    ];
    $rendered = $t->render('order_received', $order);
    check('customer name is escaped', false, str_contains($rendered['html'], '<img src=x'));
    check('order link uses ?order=', true, str_contains($rendered['html'], '/track-order?order=ORD-T-1'));
    check('totals show the promo code', true, str_contains($rendered['text'], 'Discount (SAVE10)'));
    check('no invented fallback references', false, str_contains($t->render('payment_verified', $order)['text'], 'VERIFIED'));
    $ship = $t->render('shipment_tracking', $order + ['carrier' => 'GIG', 'tracking_number' => 'T1', 'tracking_url' => 'javascript:alert(1)']);
    check('non-https tracking link is dropped', false, str_contains($ship['html'], 'javascript:'));
} finally {
    $cleanup();
    proc_terminate($proc);
    proc_close($proc);
    @unlink($serverFile);
    @unlink($captureFile);
}

echo "\nRESULT: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
