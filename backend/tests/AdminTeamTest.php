<?php

declare(strict_types=1);

/**
 * Admin alerts ("Send admin alerts to"), changing the admin's own sign-in email, and the admin
 * team (list, add an admin, switch one off and back on).
 *
 *   php backend/tests/run.php AdminTeam
 */

require __DIR__ . '/support/TestKit.php';

use App\Services\MailService;

$t = new TestKit('AdminTeam');
$admin = $t->adminSession('team');
$A = static fn (string $method, string $path, array $opts = []): array
    => $t->http($method, $path, $opts + ['jar' => $admin['jar'], 'csrf' => $admin['csrf']]);
$mail = new MailService($t->db);

$oldAlert = $t->scalar("SELECT `value` FROM `store_settings` WHERE `key_name` = 'admin_alert_email'");
$t->cleanup(function () use ($t, $oldAlert): void {
    if ($oldAlert === null) {
        $t->sql("DELETE FROM `store_settings` WHERE `key_name` = 'admin_alert_email'");
    } else {
        $t->sql("UPDATE `store_settings` SET `value` = ? WHERE `key_name` = 'admin_alert_email'", 's', [(string) $oldAlert]);
    }
});
$t->sql("DELETE FROM `store_settings` WHERE `key_name` = 'admin_alert_email'");

// -----------------------------------------------------------------------------
$t->section('Send admin alerts to');
$settings = $A('GET', '/admin/settings');
$t->ok('the setting is editable in Admin -> Settings', in_array('admin_alert_email', array_column((array) TestKit::get($settings, 'data.editable'), 'key'), true));
$recipients = (array) TestKit::get($settings, 'data.readonly.admin_alert_recipients');
$t->ok('empty: alerts go to every active admin account (this one included)', in_array($admin['email'], $recipients, true));
$t->check('settings and the mailer agree', $recipients, $mail->adminRecipients());

$t->check('a bad address is refused', 422, $A('PUT', '/admin/settings', ['json' => ['admin_alert_email' => 'owner@example.test, nope']])['status']);
$tooMany = implode(',', array_map(static fn (int $i): string => "a{$i}@example.test", range(1, 11)));
$t->check('more than 10 addresses refused', 422, $A('PUT', '/admin/settings', ['json' => ['admin_alert_email' => $tooMany]])['status']);
$t->check('a list is refused', 422, $A('PUT', '/admin/settings', ['json' => ['admin_alert_email' => ['owner@example.test']]])['status']);

$saved = $A('PUT', '/admin/settings', ['json' => ['admin_alert_email' => ' Owner@Example.test ; ops@example.test,owner@example.test ']]);
$t->check('saved tidy: lower case, no repeats, comma-separated', [200, 'owner@example.test, ops@example.test'], [$saved['status'], TestKit::get($saved, 'data.admin_alert_email')]);
$t->check('alerts now go only there', ['owner@example.test', 'ops@example.test'], $mail->adminRecipients());
$t->check('Settings shows where alerts go', ['owner@example.test', 'ops@example.test'], TestKit::get($A('GET', '/admin/settings'), 'data.readonly.admin_alert_recipients'));

$level = 'test-' . bin2hex(random_bytes(3));
$t->cleanup(fn () => $t->sql("DELETE FROM `email_outbox` WHERE `template` = 'storage_alert' AND `dedup_key` LIKE ?", 's', ["storage_alert:{$level}:%"]));
$t->check('a storage alert is queued for the two addresses', 2, $mail->sendStorageAlertToAdmins(['level' => $level, 'issues' => []]));
$t->check('and not for the admin accounts', 0, (int) $t->scalar(
    "SELECT COUNT(*) FROM `email_outbox` WHERE `template` = 'storage_alert' AND `dedup_key` LIKE ? AND `recipient_email` = ?",
    'ss',
    ["storage_alert:{$level}:%", $admin['email']]
));

$cleanupBefore = (int) $t->scalar("SELECT COALESCE(MAX(`id`), 0) FROM `email_outbox`");
$t->cleanup(fn () => $t->sql("DELETE FROM `email_outbox` WHERE `template` = 'data_cleanup_done' AND `id` > ?", 'i', [$cleanupBefore]));
$mail->sendCleanupDoneToAdmins('Test team', '127.0.0.1', ['Test' => 1]);
$cleanupTo = array_column($t->sql("SELECT `recipient_email` FROM `email_outbox` WHERE `template` = 'data_cleanup_done' AND `id` > ?", 'i', [$cleanupBefore]), 'recipient_email');
$t->ok('a clean-up notice (security) still reaches every admin account', in_array($admin['email'], $cleanupTo, true));
$t->ok('and the alert addresses', in_array('owner@example.test', $cleanupTo, true) && in_array('ops@example.test', $cleanupTo, true));

$t->check('emptied again', 200, $A('PUT', '/admin/settings', ['json' => ['admin_alert_email' => '']])['status']);
$t->ok('alerts go back to the admin accounts', in_array($admin['email'], $mail->adminRecipients(), true));

// -----------------------------------------------------------------------------
$t->section('Changing the sign-in email');
$profile = ['full_name' => 'Test team', 'phone' => ''];
$newEmail = $t->uniq('t-team-new') . '@example.test';
$t->check('needs the current password', 422, $A('PUT', '/admin/account', ['json' => $profile + ['email' => $newEmail]])['status']);
$t->check('a wrong password is refused', 422, $A('PUT', '/admin/account', ['json' => $profile + ['email' => $newEmail, 'current_password' => 'nope']])['status']);
$t->check('a bad address is refused', 422, $A('PUT', '/admin/account', ['json' => $profile + ['email' => 'not-an-email', 'current_password' => $admin['password']]])['status']);
$custEmail = $t->uniq('t-team-cust') . '@example.test';
$custId = (int) $t->db->insert("INSERT INTO `users` (`uuid`, `email`, `full_name`, `role`, `status`) VALUES (UUID(), ?, 'Team Customer', 'customer', 'active')", 's', [$custEmail]);
$t->cleanup(fn () => $t->sql("DELETE FROM `users` WHERE `id` = ?", 'i', [$custId]));
$t->check("another account's email is refused", 409, $A('PUT', '/admin/account', ['json' => $profile + ['email' => strtoupper($custEmail), 'current_password' => $admin['password']]])['status']);
$changed = $A('PUT', '/admin/account', ['json' => $profile + ['email' => strtoupper($newEmail), 'current_password' => $admin['password']]]);
$t->check('changed (saved in lower case)', [200, $newEmail], [$changed['status'], TestKit::get($changed, 'data.email')]);
$t->check('users.email is updated', $newEmail, $t->scalar("SELECT `email` FROM `users` WHERE `id` = ?", 'i', [$admin['id']]));
$t->check('the account page shows it', $newEmail, TestKit::get($A('GET', '/admin/account'), 'data.email'));
$t->ok('admin alerts follow the new email', in_array($newEmail, $mail->adminRecipients(), true) && !in_array($admin['email'], $mail->adminRecipients(), true));
$t->check('sign in with the new email', 200, $t->http('POST', '/auth/login', ['json' => ['email' => $newEmail, 'password' => $admin['password']], 'jar' => 'team-new-email'])['status']);
$t->check('the old email no longer signs in', 401, $t->http('POST', '/auth/login', ['json' => ['email' => $admin['email'], 'password' => $admin['password']]])['status']);
$t->check('audited as admin.email_change', 1, (int) $t->scalar("SELECT COUNT(*) FROM `admin_audit_logs` WHERE `admin_id` = ? AND `action` = 'admin.email_change'", 'i', [$admin['id']]));
$t->check('a name-only change needs no password', 200, $A('PUT', '/admin/account', ['json' => ['full_name' => 'Test team renamed', 'phone' => '']])['status']);

// -----------------------------------------------------------------------------
$t->section('Admin team');
$list = $A('GET', '/admin/admins');
$me = array_values(array_filter((array) TestKit::get($list, 'data.items'), static fn (array $a): bool => (int) $a['id'] === $admin['id']));
$t->check('lists the admin accounts, marking yours', [200, true], [$list['status'], (bool) ($me[0]['is_you'] ?? false)]);
$t->ok('no password hashes in the list', !str_contains($list['body'], 'password_hash') && !str_contains($list['body'], '$2y$'));
$t->check('signed out: refused', 401, $t->http('GET', '/admin/admins')['status']);

$addEmail = $t->uniq('t-team-add') . '@example.test';
$t->cleanup(function () use ($t, $addEmail): void {
    $id = (int) $t->scalar("SELECT `id` FROM `users` WHERE `email` = ?", 's', [$addEmail]);
    $t->sql("DELETE FROM `sessions` WHERE `user_id` = ?", 'i', [$id]);
    $t->sql("DELETE FROM `admin_audit_logs` WHERE `admin_id` = ?", 'i', [$id]);
    $t->sql("DELETE FROM `users` WHERE `id` = ?", 'i', [$id]);
});
$t->check('needs your own password', 422, $A('POST', '/admin/admins', ['json' => ['full_name' => 'New Admin', 'email' => $addEmail]])['status']);
$t->check('a wrong password is refused', 422, $A('POST', '/admin/admins', ['json' => ['full_name' => 'New Admin', 'email' => $addEmail, 'current_password' => 'nope']])['status']);
$short = $A('POST', '/admin/admins', ['json' => ['full_name' => 'New Admin', 'email' => $addEmail, 'password' => 'short', 'current_password' => $admin['password']]]);
$t->check('a password under 10 characters is refused', [422, true], [$short['status'], is_string(TestKit::get($short, 'error.details.password'))]);
$t->check('a name is required', 422, $A('POST', '/admin/admins', ['json' => ['full_name' => '  ', 'email' => $addEmail, 'current_password' => $admin['password']]])['status']);
$t->check("a customer's email is refused", 409, $A('POST', '/admin/admins', ['json' => ['full_name' => 'New Admin', 'email' => $custEmail, 'current_password' => $admin['password']]])['status']);
$t->check("an admin's email is refused", 409, $A('POST', '/admin/admins', ['json' => ['full_name' => 'New Admin', 'email' => $newEmail, 'current_password' => $admin['password']]])['status']);

$added = $A('POST', '/admin/admins', ['json' => ['full_name' => 'New  Admin', 'email' => strtoupper($addEmail), 'current_password' => $admin['password']]]);
$generated = (string) TestKit::get($added, 'data.generated_password');
$addedId = (int) TestKit::get($added, 'data.id');
$t->check('added with a made-up password, shown once', [201, 'admin', $addEmail, 'New Admin'], [$added['status'], TestKit::get($added, 'data.role'), TestKit::get($added, 'data.email'), TestKit::get($added, 'data.full_name')]);
$t->ok('the made-up password is 16 letters and digits in groups', (bool) preg_match('/^[A-Za-z2-9]{4}(-[A-Za-z2-9]{4}){3}$/', $generated), $generated);
$t->ok('only its hash is stored', password_verify($generated, (string) $t->scalar("SELECT `password_hash` FROM `users` WHERE `id` = ?", 'i', [$addedId])));
$login = $t->http('POST', '/auth/login', ['json' => ['email' => $addEmail, 'password' => $generated], 'jar' => 'team-added']);
$t->check('the new admin can sign in to the admin', [200, 200], [$login['status'], $t->http('GET', '/admin/account', ['jar' => 'team-added'])['status']]);
$t->check('audited as admin.create (no password in the log)', [1, false], [
    (int) $t->scalar("SELECT COUNT(*) FROM `admin_audit_logs` WHERE `admin_id` = ? AND `action` = 'admin.create' AND `entity_id` = ?", 'is', [$admin['id'], (string) $addedId]),
    str_contains((string) $t->scalar("SELECT `after_state` FROM `admin_audit_logs` WHERE `action` = 'admin.create' AND `entity_id` = ?", 's', [(string) $addedId]), $generated),
]);
$t->check('the same email twice is refused', 409, $A('POST', '/admin/admins', ['json' => ['full_name' => 'Again', 'email' => $addEmail, 'current_password' => $admin['password']]])['status']);

$chosenEmail = $t->uniq('t-team-chosen') . '@example.test';
$t->cleanup(function () use ($t, $chosenEmail): void {
    $id = (int) $t->scalar("SELECT `id` FROM `users` WHERE `email` = ?", 's', [$chosenEmail]);
    $t->sql("DELETE FROM `sessions` WHERE `user_id` = ?", 'i', [$id]);
    $t->sql("DELETE FROM `users` WHERE `id` = ?", 'i', [$id]);
});
$chosen = $A('POST', '/admin/admins', ['json' => ['full_name' => 'Chosen Admin', 'email' => $chosenEmail, 'password' => 'chosen-pass-123', 'current_password' => $admin['password']]]);
$t->check('added with a password the creator chose (none returned)', [201, null], [$chosen['status'], TestKit::get($chosen, 'data.generated_password')]);
$t->check('which signs in', 200, $t->http('POST', '/auth/login', ['json' => ['email' => $chosenEmail, 'password' => 'chosen-pass-123']])['status']);

$t->section('Switching an admin off');
$t->check('not yourself', 422, $A('PATCH', "/admin/admins/{$admin['id']}/status", ['json' => ['status' => 'suspended', 'current_password' => $admin['password']]])['status']);
$t->check('needs your own password', 422, $A('PATCH', "/admin/admins/{$addedId}/status", ['json' => ['status' => 'suspended']])['status']);
$t->check('a bad status is refused', 422, $A('PATCH', "/admin/admins/{$addedId}/status", ['json' => ['status' => 'deleted', 'current_password' => $admin['password']]])['status']);
$t->check('a customer is not an admin account', 404, $A('PATCH', "/admin/admins/{$custId}/status", ['json' => ['status' => 'suspended', 'current_password' => $admin['password']]])['status']);
$off = $A('PATCH', "/admin/admins/{$addedId}/status", ['json' => ['status' => 'suspended', 'current_password' => $admin['password']]]);
$t->check('switched off', [200, 'suspended'], [$off['status'], $t->scalar("SELECT `status` FROM `users` WHERE `id` = ?", 'i', [$addedId])]);
$t->check('and signed out everywhere', [0, 401], [(int) $t->scalar("SELECT COUNT(*) FROM `sessions` WHERE `user_id` = ?", 'i', [$addedId]), $t->http('GET', '/admin/account', ['jar' => 'team-added'])['status']]);
$t->check('cannot sign in while off', 403, $t->http('POST', '/auth/login', ['json' => ['email' => $addEmail, 'password' => $generated]])['status']);
$t->ok('alerts no longer go to them', !in_array($addEmail, $mail->adminRecipients(), true));
$t->check('switched back on', 200, $A('PATCH', "/admin/admins/{$addedId}/status", ['json' => ['status' => 'active', 'current_password' => $admin['password']]])['status']);
$t->check('and can sign in again', 200, $t->http('POST', '/auth/login', ['json' => ['email' => $addEmail, 'password' => $generated]])['status']);
$t->check('both audited', 2, (int) $t->scalar("SELECT COUNT(*) FROM `admin_audit_logs` WHERE `entity_type` = 'admin' AND `entity_id` = ? AND `action` IN ('admin.suspend', 'admin.reactivate')", 's', [(string) $addedId]));

$t->sql("UPDATE `users` SET `role` = 'super_admin' WHERE `id` = ?", 'i', [$addedId]);
$t->check('an admin cannot switch off a super admin', 403, $A('PATCH', "/admin/admins/{$addedId}/status", ['json' => ['status' => 'suspended', 'current_password' => $admin['password']]])['status']);
$t->sql("UPDATE `users` SET `role` = 'admin' WHERE `id` = ?", 'i', [$addedId]);

// -----------------------------------------------------------------------------
$t->section('Deleting an admin');
$D = static fn (int $id, array $json): array => $A('POST', "/admin/admins/{$id}/delete", ['json' => $json]);
$t->check('not yourself', [422, 'CANNOT_CHANGE_SELF'], [($r = $D((int) $admin['id'], ['current_password' => $admin['password']]))['status'], TestKit::get($r, 'error.code')]);
$t->check('needs your own password', 422, $D($addedId, [])['status']);
$t->check('a wrong password is refused', 422, $D($addedId, ['current_password' => 'not-my-password'])['status']);
$t->check('a customer is not an admin account', 404, $D($custId, ['current_password' => $admin['password']])['status']);
$t->check('an unknown id is 404', 404, $D(2147483600, ['current_password' => $admin['password']])['status']);
$t->sql("UPDATE `users` SET `role` = 'super_admin' WHERE `id` = ?", 'i', [$addedId]);
$t->check('an admin cannot delete a super admin', 403, $D($addedId, ['current_password' => $admin['password']])['status']);
$t->sql("UPDATE `users` SET `role` = 'admin' WHERE `id` = ?", 'i', [$addedId]);
$t->check('nothing was deleted by the refused tries', 1, (int) $t->scalar("SELECT COUNT(*) FROM `users` WHERE `id` = ?", 'i', [$addedId]));

// The admin about to be deleted does something first, so their name must stay on it.
$t->http('POST', '/auth/login', ['json' => ['email' => $addEmail, 'password' => $generated], 'jar' => 'team-added']);
$t->sql("INSERT INTO `admin_audit_logs` (`admin_id`, `action`, `entity_type`, `entity_id`) VALUES (?, 'test.kept_name', 'admin', ?)", 'is', [$addedId, (string) $addedId]);
$t->cleanup(fn () => $t->sql("DELETE FROM `admin_audit_logs` WHERE `action` = 'test.kept_name'"));
$gone = $D($addedId, ['current_password' => $admin['password']]);
$t->check('deleted', [200, 0], [$gone['status'], (int) $t->scalar("SELECT COUNT(*) FROM `users` WHERE `id` = ?", 'i', [$addedId])]);
$t->check('signed out everywhere and cannot sign in', [0, 401, 401], [
    (int) $t->scalar("SELECT COUNT(*) FROM `sessions` WHERE `user_id` = ?", 'i', [$addedId]),
    $t->http('GET', '/admin/account', ['jar' => 'team-added'])['status'],
    $t->http('POST', '/auth/login', ['json' => ['email' => $addEmail, 'password' => $generated]])['status'],
]);
$t->check('their past activity keeps their name', "New Admin (deleted admin, {$addEmail})", $t->scalar("SELECT `admin_label` FROM `admin_audit_logs` WHERE `action` = 'test.kept_name'"));
$shown = null;
foreach ((array) TestKit::get($A('GET', '/admin/audit-logs?action=test.kept_name'), 'data.items') as $item) {
    $shown = $item['admin_name'] ?? null;
}
$t->check('and the activity log shows it', "New Admin (deleted admin, {$addEmail})", $shown);
$t->check('audited as admin.delete by the admin who did it', 1, (int) $t->scalar("SELECT COUNT(*) FROM `admin_audit_logs` WHERE `admin_id` = ? AND `action` = 'admin.delete' AND `entity_id` = ?", 'is', [$admin['id'], (string) $addedId]));
$t->ok('no longer in the team list', !in_array($addEmail, array_column((array) TestKit::get($A('GET', '/admin/admins'), 'data.items'), 'email'), true));
$t->ok('alerts no longer go to them', !in_array($addEmail, $mail->adminRecipients(), true));
$t->check('deleting them again is 404', 404, $D($addedId, ['current_password' => $admin['password']])['status']);
// "Send admin alerts to" must not keep mailing someone who can no longer sign in.
$alertsBefore = $t->db->fetchOne("SELECT `value` FROM `store_settings` WHERE `key_name` = 'admin_alert_email'");
$t->cleanup(function () use ($t, $alertsBefore): void {
    if ($alertsBefore === null) {
        $t->sql("DELETE FROM `store_settings` WHERE `key_name` = 'admin_alert_email'");
    } else {
        $t->sql("UPDATE `store_settings` SET `value` = ? WHERE `key_name` = 'admin_alert_email'", 's', [(string) $alertsBefore['value']]);
    }
});
// Made directly (not through the API): the suite is close to the 10-new-admins-an-hour limit.
$leaverEmail = $t->uniq('t-team-leaver') . '@example.test';
$leaverId = (int) $t->db->insert(
    "INSERT INTO `users` (`uuid`, `email`, `password_hash`, `full_name`, `role`, `status`, `email_verified_at`) VALUES (UUID(), ?, 'x', 'Leaving Admin', 'admin', 'active', NOW())",
    's',
    [$leaverEmail]
);
$t->cleanup(fn () => $t->sql("DELETE FROM `users` WHERE `id` = ?", 'i', [$leaverId]));
$t->sql("INSERT INTO `store_settings` (`key_name`, `value`) VALUES ('admin_alert_email', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", 's', ["keeper@example.test, {$leaverEmail}"]);
$leaverGone = $D($leaverId, ['current_password' => $admin['password']]);
$t->check('a deleted admin leaves "Send admin alerts to", and the answer says so', [200, 'keeper@example.test', true], [
    $leaverGone['status'],
    $t->scalar("SELECT `value` FROM `store_settings` WHERE `key_name` = 'admin_alert_email'"),
    str_contains((string) TestKit::get($leaverGone, 'message'), 'Send admin alerts to'),
]);
$t->ok('alerts go only to the address that is left', $mail->adminRecipients() === ['keeper@example.test']);
$t->sql("UPDATE `store_settings` SET `value` = '' WHERE `key_name` = 'admin_alert_email'");

$t->check('their email can be used for a new admin', 201, ($again = $A('POST', '/admin/admins', ['json' => ['full_name' => 'Back Again', 'email' => $addEmail, 'current_password' => $admin['password']]]))['status']);
$addedId = (int) TestKit::get($again, 'data.id'); // the suite's clean-up removes this one by email

// Last: five wrong passwords lock these checks for 15 minutes (a separate admin, so the
// rest of this suite is not locked out).
$t->section('Wrong passwords are limited');
$other = $t->adminSession('team-limit');
$O = static fn (string $method, string $path, array $opts = []): array
    => $t->http($method, $path, $opts + ['jar' => $other['jar'], 'csrf' => $other['csrf']]);
for ($i = 1; $i <= 5; $i++) {
    $O('POST', '/admin/admins', ['json' => ['full_name' => 'X', 'email' => "x{$i}@example.test", 'current_password' => "wrong-{$i}"]]);
}
$t->check('6th try -> 429, even with the right password', 429, $O('POST', '/admin/admins', ['json' => ['full_name' => 'X', 'email' => 'x6@example.test', 'current_password' => $other['password']]])['status']);

exit($t->finish());
