<?php

declare(strict_types=1);

/**
 * Data retention (admin → Data & storage): records past the keep periods are only listed;
 * nothing is removed until an admin reviews them and confirms with their password and an
 * emailed code. Inactive customers lose their account and the personal details on their
 * orders, money records stay. Also the daily check, storage health and the slow-request log.
 *
 *   php backend/tests/run.php DataRetention
 */

require __DIR__ . '/support/TestKit.php';

use App\Services\DatabaseCleanup;
use App\Services\MailService;
use App\Services\StorageHealth;
use App\Support\SlowRequestLog;

$t = new TestKit('DataRetention');

$setting = static fn (string $key, string $value) => $t->sql(
    "INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
    'ss',
    [$key, $value]
);
$t->cleanup(function () use ($setting): void {
    $setting('retention_customer_days', '0');
    $setting('retention_activity_days', '0');
    $setting('storage_alert_mb', '500');
});

/** A customer account last seen $days ago. */
$account = static function (string $email, int $seenDaysAgo, string $role = 'customer') use ($t): int {
    $id = (int) $t->db->insert(
        "INSERT INTO `users` (`uuid`, `email`, `full_name`, `phone`, `role`, `status`, `email_verified_at`, `last_seen_at`, `created_at`)
         VALUES (UUID(), ?, 'Retention Person', '08000000007', ?, 'active', NOW(), DATE_SUB(NOW(), INTERVAL ? DAY), DATE_SUB(NOW(), INTERVAL ? DAY))",
        'ssii',
        [$email, $role, $seenDaysAgo, $seenDaysAgo + 30]
    );
    $t->cleanup(fn () => $t->sql("DELETE FROM `users` WHERE `id` = ?", 'i', [$id]));
    return $id;
};

/** A paid order for $email in $status, last changed $days ago; stock is given back. */
$order = static function (string $email, string $status, int $daysAgo, ?int $userId = null) use ($t): ?int {
    $variant = $t->variant(2);
    if ($variant === null) {
        return null;
    }
    $res = $t->http('POST', '/checkout/orders', [
        'headers' => ['Idempotency-Key: ' . bin2hex(random_bytes(16))],
        'json' => [
            'email' => $email,
            'notes' => 'Leave with the gate man',
            'items' => [['product_id' => (int) $variant['product_id'], 'variant_id' => (int) $variant['variant_id'], 'quantity' => 1]],
            'shipping_address' => ['full_name' => 'Retention Buyer', 'phone' => '08000000008', 'address_line1' => '8 Old Road', 'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria'],
        ],
    ]);
    if ($res['status'] !== 201) {
        return null;
    }
    $oid = (int) $t->scalar("SELECT `id` FROM `orders` WHERE `order_number` = ?", 's', [(string) TestKit::get($res, 'data.order_number')]);
    $t->cleanup(function () use ($t, $oid): void {
        foreach (['refunds', 'shipments', 'payments', 'order_status_history', 'order_items'] as $table) {
            $t->sql("DELETE FROM `{$table}` WHERE `order_id` = ?", 'i', [$oid]);
        }
        $t->sql("DELETE FROM `orders` WHERE `id` = ?", 'i', [$oid]);
    });
    $t->sql(
        "UPDATE `product_variants` v JOIN `order_items` oi ON oi.variant_id = v.id
         SET v.stock_quantity = v.stock_quantity + oi.quantity WHERE oi.order_id = ?",
        'i',
        [$oid]
    );
    $t->sql(
        "INSERT INTO `payments` (`order_id`, `provider`, `provider_reference`, `amount`, `currency`, `status`, `verified_at`)
         SELECT `id`, 'kora', ?, `grand_total`, 'NGN', 'successful', DATE_SUB(NOW(), INTERVAL ? DAY) FROM `orders` WHERE `id` = ?",
        'sii',
        ['RET-' . bin2hex(random_bytes(5)), $daysAgo, $oid]
    );
    $t->sql(
        "UPDATE `orders` SET `order_status` = ?, `payment_status` = 'successful', `expires_at` = NULL, `user_id` = ?,
                `created_at` = DATE_SUB(NOW(), INTERVAL ? DAY), `updated_at` = DATE_SUB(NOW(), INTERVAL ? DAY)
         WHERE `id` = ?",
        'siiii',
        [$status, $userId, $daysAgo + 5, $daysAgo, $oid]
    );
    return $oid;
};

/** An old payment notification: a quick way to have something past its keep period. */
$oldNotification = static function () use ($t): int {
    $id = (int) $t->db->insert(
        "INSERT INTO `payment_webhook_events` (`provider`, `event_reference`, `event_type`, `processing_result`, `received_at`)
         VALUES ('kora', ?, 'charge.success', 'processed', DATE_SUB(NOW(), INTERVAL 400 DAY))",
        's',
        ['RET-EVT-' . bin2hex(random_bytes(5))]
    );
    $t->cleanup(fn () => $t->sql("DELETE FROM `payment_webhook_events` WHERE `id` = ?", 'i', [$id]));
    return $id;
};

$row = static fn (int $id): ?array => $t->db->fetchOne("SELECT * FROM `orders` WHERE `id` = ?", 'i', [$id]);
$userExists = static fn (int $id): bool => (int) $t->scalar("SELECT COUNT(*) FROM `users` WHERE `id` = ?", 'i', [$id]) === 1;
$now = static fn (): string => date('Y-m-d H:i:s');

// -----------------------------------------------------------------------------
$t->section('Settings');
$cleanup = new DatabaseCleanup($t->db);
$t->check('customer records are kept until the admin chooses', 0, $cleanup->settings()['retention_customer_days']);
$t->check('unfinished checkouts: 30 days by default', 30, $cleanup->settings()['retention_unpaid_days']);
$setting('retention_customer_days', '45');
$t->check('a value that is not offered falls back to the default', 0, $cleanup->settings()['retention_customer_days']);

// -----------------------------------------------------------------------------
$t->section('Test customers');
$guestOld = $t->uniq('ret-guest') . '@example.test';     // finished order 120 days ago   -> anonymised
$acctOld = $t->uniq('ret-acct') . '@example.test';       // account unused 200 days       -> deleted
$returning = $t->uniq('ret-back') . '@example.test';     // old order, but bought again    -> kept
$shippedOld = $t->uniq('ret-ship') . '@example.test';    // order still on its way        -> kept
$activeAcct = $t->uniq('ret-active') . '@example.test';  // signed in 10 days ago          -> kept
$flaggedOld = $t->uniq('ret-flag') . '@example.test';    // order flagged for the admin   -> kept
$adminOld = $t->uniq('ret-admin') . '@example.test';     // admin, never removed

$acctOldId = $account($acctOld, 200);
$t->sql(
    "INSERT INTO `addresses` (`user_id`, `recipient_name`, `phone`, `street_address`, `city`, `state`) VALUES (?, 'Retention Person', '08000000007', '9 Saved St', 'Ikeja', 'Lagos')",
    'i',
    [$acctOldId]
);
$activeAcctId = $account($activeAcct, 10);
$adminOldId = $account($adminOld, 400, 'admin');

$orders = [
    'guestOld' => $order($guestOld, 'delivered', 120),
    'acctOld' => $order($acctOld, 'completed', 150, $acctOldId),
    'returningOld' => $order($returning, 'delivered', 200),
    'returningNew' => $order($returning, 'delivered', 10),
    'shippedOld' => $order($shippedOld, 'shipped', 200),
    'activeAcct' => $order($activeAcct, 'delivered', 200, $activeAcctId),
    'flaggedOld' => $order($flaggedOld, 'delivered', 200),
];
$t->ok('test orders placed', !in_array(null, $orders, true));
$t->sql("UPDATE `orders` SET `requires_attention` = 1, `attention_reason` = 'test', `updated_at` = `updated_at` WHERE `id` = ?", 'i', [$orders['flaggedOld']]);
$before = $row($orders['guestOld']);
$setting('retention_customer_days', '90');

// -----------------------------------------------------------------------------
$t->section('Review lists exactly what would go');
$asOf = $now();
$snap = $cleanup->snapshot($asOf);
$people = $snap['ids']['inactive_customers'] ?? [];
$orderIds = $snap['ids']['inactive_customer_orders'] ?? [];
$t->ok('inactive guest listed', in_array($guestOld, $people, true));
$t->ok('unused account listed', in_array($acctOld, $people, true) && in_array((string) $acctOldId, $snap['ids']['inactive_customer_accounts'] ?? [], true));
$t->ok('their orders listed', in_array((string) $orders['guestOld'], $orderIds, true) && in_array((string) $orders['acctOld'], $orderIds, true));
foreach (['returning' => $returning, 'shipped' => $shippedOld, 'active' => $activeAcct, 'flagged' => $flaggedOld, 'admin' => $adminOld] as $who => $email) {
    $t->ok("{$who} customer not listed", !in_array($email, $people, true));
}
$t->check('counts match the lists', count($people), $snap['counts']['inactive_customers']);

$page = $cleanup->records('inactive_customers', $asOf, 1, 500);
$listed = array_column($page['items'], null, 'email');
$t->check('customer page shows the name', 'Retention Buyer', $listed[$guestOld]['name'] ?? null);
$t->check('customer page shows the account is deleted', 'Yes (deleted)', $listed[$acctOld]['account'] ?? null);
$orderPage = $cleanup->records('inactive_customer_orders', $asOf, 1, 500);
$t->ok('order page shows order numbers and totals', in_array($before['order_number'], array_column($orderPage['items'], 'order_number'), true)
    && array_key_exists('grand_total', $orderPage['items'][0] ?? []));
$t->ok('order page has column headings', in_array('Total (kept)', array_column($orderPage['columns'], 'label'), true));
$t->ok('reviewing changes nothing', $userExists($acctOldId) && $row($orders['guestOld'])['guest_email'] === $guestOld);

// -----------------------------------------------------------------------------
$t->section('Nothing is removed automatically');
$health = (new StorageHealth($t->db))->report($now(), false);
$t->ok('health flags records past the keep periods', in_array('due', array_column($health['issues'], 'code'), true));
$t->cleanup(fn () => $t->sql("DELETE FROM `email_outbox` WHERE `template` = 'storage_alert'"));
$proc = proc_open([PHP_BINARY, BASE_PATH . '/scripts/check_database.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH);
$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
$t->check('daily check runs', 0, proc_close($proc), $output);
$t->ok('daily check lists what is due', str_contains($output, 'nothing removed'));
$t->ok('daily check removed nothing', $userExists($acctOldId) && $row($orders['guestOld'])['guest_email'] === $guestOld);
$t->ok('daily check alerted the admins', (int) $t->scalar("SELECT COUNT(*) FROM `email_outbox` WHERE `template` = 'storage_alert'") >= 1);

// -----------------------------------------------------------------------------
$t->section('A list that changed after review is refused');
$t->sql("UPDATE `orders` SET `updated_at` = NOW() WHERE `id` = ?", 'i', [$orders['guestOld']]); // the customer is back
try {
    $cleanup->execute($asOf, $snap['fingerprint']);
    $t->ok('changed list refused', false);
} catch (DomainException) {
    $t->ok('changed list refused', true);
}
$t->sql("UPDATE `orders` SET `updated_at` = ? WHERE `id` = ?", 'si', [$before['updated_at'], $orders['guestOld']]);
$t->ok('and nothing was removed', $userExists($acctOldId));

// -----------------------------------------------------------------------------
$t->section('Clean-up of the reviewed records');
$asOf = $now();
$snap = $cleanup->snapshot($asOf);
$removed = $cleanup->execute($asOf, $snap['fingerprint'], 'Test Admin');
$t->ok('inactive customers handled', ($removed['inactive_customers'] ?? 0) >= 2, json_encode($removed));

$g = $row($orders['guestOld']);
$snapshot = json_decode((string) $g['shipping_address_snapshot'], true);
$t->check('guest email removed', '', $g['guest_email']);
$t->check('address keeps only city, state and country', ['city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria'], $snapshot);
$t->check('customer note removed', null, $g['notes']);
$t->ok('old guest link no longer opens the order', $g['guest_access_token_hash'] !== $before['guest_access_token_hash']);
$t->ok('removal date recorded', $g['personal_data_removed_at'] !== null);
$t->check('total kept', $before['grand_total'], $g['grand_total']);
$t->check('last-changed date kept (reports use it)', $before['updated_at'], $g['updated_at']);
$t->check('items kept', 1, (int) $t->scalar("SELECT COUNT(*) FROM `order_items` WHERE `order_id` = ?", 'i', [$orders['guestOld']]));
$t->check('payment kept', 1, (int) $t->scalar("SELECT COUNT(*) FROM `payments` WHERE `order_id` = ? AND `status` = 'successful'", 'i', [$orders['guestOld']]));

$t->ok('unused account deleted', !$userExists($acctOldId));
$t->check('its saved address went with it', 0, (int) $t->scalar("SELECT COUNT(*) FROM `addresses` WHERE `user_id` = ?", 'i', [$acctOldId]));
$a = $row($orders['acctOld']);
$t->ok('its order is kept, anonymised', $a !== null && $a['user_id'] === null && $a['guest_email'] === '' && $a['personal_data_removed_at'] !== null);

$t->check('customer who bought again keeps the old order details', $returning, $row($orders['returningOld'])['guest_email']);
$t->check('order still on its way is untouched', $shippedOld, $row($orders['shippedOld'])['guest_email']);
$t->ok('recently active account is kept', $userExists($activeAcctId));
$t->check('and its old order keeps the details', $activeAcct, $row($orders['activeAcct'])['guest_email']);
$t->check('order flagged for the admin is untouched', $flaggedOld, $row($orders['flaggedOld'])['guest_email']);
$t->ok('admin account is never removed', $userExists($adminOldId));
$t->check('the run is recorded', 'Test Admin', $cleanup->lastRun()['by'] ?? null);

// -----------------------------------------------------------------------------
$t->section('Signing in counts as activity');
$seenEmail = $t->uniq('ret-seen') . '@example.test';
$seenId = $account($seenEmail, 300);
$t->http('POST', '/auth/customer/magic-link', ['json' => ['email' => $seenEmail]]);
$link = $t->magicLinkToken($seenEmail);
$t->check('customer signs in', 200, $t->http('POST', '/auth/customer/verify', ['jar' => 'seen', 'json' => ['token' => (string) $link]])['status']);
$t->http('GET', '/auth/me', ['jar' => 'seen']);
$t->ok('last seen is now', (int) $t->scalar("SELECT TIMESTAMPDIFF(MINUTE, `last_seen_at`, NOW()) FROM `users` WHERE `id` = ?", 'i', [$seenId]) < 5);

// -----------------------------------------------------------------------------
$t->section('Slow requests and health');
$t->cleanup(fn () => $t->sql("DELETE FROM `api_slow_requests` WHERE `route` = '/api/v1/test/slow'"));
SlowRequestLog::record('GET', '/api/v1/test/slow', microtime(true) - 2.5);
SlowRequestLog::record('GET', '/api/v1/test/slow', microtime(true) - 3.0);
SlowRequestLog::record('GET', '/api/v1/test/slow', microtime(true));        // fast: not recorded
SlowRequestLog::record('GET', null, microtime(true) - 5.0);                  // unknown route: not recorded
$slow = $t->db->fetchOne("SELECT `hits`, `max_ms` FROM `api_slow_requests` WHERE `route` = '/api/v1/test/slow'");
$t->check('two slow requests counted in one row', 2, (int) ($slow['hits'] ?? 0));
$t->ok('slowest time kept', (int) ($slow['max_ms'] ?? 0) >= 2900);

$health = (new StorageHealth($t->db))->report($now(), true);
$t->ok('health has a level', in_array($health['level'], ['ok', 'warning', 'critical'], true));
$t->ok('database size measured', $health['database']['size_bytes'] > 0);
$t->ok('slow requests in the report', $health['slow_requests']['last_24h'] >= 2);
$t->ok('typical query time measured', isset($health['queries']['typical_ms']));
$setting('storage_alert_mb', '1');
$t->check('an unknown size limit falls back to 500 MB', 500, (new StorageHealth($t->db))->alertMb());

// -----------------------------------------------------------------------------
$t->section('Admin: review pages');
$t->check('signed out: 401', 401, $t->http('GET', '/admin/data')['status']);
$admin = $t->adminSession('retention');
$A = static fn (string $method, string $path, array $opts = []): array
    => $t->http($method, $path, $opts + ['jar' => $admin['jar'], 'csrf' => $admin['csrf']]);

$overview = $A('GET', '/admin/data');
$t->check('overview 200', 200, $overview['status']);
$t->check('overview has the saved customer period', 90, TestKit::get($overview, 'data.settings.retention_customer_days'));
$t->ok('overview lists the choices', in_array(365, (array) TestKit::get($overview, 'data.options.retention_customer_days'), true));
$t->ok('overview has the health report', TestKit::get($overview, 'data.health.database.size_bytes') > 0);
$t->ok('overview says what is due', is_array(TestKit::get($overview, 'data.health.cleanup.due')));
$pageAsOf = (string) TestKit::get($overview, 'data.health.as_of');
$t->ok('overview gives the review time', $pageAsOf !== '');
$t->ok('alert endpoint answers', in_array(TestKit::get($A('GET', '/admin/data/alert'), 'data.level'), ['ok', 'warning', 'critical'], true));

$evt = $oldNotification();
$records = $A('GET', '/admin/data/records?rule=old_gateway_notifications&as_of=' . rawurlencode($now()));
$t->check('records page 200', 200, $records['status']);
$t->ok('records page lists the old notification', in_array('charge.success', array_column((array) TestKit::get($records, 'data.items'), 'event_type'), true));
$t->ok('records page has columns', count((array) TestKit::get($records, 'data.columns')) > 0);
$t->check('unknown rule: 422', 422, $A('GET', '/admin/data/records?rule=users&as_of=' . rawurlencode($now()))['status']);
$t->check('a review older than 30 minutes: 422', 422, $A('GET', '/admin/data/records?rule=old_gateway_notifications&as_of=' . rawurlencode(date('Y-m-d H:i:s', time() - 3600)))['status']);
$t->check('a made-up review time: 422', 422, $A('GET', '/admin/data/records?rule=old_gateway_notifications&as_of=yesterday')['status']);

$t->check('a value that is not offered: 422', 422, $A('PUT', '/admin/data/settings', ['json' => ['retention_customer_days' => 45]])['status']);
$t->check('an unknown setting: 422', 422, $A('PUT', '/admin/data/settings', ['json' => ['store_name' => 'x']])['status']);
$saved = $A('PUT', '/admin/data/settings', ['json' => ['retention_customer_days' => 365, 'storage_alert_mb' => 1000]]);
$t->check('valid settings saved', 365, TestKit::get($saved, 'data.retention_customer_days'));
$t->check('size limit saved', 1000, TestKit::get($saved, 'data.storage_alert_mb'));
$t->ok('change is in the audit log', (int) $t->scalar("SELECT COUNT(*) FROM `admin_audit_logs` WHERE `admin_id` = ? AND `action` = 'data_retention.update'", 'i', [$admin['id']]) === 1);
$t->check('settings change needs CSRF', 403, $t->http('PUT', '/admin/data/settings', ['jar' => $admin['jar'], 'json' => ['retention_unpaid_days' => 7]])['status']);
$t->ok('saving a period removes nothing', (int) $t->scalar("SELECT COUNT(*) FROM `payment_webhook_events` WHERE `id` = ?", 'i', [$evt]) === 1);

// -----------------------------------------------------------------------------
$t->section('Admin: password and emailed code');
$verify = static fn (array $json) => $A('POST', '/admin/data/cleanup/verify', ['json' => $json]);
$run = static fn (array $json) => $A('POST', '/admin/data/cleanup', ['json' => $json]);
$evtExists = static fn (int $id): bool => (int) $t->scalar("SELECT COUNT(*) FROM `payment_webhook_events` WHERE `id` = ?", 'i', [$id]) === 1;

$t->check('the old one-step clean-up is gone (needs a code)', 410, $run(['expected_customers' => 0])['status']);
$t->check('verify needs CSRF', 403, $t->http('POST', '/admin/data/cleanup/verify', ['jar' => $admin['jar'], 'json' => ['password' => $admin['password'], 'as_of' => $now()]])['status']);
$wrong = $verify(['password' => 'not-the-password', 'as_of' => $now()]);
$t->check('wrong password: 422', 422, $wrong['status']);
$t->check('wrong password says so', 'WRONG_PASSWORD', TestKit::get($wrong, 'error.code'));
$t->ok('wrong password is audited', (int) $t->scalar("SELECT COUNT(*) FROM `admin_audit_logs` WHERE `admin_id` = ? AND `action` = 'data.cleanup_password_failed'", 'i', [$admin['id']]) === 1);
$t->check('a stale review is refused', 422, $verify(['password' => $admin['password'], 'as_of' => date('Y-m-d H:i:s', time() - 3600)])['status']);

$ok = $verify(['password' => $admin['password'], 'as_of' => $now()]);
$t->check('right password: code sent', 200, $ok['status']);
$confirmId = (string) TestKit::get($ok, 'data.confirmation_id');
$t->ok('email address shown masked', str_contains((string) TestKit::get($ok, 'data.sent_to'), '•'));
$code = $t->confirmationCode(strtolower($admin['email']));
$t->ok('code delivered by email', $code !== null && strlen($code) === 6);
$t->check('code is never stored', 0, (int) $t->scalar("SELECT COUNT(*) FROM `admin_confirmations` WHERE `id` = ? AND `code_hash` = ?", 'ss', [$confirmId, (string) $code]));
// Someone signed in to admin must not be able to read the code: that is what it protects against.
$outboxList = $A('GET', '/admin/notifications/outbox?per_page=100');
$t->check('the email outbox lists the code email', 200, $outboxList['status']);
$t->ok('with its subject', in_array('Your clean-up confirmation code', array_column((array) TestKit::get($outboxList, 'data.items'), 'subject'), true));
$t->ok('the outbox list does not show the code', !str_contains($outboxList['body'], (string) $code));
$t->ok('nor does the review list of old emails', !str_contains($A('GET', '/admin/data/records?rule=old_emails&as_of=' . rawurlencode($now()))['body'], (string) $code));
$t->check('and it is nowhere in its stored row', 0, (int) $t->scalar("SELECT COUNT(*) FROM `email_outbox` WHERE `template` = 'admin_confirm_code' AND (`subject` LIKE ? OR `payload` LIKE ?)", 'ss', ["%{$code}%", "%{$code}%"]));
$t->ok('nothing removed yet', $evtExists($evt));

$bad = $run(['confirmation_id' => $confirmId, 'code' => $code === '000000' ? '111111' : '000000']);
$t->check('wrong code: 422', 422, $bad['status']);
$t->ok('wrong code says how many tries are left', str_contains((string) TestKit::get($bad, 'error.message'), '4 tries left'));

$other = $t->http('POST', '/auth/login', ['json' => ['email' => $admin['email'], 'password' => $admin['password']], 'jar' => 'retention-other']);
$t->check('a code from another sign-in session is refused', 410, $t->http('POST', '/admin/data/cleanup', [
    'jar' => 'retention-other',
    'csrf' => (string) TestKit::get($other, 'data.csrf_token'),
    'json' => ['confirmation_id' => $confirmId, 'code' => $code],
])['status']);

$done = $run(['confirmation_id' => $confirmId, 'code' => $code]);
$t->check('right code: clean-up runs', 200, $done['status']);
$t->ok('reviewed record removed', !$evtExists($evt));
$t->ok('clean-up reports what it removed', (int) TestKit::get($done, 'data.removed.old_gateway_notifications') >= 1);
$t->ok('clean-up is in the audit log', (int) $t->scalar("SELECT COUNT(*) FROM `admin_audit_logs` WHERE `admin_id` = ? AND `action` = 'data.cleanup'", 'i', [$admin['id']]) === 1);
$t->ok('all admins are told', (int) $t->scalar("SELECT COUNT(*) FROM `email_outbox` WHERE `template` = 'data_cleanup_done' AND `recipient_email` = ?", 's', [strtolower($admin['email'])]) === 1);
$t->cleanup(fn () => $t->sql("DELETE FROM `email_outbox` WHERE `template` IN ('data_cleanup_done', 'admin_confirm_code')"));
$t->check('a used code cannot be used again', 410, $run(['confirmation_id' => $confirmId, 'code' => $code])['status']);

$t->check('nothing to clean: no code sent', 422, $verify(['password' => $admin['password'], 'as_of' => $now()])['status']);

// Expired code.
$evt2 = $oldNotification();
$second = $verify(['password' => $admin['password'], 'as_of' => $now()]);
$t->sql("UPDATE `admin_confirmations` SET `expires_at` = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE `id` = ?", 's', [(string) TestKit::get($second, 'data.confirmation_id')]);
$t->check('an expired code is refused', 410, $run(['confirmation_id' => (string) TestKit::get($second, 'data.confirmation_id'), 'code' => (string) $t->confirmationCode(strtolower($admin['email']))])['status']);
$t->ok('and nothing was removed', $evtExists($evt2));

// Five wrong codes burn the confirmation.
$third = $verify(['password' => $admin['password'], 'as_of' => $now()]);
$thirdId = (string) TestKit::get($third, 'data.confirmation_id');
$thirdCode = (string) $t->confirmationCode(strtolower($admin['email']));
for ($i = 0; $i < 5; $i++) {
    $run(['confirmation_id' => $thirdId, 'code' => $thirdCode === '000000' ? '111111' : '000000']);
}
$t->check('after five wrong codes even the right one fails', 410, $run(['confirmation_id' => $thirdId, 'code' => $thirdCode])['status']);
$t->ok('and nothing was removed', $evtExists($evt2));

// Records that change between review and confirmation.
$reviewedAt = $now();
$fourth = $verify(['password' => $admin['password'], 'as_of' => $reviewedAt]);
$oldNotification(); // a record the admin did not see
$t->check('records that changed after review are refused', 409, $run(['confirmation_id' => (string) TestKit::get($fourth, 'data.confirmation_id'), 'code' => (string) $t->confirmationCode(strtolower($admin['email']))])['status']);
$t->ok('and nothing was removed', $evtExists($evt2));

// Throwaway counters touched between review and confirmation: the clean-up's own code email
// restarts the admin's old "codes sent" counter, and a returning visitor restarts theirs.
$counterId = static fn (string $bucket, string $key): string => hash('sha256', $bucket . '|' . strtolower(trim($key)));
$adminCounter = $counterId('cleanup_code_sent', (string) $admin['id']);
$visitorCounter = $counterId('checkout', '203.0.113.77');
$idleCounter = $counterId('checkout', '203.0.113.78');
$t->sql("UPDATE `rate_limits` SET `window_start` = UNIX_TIMESTAMP() - 172800 WHERE `id` = ?", 's', [$adminCounter]);
$t->sql("INSERT INTO `rate_limits` (`id`, `hits`, `window_start`) VALUES (?, 1, UNIX_TIMESTAMP() - 172800), (?, 1, UNIX_TIMESTAMP() - 172800)
         ON DUPLICATE KEY UPDATE `window_start` = VALUES(`window_start`)", 'ss', [$visitorCounter, $idleCounter]);
$fifth = $verify(['password' => $admin['password'], 'as_of' => $now()]);
$t->sql("UPDATE `rate_limits` SET `window_start` = UNIX_TIMESTAMP() WHERE `id` = ?", 's', [$visitorCounter]);
$fifthRun = $run(['confirmation_id' => (string) TestKit::get($fifth, 'data.confirmation_id'), 'code' => (string) $t->confirmationCode(strtolower($admin['email']))]);
$t->check("old counters touched after review (the code email itself, a returning visitor) don't spoil the clean-up", 200, $fifthRun['status']);
$counterLeft = static fn (string $id): bool => (int) $t->scalar("SELECT COUNT(*) FROM `rate_limits` WHERE `id` = ?", 's', [$id]) === 1;
$t->ok('the touched counters are kept, the idle old one is removed', $counterLeft($visitorCounter) && $counterLeft($adminCounter) && !$counterLeft($idleCounter));
$t->ok('and the reviewed records went', !$evtExists($evt2));

// Unticked lines: the admin leaves a kind of record out of the clean-up.
$keptEvt = $oldNotification();
$goneCounter = $counterId('checkout', '203.0.113.79');
$t->sql("INSERT INTO `rate_limits` (`id`, `hits`, `window_start`) VALUES (?, 1, UNIX_TIMESTAMP() - 172800)
         ON DUPLICATE KEY UPDATE `window_start` = VALUES(`window_start`)", 's', [$goneCounter]);
$everything = array_keys((array) TestKit::get($A('GET', '/admin/data'), 'data.labels'));
$t->check('unticking every line: nothing to clean, no code sent', 422, $verify(['password' => $admin['password'], 'as_of' => $now(), 'skip' => $everything])['status']);
$picked = $verify(['password' => $admin['password'], 'as_of' => $now(), 'skip' => ['old_gateway_notifications', 'no_such_rule']]);
$t->check('unticking a line: the code is sent for the rest', 200, $picked['status']);
$pickedCounts = (array) TestKit::get($picked, 'data.counts');
$t->ok('the code covers only the ticked lines', !array_key_exists('old_gateway_notifications', $pickedCounts) && ($pickedCounts['old_rate_limit_counters'] ?? 0) >= 1, json_encode($pickedCounts));
$oldNotification(); // the unticked kind changes after review: that must not stop the clean-up
$pickedRun = $run(['confirmation_id' => (string) TestKit::get($picked, 'data.confirmation_id'), 'code' => (string) $t->confirmationCode(strtolower($admin['email']))]);
$t->check('the clean-up runs', 200, $pickedRun['status']);
$t->ok('the ticked line was cleaned', !$counterLeft($goneCounter));
$t->ok('the unticked line was kept', $evtExists($keptEvt));
$t->check('and reported as untouched', null, TestKit::get($pickedRun, 'data.removed.old_gateway_notifications'));
$t->check('unticking one customer line leaves the whole customer group out', [], array_values(array_intersect(
    \App\Services\DatabaseCleanup::selection(['inactive_customer_orders']),
    ['inactive_customers', 'inactive_customer_accounts', 'inactive_customer_orders']
)));
// The notifications made here are removed by $oldNotification's own cleanup.

// Password lock-out.
for ($i = 0; $i < 5; $i++) {
    $verify(['password' => 'still-wrong', 'as_of' => $now()]);
}
$t->check('five wrong passwords lock the clean-up for a while', 429, $verify(['password' => $admin['password'], 'as_of' => $now()])['status']);

// -----------------------------------------------------------------------------
$t->section('Alert email to the admins');
$t->sql("DELETE FROM `email_outbox` WHERE `template` = 'storage_alert'");
$report = ['level' => 'warning', 'issues' => [['level' => 'warning', 'code' => 'size', 'title' => 'The database is getting large', 'detail' => 'It is 420 MB.']]];
$mail = new MailService($t->db);
$t->ok('alert queued for the admins', $mail->sendStorageAlertToAdmins($report) >= 1);
$alertRow = $t->db->fetchOne("SELECT `subject`, `payload` FROM `email_outbox` WHERE `template` = 'storage_alert' AND `recipient_email` = ?", 's', [strtolower($admin['email'])]);
$t->check('subject says what it is about', 'Your store database needs attention', $alertRow['subject'] ?? null);
$t->ok('the problem is in the email', str_contains((string) ($alertRow['payload'] ?? ''), 'getting large'));
$mail->sendStorageAlertToAdmins($report);
$t->check('not sent twice in the same week', 1, (int) $t->scalar("SELECT COUNT(*) FROM `email_outbox` WHERE `template` = 'storage_alert' AND `recipient_email` = ?", 's', [strtolower($admin['email'])]));

// -----------------------------------------------------------------------------
$t->section('A removed customer\'s never-paid order when that line is unticked');
// An inactive guest with one finished order and one cancelled order that was never paid.
$mixed = $t->uniq('ret-mixed') . '@example.test';
$mixedDone = $order($mixed, 'delivered', 130);
$mixedUnpaid = $order($mixed, 'cancelled', 125);
$t->sql("DELETE FROM `payments` WHERE `order_id` = ?", 'i', [$mixedUnpaid]);
$t->sql("UPDATE `orders` SET `payment_status` = 'pending', `updated_at` = `updated_at` WHERE `id` = ?", 'i', [$mixedUnpaid]);
$setting('retention_customer_days', '90');
$asOf = $now();
$all = $cleanup->snapshot($asOf);
$t->ok('listed as never paid', in_array((string) $mixedUnpaid, $all['ids']['unpaid_cancelled_orders'] ?? [], true));
$t->ok('all lines ticked: deleted as never paid, so not anonymised as well', !in_array((string) $mixedUnpaid, $all['ids']['inactive_customer_orders'] ?? [], true) && in_array((string) $mixedDone, $all['ids']['inactive_customer_orders'] ?? [], true));
$kept = $cleanup->snapshot($asOf, DatabaseCleanup::selection(['unpaid_cancelled_orders']));
$t->ok('never-paid line unticked: the order stays, so it is anonymised with the customer', in_array((string) $mixedUnpaid, $kept['ids']['inactive_customer_orders'] ?? [], true));

exit($t->finish());
