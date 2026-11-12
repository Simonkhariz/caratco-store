<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Middleware\JsonBodyMiddleware;
use App\Services\AuditService;
use App\Services\DatabaseCleanup;
use App\Services\MailService;
use App\Services\StorageHealth;
use App\Support\DevSwitches;
use App\Support\Cache;
use App\Support\Input;
use App\Support\RateLimiter;
use App\Support\RequestContext;
use App\Support\Response;
use DomainException;

/**
 * Admin → Data & storage: keep periods, database size and speed, the exact records that are
 * past their keep period, and the clean-up itself.
 *
 * Nothing is removed automatically. A clean-up needs, in order:
 *   1. the admin reviews the records (all lists are computed "as of" the time the page loaded);
 *   2. POST cleanup/verify with their password: a 6-digit code is emailed to them;
 *   3. POST cleanup with that code (10 minutes, 5 tries, once, same sign-in session).
 * It then removes exactly the reviewed records, or refuses if they changed. Every step is in
 * the audit log and all admins get an email when a clean-up has run.
 */
final class AdminDataController
{
    private const ACTION = 'data_cleanup';
    private const CODE_MINUTES = 10;
    private const CODE_TRIES = 5;
    /** How long a reviewed list stays valid for a clean-up. */
    private const REVIEW_MINUTES = 30;

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * GET /api/v1/admin/data
     * Keep periods with their choices, the full health report and what is past its keep
     * period right now (as_of: the time every list on the page is computed for).
     */
    public function overview(): void
    {
        DevSwitches::requireDevMode();
        $health = new StorageHealth($this->db);

        Response::success([
            'settings' => (new DatabaseCleanup($this->db))->settings() + ['storage_alert_mb' => $health->alertMb()],
            'options' => DatabaseCleanup::OPTIONS + ['storage_alert_mb' => StorageHealth::ALERT_MB_OPTIONS],
            'labels' => DatabaseCleanup::LABELS,
            'housekeeping' => DatabaseCleanup::HOUSEKEEPING,
            'health' => $health->report(date('Y-m-d H:i:s'), true),
            'mail_mode' => MailService::mode(),
        ], 'Data and storage retrieved.');
    }

    /**
     * GET /api/v1/admin/data/records?rule=…&as_of=…&page=…
     * The records one rule would remove, 25 per page.
     */
    public function records(): void
    {
        DevSwitches::requireDevMode();
        $rule = (string) ($_GET['rule'] ?? '');
        if (!array_key_exists($rule, DatabaseCleanup::LABELS)) {
            Response::error('Unknown kind of data.', 422, 'VALIDATION_ERROR');
        }
        $asOf = $this->reviewTime((string) ($_GET['as_of'] ?? ''));
        $page = Input::page($_GET['page'] ?? 1);
        $perPage = 25;

        try {
            $result = (new DatabaseCleanup($this->db))->records($rule, $asOf, $page, $perPage);
        } catch (DomainException $e) {
            Response::error($e->getMessage(), 422, 'VALIDATION_ERROR');
        }

        $pagination = ['total' => $result['total'], 'page' => $page, 'per_page' => $perPage, 'total_pages' => (int) ceil($result['total'] / $perPage)];
        Response::success(
            ['rule' => $rule, 'label' => DatabaseCleanup::LABELS[$rule], 'columns' => $result['columns'], 'items' => $result['items'], 'pagination' => $pagination],
            'Records retrieved.',
            200,
            $pagination
        );
    }

    /**
     * GET /api/v1/admin/data/alert
     * Quick check for the admin alert bell, cached for a few minutes (and refreshed after
     * any admin change, such as a clean-up).
     */
    public function alert(): void
    {
        $result = Cache::remember('admin-storage-alert', function (): array {
            $report = (new StorageHealth($this->db))->report(date('Y-m-d H:i:s'), false);
            return ['level' => $report['level'], 'issues' => $report['issues']];
        }, 5);
        Response::success($result, 'Storage alert retrieved.');
    }

    /**
     * PUT /api/v1/admin/data/settings  {retention_customer_days, retention_unpaid_days,
     * retention_message_days, retention_activity_days, storage_alert_mb} (any subset)
     * Saving removes nothing: records past a period are listed for review.
     */
    public function updateSettings(): void
    {
        DevSwitches::requireDevMode();
        $body = JsonBodyMiddleware::getBody();
        $options = DatabaseCleanup::OPTIONS + ['storage_alert_mb' => StorageHealth::ALERT_MB_OPTIONS];
        $errors = [];
        $changes = [];

        foreach ($body as $key => $value) {
            if (!isset($options[$key])) {
                $errors[$key] = 'This setting cannot be changed here.';
            } elseif (!is_numeric($value) || !in_array((int) $value, $options[$key], true)) {
                $errors[$key] = 'Choose one of the listed options.';
            } else {
                $changes[$key] = (int) $value;
            }
        }
        if ($errors !== []) {
            Response::error('Please fix the highlighted settings.', 422, 'VALIDATION_ERROR', $errors);
        }
        if ($changes === []) {
            Response::error('Nothing to save.', 422, 'VALIDATION_ERROR');
        }

        $current = fn (): array => (new DatabaseCleanup($this->db))->settings() + ['storage_alert_mb' => (new StorageHealth($this->db))->alertMb()];
        $before = $current();
        foreach ($changes as $key => $value) {
            $this->db->execute(
                "INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                'ss',
                [$key, (string) $value]
            );
        }
        $after = $current();

        AuditService::record($this->adminId(), 'data_retention.update', 'store_settings', 'data_retention', $before, $after);
        Response::success($after, 'Data settings saved.');
    }

    /**
     * POST /api/v1/admin/data/cleanup/verify  {password, as_of, skip?}
     * Step 1: checks the admin's password and emails them a one-time code for exactly the
     * records reviewed as of as_of. `skip` lists the lines the admin unticked (rule names); the
     * code, its email and the clean-up then cover only the lines left ticked.
     */
    public function verify(): void
    {
        DevSwitches::requireDevMode();
        $body = JsonBodyMiddleware::getBody();
        $admin = RequestContext::getUser();
        $session = RequestContext::getSession();
        $adminId = (int) ($admin['id'] ?? 0);

        $user = $this->db->fetchOne("SELECT `email`, `full_name`, `password_hash` FROM `users` WHERE `id` = ?", 'i', [$adminId]);
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        // The same five-tries limit as every other "type your password again" check.
        if ($user === null || !AdminOperationsController::passwordConfirmed($adminId, $password)) {
            AuditService::record($adminId, 'data.cleanup_password_failed', 'database', null);
            Response::error('That password is not right.', 422, 'WRONG_PASSWORD', ['password' => 'That password is not right.']);
        }

        $asOf = $this->reviewTime((string) ($body['as_of'] ?? ''));
        $skip = is_array($body['skip'] ?? null) ? array_values($body['skip']) : [];
        $only = $skip === [] ? null : DatabaseCleanup::selection($skip);
        $snapshot = (new DatabaseCleanup($this->db))->snapshot($asOf, $only);
        $total = array_sum($snapshot['counts']) - ($snapshot['counts']['inactive_customers'] ?? 0);
        if ($total === 0) {
            Response::error($only === null ? 'There is nothing to clean up.' : 'Tick at least one line to clean up.', 422, 'NOTHING_TO_CLEAN');
        }

        RateLimiter::attempt('cleanup_code_sent', (string) $adminId, 5, 3600, 'Too many codes requested. Try again in an hour.');

        $id = bin2hex(random_bytes(16));
        $code = sprintf('%06d', random_int(0, 999999));
        $expiresAt = date('Y-m-d H:i:s', time() + self::CODE_MINUTES * 60);

        // A new code replaces any earlier one.
        $this->db->execute(
            "UPDATE `admin_confirmations` SET `expires_at` = NOW() WHERE `admin_id` = ? AND `action` = ? AND `used_at` IS NULL AND `expires_at` > NOW()",
            'is',
            [$adminId, self::ACTION]
        );
        $this->db->execute(
            "INSERT INTO `admin_confirmations` (`id`, `admin_id`, `session_id`, `action`, `code_hash`, `reviewed_at`, `fingerprint`, `summary`, `expires_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'sisssssss',
            [$id, $adminId, (string) ($session['id'] ?? ''), self::ACTION, self::codeHash($id, $code), $asOf, $snapshot['fingerprint'], json_encode(['counts' => $snapshot['counts'], 'only' => $only]), $expiresAt]
        );

        if (!(new MailService($this->db))->sendAdminConfirmCode((string) $user['email'], (string) $user['full_name'], $code, $id, $this->labelled($snapshot['counts']), $expiresAt)) {
            Response::error('The code could not be emailed. Check the email settings and try again.', 500, 'CODE_NOT_SENT');
        }
        AuditService::record($adminId, 'data.cleanup_code_sent', 'database', null, null, ['reviewed_at' => $asOf, 'counts' => $snapshot['counts']]);

        Response::success([
            'confirmation_id' => $id,
            'sent_to' => self::maskEmail((string) $user['email']),
            'expires_at' => $expiresAt,
            'counts' => $snapshot['counts'],
            'mail_mode' => MailService::mode(),
        ], 'A code was sent to your email.');
    }

    /**
     * POST /api/v1/admin/data/cleanup  {confirmation_id, code}
     * Step 2: with the right code, removes exactly the reviewed records.
     */
    public function runCleanup(): void
    {
        DevSwitches::requireDevMode();
        $body = JsonBodyMiddleware::getBody();
        $admin = RequestContext::getUser();
        $session = RequestContext::getSession();
        $adminId = (int) ($admin['id'] ?? 0);
        $id = (string) ($body['confirmation_id'] ?? '');
        $code = preg_replace('/\D/', '', (string) ($body['code'] ?? '')) ?? '';

        // Decide inside a transaction (the row is locked, so two tries can't race), answer after it.
        $outcome = $this->db->transaction(function (Database $db) use ($id, $code, $adminId, $session): array {
            $row = $db->fetchOne(
                "SELECT *, `expires_at` > NOW() AS `live` FROM `admin_confirmations` WHERE `id` = ? AND `action` = ? FOR UPDATE",
                'ss',
                [$id, self::ACTION]
            );
            if ($row === null || (int) $row['admin_id'] !== $adminId || !hash_equals((string) $row['session_id'], (string) ($session['id'] ?? ''))) {
                return ['error' => 'missing'];
            }
            if ($row['used_at'] !== null || !(int) $row['live'] || (int) $row['attempts'] >= self::CODE_TRIES) {
                return ['error' => 'expired'];
            }
            if (strlen($code) !== 6 || !hash_equals((string) $row['code_hash'], self::codeHash($id, $code))) {
                $attempts = (int) $row['attempts'] + 1;
                $db->execute("UPDATE `admin_confirmations` SET `attempts` = ? WHERE `id` = ?", 'is', [$attempts, $id]);
                return ['error' => 'wrong', 'left' => self::CODE_TRIES - $attempts];
            }
            $db->execute("UPDATE `admin_confirmations` SET `used_at` = NOW() WHERE `id` = ?", 's', [$id]);
            return ['row' => $row];
        });

        if (isset($outcome['error'])) {
            if ($outcome['error'] === 'wrong') {
                AuditService::record($adminId, 'data.cleanup_code_failed', 'database', null);
                $left = (int) $outcome['left'];
                $left > 0
                    ? Response::error("That code is not right. {$left} " . ($left === 1 ? 'try' : 'tries') . ' left.', 422, 'WRONG_CODE', ['code' => 'That code is not right.'])
                    : Response::error('Too many wrong codes. Start again to get a new code.', 410, 'CODE_EXPIRED');
            }
            Response::error('This code has expired or was already used. Start again to get a new code.', 410, 'CODE_EXPIRED');
        }

        $row = $outcome['row'];
        // The lines the admin left ticked when the code was sent (codes sent before this choice
        // existed stored just the counts, and covered everything).
        $stored = json_decode((string) ($row['summary'] ?? ''), true);
        $only = is_array($stored) && array_key_exists('only', $stored) && is_array($stored['only']) ? array_values(array_filter($stored['only'], 'is_string')) : null;
        try {
            $removed = (new DatabaseCleanup($this->db))->execute((string) $row['reviewed_at'], (string) $row['fingerprint'], (string) ($admin['full_name'] ?? ''), $only);
        } catch (DomainException $e) {
            Response::error($e->getMessage(), 409, 'CLEANUP_CHANGED');
        }

        AuditService::record($adminId, 'data.cleanup', 'database', null, null, ['reviewed_at' => $row['reviewed_at'], 'removed' => $removed]);
        (new MailService($this->db))->sendCleanupDoneToAdmins((string) ($admin['full_name'] ?? 'An admin'), RequestContext::clientIp(), $this->labelled($removed));

        Response::success(['removed' => $removed], 'Clean-up finished.');
    }

    // -------------------------------------------------------------------------

    /**
     * The time a reviewed list was computed for: not in the future and at most 30 minutes old.
     */
    private function reviewTime(string $value): string
    {
        $time = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
        if ($time === false || $time->format('Y-m-d H:i:s') !== $value) {
            Response::error('Refresh the page to review the data again.', 422, 'REVIEW_REQUIRED');
        }
        $age = time() - $time->getTimestamp();
        if ($age < -60 || $age > self::REVIEW_MINUTES * 60) {
            Response::error('This list is more than 30 minutes old. Refresh the page and review it again.', 422, 'REVIEW_EXPIRED');
        }
        return $value;
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int> label => count, without empty rules
     */
    private function labelled(array $counts): array
    {
        $out = [];
        foreach ($counts as $rule => $count) {
            if ($count > 0) {
                $out[DatabaseCleanup::LABELS[$rule] ?? $rule] = $count;
            }
        }
        return $out;
    }

    private static function codeHash(string $id, string $code): string
    {
        return hash('sha256', $id . '|' . $code);
    }

    private static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($name, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($name) - 1))) . '@' . $domain;
    }

    private function adminId(): ?int
    {
        $admin = RequestContext::getUser();
        return $admin ? (int) $admin['id'] : null;
    }
}
