<?php

declare(strict_types=1);

/**
 * Resend transport (roadmap Prompt 18), against a local stand-in for Resend's API
 * (support/fake_resend.php): nothing is sent to Resend and no real address is used.
 * Covers configuration, the request Resend receives, the idempotency key that stops a retry
 * from becoming a second email, and how each kind of failure is retried or given up.
 *
 *   php backend/tests/run.php ResendTransport
 */

require __DIR__ . '/support/TestKit.php';

use App\Config\Env;
use App\Services\MailService;
use App\Services\ResendTransport;

$t = new TestKit('ResendTransport');

$setEnv = static function (array $values): void {
    $prop = new ReflectionProperty(Env::class, 'vars');
    $prop->setValue(null, array_merge($prop->getValue(), $values));
};
$dir = sys_get_temp_dir() . '/shopco-fake-resend-' . getmypid();
@mkdir($dir, 0700, true);
foreach (['mode' => 'ok', 'requests.log' => '', 'sent.log' => '', 'keys.json' => '{}'] as $file => $content) {
    file_put_contents("{$dir}/{$file}", $content);
}
$port = (int) (getenv('TEST_RESEND_PORT') ?: 8110);
$fake = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/support/fake_resend.php'],
    [1 => ['file', "{$dir}/server.log", 'w'], 2 => ['file', "{$dir}/server.log", 'a']],
    $pipes,
    BASE_PATH,
    array_merge(getenv(), ['FAKE_RESEND_DIR' => $dir])
);
$up = false;
for ($i = 0; $i < 40 && !$up; $i++) {
    usleep(150000);
    $up = @fsockopen('127.0.0.1', $port) !== false;
}
$t->check('fake Resend API started', true, $up);

$domain = 'resendtest.example';
$apiKey = 're_test_' . bin2hex(random_bytes(8));
$t->cleanup(function () use ($t, $fake, $dir, $domain): void {
    proc_terminate($fake);
    $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` LIKE ?", 's', ["%@{$domain}"]);
    array_map('unlink', glob("{$dir}/*") ?: []);
    @rmdir($dir);
});

$mode = static fn (string $m) => file_put_contents("{$dir}/mode", $m);
$requests = static fn (): array => array_map(
    static fn (string $l): array => (array) json_decode($l, true),
    file("{$dir}/requests.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
);
$sentTo = static fn (string $to): int => count(array_filter(
    file("{$dir}/sent.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    static fn (string $l): bool => $l === strtolower($to)
));
$row = static fn (int $id): ?array => $t->db->fetchOne("SELECT `status`, `transport`, `attempt_count`, `last_error` FROM `email_outbox` WHERE `id` = ?", 'i', [$id]);
$mail = new MailService($t->db);
// Queue without sending, make it due, deliver it once (as the worker or the retry run would).
$queue = static function (string $to, string $template = 'payment_claimed', array $data = []) use ($t, $mail): int {
    $mail->queue($to, $template, $data + ['order_number' => 'ORD-RESEND', 'amount' => 2500], null, false);
    return (int) $t->scalar("SELECT `id` FROM `email_outbox` WHERE `recipient_email` = ? ORDER BY `id` DESC LIMIT 1", 's', [strtolower($to)]);
};
$retryNow = static fn (int $id) => $t->sql("UPDATE `email_outbox` SET `next_attempt_at` = NULL WHERE `id` = ?", 'i', [$id]);

// -----------------------------------------------------------------------------
$t->section('Configuration');
$base = ['MAIL_MAILER' => 'resend', 'RESEND_API_URL' => "http://127.0.0.1:{$port}", 'MAIL_FROM_ADDRESS' => "store@{$domain}", 'MAIL_FROM_NAME' => 'Test Shop', 'MAIL_TIMEOUT_SECONDS' => '2'];
$setEnv(array_merge($base, ['RESEND_API_KEY' => '']));
$t->check('without an API key Resend is not used (log only)', 'log_only', MailService::mode());
$setEnv(array_merge($base, ['RESEND_API_KEY' => $apiKey, 'MAIL_FROM_ADDRESS' => 'not-an-address']));
$t->check('without a valid sender address Resend is not used', false, ResendTransport::isConfigured());
$setEnv(array_merge($base, ['RESEND_API_KEY' => $apiKey]));
$t->check('with key and sender: mode is resend', 'resend', MailService::mode());

// -----------------------------------------------------------------------------
$t->section('A delivered email');
$to = "buyer@{$domain}";
$id = $queue($to);
$result = $mail->deliver($id);
$t->check('delivered', 'sent', $result['status'] ?? null);
$t->check('transport recorded as resend', 'resend', $row($id)['transport'] ?? null);
$all = $requests();
$req = end($all) ?: [];
$t->check('posted to /emails', '/emails', $req['path'] ?? null);
$t->check('authenticated with the server-side key', "Bearer {$apiKey}", $req['auth'] ?? null);
$t->check('from the store name and address', "Test Shop <store@{$domain}>", $req['body']['from'] ?? null);
$t->check('to the recipient only', [$to], $req['body']['to'] ?? null);
$t->ok('subject, HTML and plain text are sent', !empty($req['body']['subject']) && !empty($req['body']['html']) && !empty($req['body']['text']));
$t->ok('carries an idempotency key for this outbox email', str_starts_with((string) ($req['key'] ?? ''), "shopco-outbox-{$id}-"), (string) ($req['key'] ?? ''));
$t->check('the API key is never stored with the email', false, str_contains(json_encode($row($id)), $apiKey));
$t->check('no reply-to unless MAIL_REPLY_TO is set', false, array_key_exists('reply_to', $req['body'] ?? []));

$setEnv(['MAIL_REPLY_TO' => "owner@{$domain}"]);
$id = $queue("replies@{$domain}");
$mail->deliver($id);
$all = $requests();
$req = end($all) ?: [];
$t->check('MAIL_REPLY_TO: replies go to the owner', ["owner@{$domain}"], $req['body']['reply_to'] ?? null);
$setEnv(['MAIL_REPLY_TO' => 'not-an-address']);
$t->check('an invalid MAIL_REPLY_TO is ignored', null, MailService::replyToAddress());
$setEnv(['MAIL_REPLY_TO' => '']);

$news = "reader@{$domain}";
$t->db->execute("INSERT INTO `newsletter_subscribers` (`email`, `status`, `source`, `unsubscribe_token`) VALUES (?, 'active', 'test', ?)", 'ss', [$news, bin2hex(random_bytes(16))]);
$t->cleanup(fn () => $t->sql("DELETE FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$news]));
$newsId = $queue($news, 'marketing_broadcast', ['subject' => 'News', 'content' => 'Hello', 'unsubscribe_url' => 'https://shop.example/unsubscribe?token=abc']);
$mail->deliver($newsId);
$all = $requests();
$req = end($all) ?: [];
$t->check('newsletters carry List-Unsubscribe', '<https://shop.example/unsubscribe?token=abc>', $req['body']['headers']['List-Unsubscribe'] ?? null);

// -----------------------------------------------------------------------------
$t->section('A lost answer is retried without a second email');
$to = "lost@{$domain}";
$id = $queue($to);
$mode('drop'); // Resend sends it, but the answer never arrives
$mail->deliver($id);
$t->check('the first attempt looks failed, so it stays queued', 'pending', $row($id)['status'] ?? null);
$mode('ok');
$retryNow($id);
$mail->deliver($id);
$t->check('the retry is reported sent', 'sent', $row($id)['status'] ?? null);
$t->check('and the recipient got exactly one email', 1, $sentTo($to));
$keys = array_column(array_filter($requests(), fn (array $r) => ($r['body']['to'][0] ?? '') === $to), 'key');
$t->check('both attempts used the same idempotency key', 1, count(array_unique($keys)));

// -----------------------------------------------------------------------------
$t->section('Failures');
$cases = [
    ['429', 'rate limited (429): retried later', 'pending', '429'],
    ['500', 'Resend error (500): retried later', 'pending', '500'],
    ['401', 'wrong API key (401): retried later, so a fixed key still sends it', 'pending', '401'],
    ['422', 'the email itself is invalid (422): given up at once', 'failed', '422'],
];
foreach ($cases as [$m, $label, $expected, $code]) {
    $to = "fail{$m}@{$domain}";
    $id = $queue($to);
    $mode($m);
    $mail->deliver($id);
    $r = $row($id);
    $t->check($label, $expected, $r['status'] ?? null);
    $t->ok("the reason names HTTP {$code}", str_contains((string) ($r['last_error'] ?? ''), "HTTP {$code}"), (string) ($r['last_error'] ?? ''));
    $t->check('nothing was sent', 0, $sentTo($to));
}

// -----------------------------------------------------------------------------
$t->section('Too many requests: slow down without using up attempts');
$mode('429');
$ids = [];
foreach (['a', 'b', 'c'] as $n) {
    $ids[] = $queue("busy-{$n}@{$domain}");
}
// Anything earlier suites left due must not take the refusal instead of these three.
$t->sql('UPDATE `email_outbox` SET `next_attempt_at` = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE `status` = \'pending\' AND `id` NOT IN (' . implode(',', $ids) . ') AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= NOW())');
$before = count($requests());
$summary = $mail->processQueue(10);
$t->check('the batch stops at the first "too many requests"', 1, count($requests()) - $before);
$t->check('and reports it', [0, 1], [$summary['processed'], $summary['rate_limited']]);
$in = implode(',', $ids);
$t->check('no email lost an attempt', 0, (int) $t->scalar("SELECT SUM(`attempt_count`) FROM `email_outbox` WHERE `id` IN ({$in})"));
$t->check('the refused one waits a minute, the rest stay due', [1, 2], [
    (int) $t->scalar("SELECT COUNT(*) FROM `email_outbox` WHERE `id` IN ({$in}) AND `status` = 'pending' AND `next_attempt_at` > NOW()"),
    (int) $t->scalar("SELECT COUNT(*) FROM `email_outbox` WHERE `id` IN ({$in}) AND `status` = 'pending' AND `next_attempt_at` IS NULL"),
]);
$t->sql("DELETE FROM `email_outbox` WHERE `id` IN ({$in})");
$mode('429quota');
$id = $queue("quota@{$domain}");
$mail->deliver($id);
$t->check('a used-up daily quota (also 429) counts as an attempt, so it cannot retry forever', ['pending', 1], [$row($id)['status'] ?? null, (int) ($row($id)['attempt_count'] ?? -1)]);
$mode('ok');

$mode('slow');
$to = "slow@{$domain}";
$id = $queue($to);
$start = microtime(true);
$mail->deliver($id);
$took = microtime(true) - $start;
$t->ok('a hanging Resend costs at most the mail timeout (2 s)', $took < 3.5, sprintf('%.1f s', $took));
$t->check('and the email stays queued', 'pending', $row($id)['status'] ?? null);
$mode('ok');
sleep(4); // let the stand-in finish the slow request

$setEnv(['RESEND_API_URL' => 'http://127.0.0.1:1']);
$to = "offline@{$domain}";
$id = $queue($to);
$mail->deliver($id);
$t->ok('Resend unreachable: kept for a retry with the reason', ($row($id)['status'] ?? null) === 'pending' && str_contains((string) ($row($id)['last_error'] ?? ''), 'Could not reach Resend'), (string) ($row($id)['last_error'] ?? ''));
$setEnv(['RESEND_API_URL' => "http://127.0.0.1:{$port}"]);

exit($t->finish());
