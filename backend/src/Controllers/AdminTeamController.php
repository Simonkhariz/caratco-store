<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Middleware\JsonBodyMiddleware;
use App\Services\AuditService;
use App\Services\MailService;
use App\Support\Input;
use App\Support\RateLimiter;
use App\Support\RequestContext;
use App\Support\Response;

/**
 * The admin team: list the admin accounts, add an admin, switch one off or back on, delete one.
 *
 * Adding, switching off or deleting an admin needs the signed-in admin's own password typed
 * again (a session left open on a shared computer is not enough). New accounts are always
 * "admin". The account made at first-run setup is the owner ("super_admin"): only an owner can
 * switch off or delete an owner, so a staff admin can never lock the owner out. Nobody can
 * switch off or delete their own account or the last active admin, so the shop always keeps
 * someone who can sign in. Switching off keeps the account; deleting removes it for good, and
 * the activity log keeps the deleted admin's name on everything they did
 * (admin_audit_logs.admin_label). An admin who is switched off or deleted also leaves the
 * "Send admin alerts to" list.
 */
class AdminTeamController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * GET /api/v1/admin/admins
     */
    public function index(): void
    {
        $me = $this->me();
        $rows = $this->db->fetchAll(
            "SELECT `id`, `email`, `full_name`, `role`, `status`, `created_at`, `last_seen_at`
             FROM `users` WHERE `role` IN ('admin', 'super_admin')
             ORDER BY (`status` = 'active') DESC, `created_at` ASC, `id` ASC"
        );
        $items = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'email' => (string) $r['email'],
            'full_name' => (string) $r['full_name'],
            'role' => (string) $r['role'],
            'status' => (string) $r['status'],
            'created_at' => $r['created_at'],
            'last_seen_at' => $r['last_seen_at'],
            'is_you' => (int) $r['id'] === (int) $me['id'],
        ], $rows);

        Response::success([
            'items' => $items,
            'min_password_length' => AdminOperationsController::ADMIN_MIN_PASSWORD,
        ], 'Admin accounts retrieved.');
    }

    /**
     * POST /api/v1/admin/admins  {full_name, email, password?, current_password}
     * With no password, one is made up and returned once (generated_password) for the creator
     * to pass on; it is never stored or shown again.
     */
    public function create(): void
    {
        $me = $this->me();
        $body = JsonBodyMiddleware::getBody();
        // At most 10 new admins an hour from one account, whatever happens to the requests.
        RateLimiter::attempt('admin_create', (string) $me['id'], 10, 3600, 'Too many admin accounts added. Try again in an hour.');

        $fullName = Input::singleSpaced($body['full_name'] ?? null);
        $email = strtolower(Input::text($body['email'] ?? null));
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $currentPassword = is_string($body['current_password'] ?? null) ? $body['current_password'] : '';

        $errors = [];
        if ($fullName === '') {
            $errors['full_name'] = 'Enter their full name.';
        } elseif (mb_strlen($fullName) > 255) {
            $errors['full_name'] = 'At most 255 characters.';
        }
        if ($email === '' || mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        $generated = null;
        if ($password === '') {
            $generated = self::generatePassword();
            $password = $generated;
        } elseif (($problem = AdminOperationsController::adminPasswordProblem($password)) !== null) {
            $errors['password'] = $problem;
        }
        if ($currentPassword === '') {
            $errors['current_password'] = 'Enter your own password to confirm.';
        }
        if ($errors !== []) {
            Response::error('Please fix the highlighted fields.', 422, 'VALIDATION_ERROR', $errors);
        }

        AdminOperationsController::requireCurrentPassword((int) $me['id'], $currentPassword, 'current_password', 'Your password is not right.');

        $existing = $this->db->fetchOne("SELECT `id`, `role` FROM `users` WHERE `email` = ? LIMIT 1", 's', [$email]);
        if ($existing !== null) {
            $isAdmin = in_array($existing['role'], ['admin', 'super_admin'], true);
            Response::error(
                $isAdmin ? 'This email already has an admin account.' : 'This email belongs to a customer account. Use a different email for the admin.',
                409,
                'EMAIL_ALREADY_EXISTS',
                ['email' => $isAdmin ? 'Already an admin.' : 'Used by a customer account.']
            );
        }

        $id = (int) $this->db->insert(
            "INSERT INTO `users` (`uuid`, `email`, `password_hash`, `full_name`, `role`, `status`, `email_verified_at`)
             VALUES (UUID(), ?, ?, ?, 'admin', 'active', NOW())",
            'sss',
            [$email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $fullName]
        );

        AuditService::record((int) $me['id'], 'admin.create', 'admin', (string) $id, null, [
            'email' => $email,
            'full_name' => $fullName,
            'role' => 'admin',
            'credential_made_up' => $generated !== null,
        ]);

        Response::success([
            'id' => $id,
            'email' => $email,
            'full_name' => $fullName,
            'role' => 'admin',
            'status' => 'active',
            'generated_password' => $generated,
        ], $generated !== null
            ? 'Admin added. Copy the password now: it will not be shown again.'
            : 'Admin added. They can sign in with the password you chose.', 201);
    }

    /**
     * PATCH /api/v1/admin/admins/{id}/status  {status: active|suspended, current_password}
     * Switching an admin off signs them out everywhere.
     */
    public function updateStatus(array $params): void
    {
        $me = $this->me();
        $body = JsonBodyMiddleware::getBody();
        $id = Input::wholeNumber($params['id'] ?? null, 1, PHP_INT_MAX);
        $status = Input::text($body['status'] ?? null);
        $currentPassword = is_string($body['current_password'] ?? null) ? $body['current_password'] : '';

        if (!in_array($status, ['active', 'suspended'], true)) {
            Response::error('Choose active or suspended.', 422, 'VALIDATION_ERROR', ['status' => 'Must be active or suspended.']);
        }
        $target = $id === null ? null : $this->db->fetchOne(
            "SELECT `id`, `email`, `full_name`, `role`, `status` FROM `users` WHERE `id` = ? AND `role` IN ('admin', 'super_admin')",
            'i',
            [$id]
        );
        if ($target === null) {
            Response::error('Admin account not found.', 404, 'NOT_FOUND');
        }
        if ((int) $target['id'] === (int) $me['id']) {
            Response::error('You cannot switch off your own account.', 422, 'CANNOT_CHANGE_SELF');
        }
        if ($target['role'] === 'super_admin' && $me['role'] !== 'super_admin') {
            Response::error('Only a super admin can change a super admin account.', 403, 'FORBIDDEN');
        }
        if ($currentPassword === '') {
            Response::error('Enter your own password to confirm.', 422, 'VALIDATION_ERROR', ['current_password' => 'Enter your own password to confirm.']);
        }
        AdminOperationsController::requireCurrentPassword((int) $me['id'], $currentPassword, 'current_password', 'Your password is not right.');

        if ($target['status'] === $status) {
            Response::success(['id' => (int) $target['id'], 'status' => $status], $status === 'active' ? 'Already active.' : 'Already switched off.');
        }

        $alertsChanged = false;
        $problem = $this->db->transaction(function (Database $db) use ($me, $target, $status, &$alertsChanged): ?string {
            $problem = self::lockedTeamProblem($db, (int) $me['id'], (int) $target['id'], $status === 'suspended');
            if ($problem !== null) {
                return $problem;
            }
            $db->executeUpdate("UPDATE `users` SET `status` = ? WHERE `id` = ?", 'si', [$status, (int) $target['id']]);
            if ($status === 'suspended') {
                $db->execute("DELETE FROM `sessions` WHERE `user_id` = ?", 'i', [(int) $target['id']]);
                $alertsChanged = self::dropFromAlertEmails($db, (string) $target['email']);
            }
            return null;
        });
        self::answerTeamProblem($problem);

        AuditService::record(
            (int) $me['id'],
            $status === 'suspended' ? 'admin.suspend' : 'admin.reactivate',
            'admin',
            (string) $target['id'],
            ['status' => $target['status'], 'email' => $target['email']],
            ['status' => $status, 'email' => $target['email']]
        );

        Response::success(
            ['id' => (int) $target['id'], 'status' => $status],
            $status === 'suspended'
                ? "{$target['full_name']} is switched off and signed out." . ($alertsChanged ? ' Their address was also removed from "Send admin alerts to".' : '')
                : "{$target['full_name']} can sign in again."
        );
    }

    /**
     * POST /api/v1/admin/admins/{id}/delete  {current_password}
     * Removes the admin account for good and signs it out everywhere. Their orders, stock changes
     * and refunds stay; the activity log keeps their name on what they did.
     */
    public function delete(array $params): void
    {
        $me = $this->me();
        $body = JsonBodyMiddleware::getBody();
        $id = Input::wholeNumber($params['id'] ?? null, 1, PHP_INT_MAX);
        $currentPassword = is_string($body['current_password'] ?? null) ? $body['current_password'] : '';

        $target = $id === null ? null : $this->db->fetchOne(
            "SELECT `id`, `email`, `full_name`, `role`, `status` FROM `users` WHERE `id` = ? AND `role` IN ('admin', 'super_admin')",
            'i',
            [$id]
        );
        if ($target === null) {
            Response::error('Admin account not found.', 404, 'NOT_FOUND');
        }
        if ((int) $target['id'] === (int) $me['id']) {
            Response::error('You cannot delete your own account.', 422, 'CANNOT_CHANGE_SELF');
        }
        if ($target['role'] === 'super_admin' && $me['role'] !== 'super_admin') {
            Response::error('Only a super admin can delete a super admin account.', 403, 'FORBIDDEN');
        }
        if ($currentPassword === '') {
            Response::error('Enter your own password to confirm.', 422, 'VALIDATION_ERROR', ['current_password' => 'Enter your own password to confirm.']);
        }
        AdminOperationsController::requireCurrentPassword((int) $me['id'], $currentPassword, 'current_password', 'Your password is not right.');

        $label = mb_substr("{$target['full_name']} (deleted admin, {$target['email']})", 0, 600);
        $alertsChanged = false;
        $problem = $this->db->transaction(function (Database $db) use ($me, $target, $label, &$alertsChanged): ?string {
            $problem = self::lockedTeamProblem($db, (int) $me['id'], (int) $target['id'], true);
            if ($problem !== null) {
                return $problem;
            }
            // Deleting the account empties admin_id on their activity rows: keep their name there.
            $db->execute("UPDATE `admin_audit_logs` SET `admin_label` = ? WHERE `admin_id` = ?", 'si', [$label, (int) $target['id']]);
            $db->execute("DELETE FROM `users` WHERE `id` = ? AND `role` IN ('admin', 'super_admin')", 'i', [(int) $target['id']]);
            $alertsChanged = self::dropFromAlertEmails($db, (string) $target['email']);
            return null;
        });
        self::answerTeamProblem($problem);

        AuditService::record((int) $me['id'], 'admin.delete', 'admin', (string) $target['id'], [
            'email' => $target['email'],
            'full_name' => $target['full_name'],
            'role' => $target['role'],
            'status' => $target['status'],
        ], null);

        Response::success(
            ['id' => (int) $target['id']],
            "{$target['full_name']}'s admin account is deleted." . ($alertsChanged ? ' Their address was also removed from "Send admin alerts to".' : '')
        );
    }

    /**
     * Inside the transaction: lock the active admin rows (so two admins acting on each other at
     * once cannot leave the shop with nobody who can sign in) and say what stops the change.
     * Never answers from in here: an answer ends the request, and the transaction must close first.
     *
     * @return 'ACTOR_GONE'|'LAST_ADMIN'|null
     */
    private static function lockedTeamProblem(Database $db, int $actorId, int $targetId, bool $removing): ?string
    {
        $active = array_map('intval', array_column($db->fetchAll(
            "SELECT `id` FROM `users` WHERE `role` IN ('admin', 'super_admin') AND `status` = 'active' FOR UPDATE"
        ), 'id'));
        // The admin doing this was switched off or deleted a moment ago by someone else.
        if (!in_array($actorId, $active, true)) {
            return 'ACTOR_GONE';
        }
        if ($removing && array_diff($active, [$targetId]) === []) {
            return 'LAST_ADMIN';
        }
        return null;
    }

    private static function answerTeamProblem(?string $problem): void
    {
        if ($problem === 'ACTOR_GONE') {
            Response::error('Administrator sign-in required.', 401, 'UNAUTHENTICATED');
        }
        if ($problem === 'LAST_ADMIN') {
            Response::error('The shop needs at least one active admin.', 422, 'LAST_ADMIN');
        }
    }

    /**
     * An admin who can no longer sign in stops getting the shop's alerts: take their address out
     * of "Send admin alerts to". True when the setting changed. (Emptying it means alerts go to
     * every active admin account again.)
     */
    private static function dropFromAlertEmails(Database $db, string $email): bool
    {
        $row = $db->fetchOne("SELECT `value` FROM `store_settings` WHERE `key_name` = ?", 's', [MailService::ALERT_EMAIL_SETTING]);
        $listed = MailService::parseAlertEmails((string) ($row['value'] ?? ''));
        if ($listed === null || !in_array(strtolower($email), $listed, true)) {
            return false;
        }
        $kept = array_values(array_diff($listed, [strtolower($email)]));
        $db->execute("UPDATE `store_settings` SET `value` = ? WHERE `key_name` = ?", 'ss', [implode(', ', $kept), MailService::ALERT_EMAIL_SETTING]);
        return true;
    }

    /** @return array{id: int|string, role: string} */
    private function me(): array
    {
        $user = RequestContext::getUser();
        if ($user === null) {
            Response::error('Administrator sign-in required.', 401, 'UNAUTHENTICATED');
        }
        return $user;
    }

    /** 16 letters and digits in groups of four (no 0/O, 1/l/I), e.g. "k7Qm-3xTz-9pWb-R4nd". */
    private static function generatePassword(): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $group = '';
            for ($i = 0; $i < 4; $i++) {
                $group .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $group;
        }
        return implode('-', $groups);
    }
}
