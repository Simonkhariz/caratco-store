<?php

declare(strict_types=1);

/**
 * Newsletter audience: footer subscribers plus registered customers, one entry per email, and
 * an unsubscribe always wins (a customer who unsubscribed is never put back).
 *
 *   php backend/tests/run.php MarketingAudience
 */

require __DIR__ . '/support/TestKit.php';

$t = new TestKit('MarketingAudience');
$admin = $t->adminSession('audience');
$A = static fn (string $method, string $path, array $opts = []): array
    => $t->http($method, $path, $opts + ['jar' => $admin['jar'], 'csrf' => $admin['csrf']]);

/** A customer account; $verified = their email address is confirmed. */
$customer = static function (string $label, string $status = 'active', bool $verified = true) use ($t): string {
    $email = $t->uniq("t-aud-{$label}") . '@example.test';
    $id = (int) $t->db->insert(
        "INSERT INTO `users` (`uuid`, `email`, `full_name`, `role`, `status`, `email_verified_at`) VALUES (UUID(), ?, 'Audience Test', 'customer', ?, IF(?, NOW(), NULL))",
        'ssi',
        [$email, $status, $verified ? 1 : 0]
    );
    $t->cleanup(function () use ($t, $id, $email): void {
        $t->sql("DELETE FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$email]);
        $t->sql("DELETE FROM `users` WHERE `id` = ?", 'i', [$id]);
    });
    return $email;
};
/** The audience entry for an email (status filter 'any'), or null. */
$find = static function (string $email, string $status = 'any') use ($A): ?array {
    for ($page = 1; $page <= 50; $page++) {
        $res = $A('GET', "/admin/marketing/subscribers?status={$status}&per_page=100&page={$page}");
        foreach ((array) TestKit::get($res, 'data.items') as $item) {
            if ($item['email'] === $email) {
                return $item;
            }
        }
        if ($page >= (int) TestKit::get($res, 'data.pagination.total_pages')) {
            break;
        }
    }
    return null;
};
$t->cleanup(fn () => $t->sql("DELETE FROM `newsletter_subscribers` WHERE `source` = 'registered_customer'"));

// -----------------------------------------------------------------------------
$t->section('Who is in the audience');
$plain = $customer('plain');
$entry = $find($plain);
$t->check('an active customer is listed as a registered customer', ['customer', 'active', true], [$entry['origin'] ?? null, $entry['status'] ?? null, $entry['has_account'] ?? null]);
$t->check('a suspended customer is not', null, $find($customer('off', 'suspended')));

// Signing up proves nothing: anyone can type someone else's address into the form.
$unproven = $customer('unproven', 'active', false);
$t->check('a customer who never confirmed their email is not', null, $find($unproven));
$unprovenId = (int) $t->scalar("SELECT `id` FROM `users` WHERE `email` = ?", 's', [$unproven]);
$paidOrderId = (int) $t->db->insert(
    "INSERT INTO `orders` (`order_number`, `user_id`, `guest_email`, `guest_access_token_hash`, `subtotal`, `grand_total`, `order_status`, `payment_status`)
     VALUES (?, ?, ?, ?, 1000, 1000, 'paid', 'successful')",
    'siss',
    [strtoupper(substr($t->uniq('AUD'), 0, 30)), $unprovenId, $unproven, hash('sha256', $unproven)]
);
$t->cleanup(fn () => $t->sql("DELETE FROM `orders` WHERE `id` = ?", 'i', [$paidOrderId]));
$t->check('once they have paid for an order they are', 'customer', $find($unproven)['origin'] ?? null);

$both = $customer('both');
$t->http('POST', '/newsletter/subscribe', ['json' => ['email' => $both]]);
$entry = $find($both);
$t->check('a customer who also subscribed appears once, as a subscriber with an account', ['subscriber', true], [$entry['origin'] ?? null, $entry['has_account'] ?? null]);
$t->check('only one entry for them', 1, (function () use ($A, $both): int {
    $n = 0;
    for ($page = 1; $page <= 50; $page++) {
        $res = $A('GET', "/admin/marketing/subscribers?status=any&per_page=100&page={$page}");
        $n += count(array_filter((array) TestKit::get($res, 'data.items'), static fn (array $i): bool => $i['email'] === $both));
        if ($page >= (int) TestKit::get($res, 'data.pagination.total_pages')) {
            break;
        }
    }
    return $n;
})());

$footerOnly = $t->uniq('t-aud-footer') . '@example.test';
$t->cleanup(fn () => $t->sql("DELETE FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$footerOnly]));
$t->http('POST', '/newsletter/subscribe', ['json' => ['email' => $footerOnly]]);
$entry = $find($footerOnly);
$t->check('a footer subscriber without an account', ['subscriber', false], [$entry['origin'] ?? null, $entry['has_account'] ?? null]);

$quit = $customer('quit');
$t->http('POST', '/newsletter/subscribe', ['json' => ['email' => $quit]]);
$token = (string) $t->scalar("SELECT `unsubscribe_token` FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$quit]);
$t->http('POST', '/newsletter/unsubscribe', ['json' => ['token' => $token]]);
$t->check('a customer who unsubscribed is not in the active list', null, $find($quit, 'active'));
$t->check('but is listed as unsubscribed', 'unsubscribed', $find($quit, 'unsubscribed')['status'] ?? null);
$again = $t->http('POST', '/newsletter/subscribe', ['json' => ['email' => $quit]]);
$t->check('the footer form cannot undo their unsubscribe', [409, 'UNSUBSCRIBED_BEFORE', 'unsubscribed'], [$again['status'], TestKit::get($again, 'error.code'), $find($quit, 'any')['status'] ?? null]);

$counts = (array) TestKit::get($A('GET', '/admin/marketing/subscribers'), 'data.counts');
$t->check('active = subscribers + customers', (int) $counts['active'], (int) $counts['active_subscribers'] + (int) $counts['active_customers']);
$t->check('origin filter', ['customer'], array_values(array_unique(array_column((array) TestKit::get($A('GET', '/admin/marketing/subscribers?origin=customer&per_page=100'), 'data.items'), 'origin'))));
$t->check('no unsubscribe tokens in the list', false, str_contains($A('GET', '/admin/marketing/subscribers?status=any&per_page=100')['body'], 'unsubscribe_token'));

// -----------------------------------------------------------------------------
$t->section('Sending to customers');
$active = (int) $counts['active'];
$subject = $t->uniq('Audience');
$t->cleanup(fn () => $t->sql("DELETE FROM `email_outbox` WHERE `template` = 'marketing_broadcast' AND JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.subject')) LIKE ?", 's', [$subject . '%']));
$sent = $A('POST', '/admin/marketing/broadcast', ['json' => ['subject' => $subject, 'content' => 'Hello', 'confirm_recipients' => $active]]);
$t->check('queued for everyone active (subscribers and customers)', [200, $active], [$sent['status'], TestKit::get($sent, 'data.queued_count')]);
$outboxFor = static fn (string $email, string $subj): ?array => $t->db->fetchOne(
    "SELECT `payload` FROM `email_outbox` WHERE `template` = 'marketing_broadcast' AND `recipient_email` = ? AND JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.subject')) = ?",
    'ss',
    [$email, $subj]
);
$payload = json_decode((string) ($outboxFor($plain, $subject)['payload'] ?? '{}'), true);
$t->check('the customer got it, saying why (has an account)', 'customer', $payload['reason'] ?? null);
$row = $t->db->fetchOne("SELECT `status`, `source`, `unsubscribe_token` FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$plain]);
$t->check('and now has their own unsubscribe token', ['active', 'registered_customer', 32], [$row['status'] ?? null, $row['source'] ?? null, strlen((string) ($row['unsubscribe_token'] ?? ''))]);
$t->ok('the link in their email is that token', str_contains((string) ($payload['unsubscribe_url'] ?? ''), (string) ($row['unsubscribe_token'] ?? 'x')));
$t->check('the subscriber who is also a customer got one copy', 1, (int) $t->scalar(
    "SELECT COUNT(*) FROM `email_outbox` WHERE `template` = 'marketing_broadcast' AND `recipient_email` = ? AND JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.subject')) = ?",
    'ss',
    [$both, $subject]
));
$t->check('the unsubscribed customer got nothing', null, $outboxFor($quit, $subject));
$t->check('the count stays the same after their rows were made', $active, (int) TestKit::get($A('GET', '/admin/marketing/subscribers'), 'data.counts.active'));

$t->check('the customer unsubscribes with the link', 200, $t->http('POST', '/newsletter/unsubscribe', ['json' => ['token' => (string) $row['unsubscribe_token']]])['status']);
$t->check('they leave the active list', null, $find($plain, 'active'));
$after = (int) TestKit::get($A('GET', '/admin/marketing/subscribers'), 'data.counts.active');
$t->check('one fewer active', $active - 1, $after);
$second = $subject . '-2';
$A('POST', '/admin/marketing/broadcast', ['json' => ['subject' => $second, 'content' => 'Again', 'confirm_recipients' => $after]]);
$t->check('and get no further newsletters, account or not', null, $outboxFor($plain, $second));

$t->check('a suspended customer with a row drops out', true, (function () use ($t, $A, $both, $find): bool {
    $t->sql("UPDATE `users` SET `status` = 'suspended' WHERE `email` = ?", 's', [$both]);
    // $both subscribed in the footer: still a subscriber, so still listed.
    $stillListed = ($find($both, 'active')['origin'] ?? null) === 'subscriber';
    $t->sql("UPDATE `users` SET `status` = 'active' WHERE `email` = ?", 's', [$both]);
    return $stillListed;
})());

// -----------------------------------------------------------------------------
$t->section('Data clean-up keeps the opt-out of an existing customer');
$t->sql("UPDATE `newsletter_subscribers` SET `unsubscribed_at` = DATE_SUB(NOW(), INTERVAL 5000 DAY) WHERE `email` IN (?, ?)", 'ss', [$plain, $quit]);
$oldRetention = $t->scalar("SELECT `value` FROM `store_settings` WHERE `key_name` = 'retention_customer_days'");
$t->sql("INSERT INTO `store_settings` (`key_name`, `value`) VALUES ('retention_customer_days', '730') ON DUPLICATE KEY UPDATE `value` = '730'");
$listed = array_column((new App\Services\DatabaseCleanup($t->db))->records('unsubscribed_newsletter_emails', date('Y-m-d H:i:s'), 1, 100000)['items'], 'email');
if ($oldRetention === null) {
    $t->sql("DELETE FROM `store_settings` WHERE `key_name` = 'retention_customer_days'");
} else {
    $t->sql("UPDATE `store_settings` SET `value` = ? WHERE `key_name` = 'retention_customer_days'", 's', [(string) $oldRetention]);
}
$t->ok('unsubscribed customers are not offered for deletion', !in_array($plain, $listed, true) && !in_array($quit, $listed, true));
$gone = $t->uniq('t-aud-gone') . '@example.test';
$t->cleanup(fn () => $t->sql("DELETE FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$gone]));
$t->sql("INSERT INTO `newsletter_subscribers` (`email`, `status`, `source`, `unsubscribe_token`, `unsubscribed_at`) VALUES (?, 'unsubscribed', 'storefront_footer', ?, DATE_SUB(NOW(), INTERVAL 5000 DAY))", 'ss', [$gone, bin2hex(random_bytes(16))]);
$t->sql("INSERT INTO `store_settings` (`key_name`, `value`) VALUES ('retention_customer_days', '730') ON DUPLICATE KEY UPDATE `value` = '730'");
$listed = array_column((new App\Services\DatabaseCleanup($t->db))->records('unsubscribed_newsletter_emails', date('Y-m-d H:i:s'), 1, 100000)['items'], 'email');
if ($oldRetention === null) {
    $t->sql("DELETE FROM `store_settings` WHERE `key_name` = 'retention_customer_days'");
} else {
    $t->sql("UPDATE `store_settings` SET `value` = ? WHERE `key_name` = 'retention_customer_days'", 's', [(string) $oldRetention]);
}
$t->ok('an old unsubscribe without an account still is', in_array($gone, $listed, true));

exit($t->finish());
