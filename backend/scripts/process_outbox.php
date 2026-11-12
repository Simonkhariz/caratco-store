<?php

declare(strict_types=1);

/**
 * Deliver queued emails from email_outbox (newsletters, and any order email whose first
 * attempt failed). Failed attempts are retried with backoff (1, 5, 15, 60 minutes) and
 * marked failed after 5 attempts. Safe to run concurrently, and alongside the API's own
 * automatic retries (MailService::retryDueAfterResponse): each email is claimed first.
 *
 * With MAIL_MAILER=log (the default) emails are written to backend/storage/logs/mail.log
 * instead of being delivered.
 *
 * Usage:
 *   php backend/scripts/process_outbox.php            (one batch of up to 100)
 *   php backend/scripts/process_outbox.php --limit=500
 *   php backend/scripts/process_outbox.php --limit=500 --max-seconds=50 --rate=8   (recommended schedule)
 *
 *   --limit=N        emails per batch, at most 500
 *   --max-seconds=N  keep taking batches until the queue is empty or N seconds have passed
 *                    (0 = one batch only). With a once-a-minute schedule, 50 lets one run
 *                    clear more than one batch without overlapping the next run much.
 *   --rate=N         send at most N emails per second (0 = no limit). Resend allows 10 requests a
 *                    second for the whole account by default, shared with the order emails the
 *                    API sends itself, so 8 leaves room for those; with more than one worker,
 *                    divide it between them. If Resend still answers "too many requests", the
 *                    email keeps all its attempts, is tried again a minute later, and the run
 *                    stops (its summary says stopped=rate-limited).
 *
 * Windows Task Scheduler (every minute; php-win.exe runs without a console window):
 *   schtasks /Create /SC MINUTE /MO 1 /TN "ShopCo Send Email" ^
 *     /TR "C:\xampp\php\php-win.exe C:\path\to\E-commerce\backend\scripts\process_outbox.php"
 * cron (Linux):  * * * * *  php /var/www/shopco/backend/scripts/process_outbox.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/src/autoload.php';

use App\Config\Env;
use App\Services\MailService;

Env::load(BASE_PATH . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Africa/Lagos') ?: 'Africa/Lagos');

$limit = 100;
$maxSeconds = 0;
$rate = 0.0;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(500, (int) $m[1]));
    } elseif (preg_match('/^--max-seconds=(\d+)$/', $arg, $m)) {
        $maxSeconds = min(3600, (int) $m[1]);
    } elseif (preg_match('/^--rate=(\d+(?:\.\d+)?)$/', $arg, $m)) {
        $rate = (float) $m[1];
    }
}

try {
    $mail = MailService::getInstance();
    $deadline = microtime(true) + $maxSeconds;
    $summary = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'retrying' => 0, 'rate_limited' => 0];
    do {
        $left = (int) ceil($deadline - microtime(true));
        $batch = $mail->processQueue($limit, $maxSeconds > 0 ? max(1, $left) : 0, $rate);
        foreach ($batch as $key => $n) {
            $summary[$key] += $n;
        }
        // Stop when a batch found nothing to send (empty queue, or other workers have it), or the
        // email service asked us to slow down (the next scheduled run carries on).
    } while ($maxSeconds > 0 && $batch['processed'] > 0 && $batch['rate_limited'] === 0 && microtime(true) < $deadline);
} catch (Throwable $e) {
    fwrite(STDERR, '[process_outbox] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

printf(
    "[%s] mode=%s processed=%d sent=%d retrying=%d failed=%d%s\n",
    date('Y-m-d H:i:s'),
    MailService::mode(),
    $summary['processed'],
    $summary['sent'],
    $summary['retrying'],
    $summary['failed'],
    $summary['rate_limited'] > 0 ? ' stopped=rate-limited (lower --rate)' : ''
);
