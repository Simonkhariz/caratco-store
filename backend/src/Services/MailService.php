<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Config\Shop;
use App\Database\Database;
use App\Support\DevSwitches;
use App\Support\Response;
use Throwable;

/**
 * Store email, outbox first.
 *
 * Every email is a row in `email_outbox` whose payload holds everything needed to render it
 * (MailTemplates). Transactional emails get one quick delivery attempt, but never while the
 * shopper or admin waits: during a web request it runs after the response has been handed over
 * (Response::finishForClient); command-line scripts deliver at once. Anything that fails, and
 * every newsletter, is delivered by backend/scripts/process_outbox.php with retries and backoff.
 * A dedup key is unique (migration 019), so the same email is never queued twice.
 *
 * Status is honest: 'sent' only when the SMTP server accepted the message or, in log mode, when it
 * was written to storage/logs/mail.log (transport = 'log', shown as "logged only" in the admin).
 *
 * Sign-in links are the exception: the raw token is never stored, so they are delivered
 * immediately and the outbox row keeps only a fingerprint (they cannot be re-sent).
 */
class MailService
{
    public const MAX_ATTEMPTS = 5;
    /** Minutes to wait after attempt N fails (index = attempts so far). */
    private const BACKOFF_MINUTES = [1 => 1, 2 => 5, 3 => 15, 4 => 60];
    /** How long a worker owns a row while delivering it. */
    private const LEASE_MINUTES = 10;
    /** Automatic retries after requests: at most one run per interval, this many emails, this long. */
    private const SWEEP_INTERVAL_SECONDS = 60;
    private const SWEEP_BATCH = 5;
    private const SWEEP_MAX_SECONDS = 20;
    private const SWEEP_KEY = 'outbox_last_sweep';

    private static ?MailService $instance = null;
    /** @var list<int> Outbox rows to deliver once this web request's response has gone out. */
    private static array $afterResponse = [];
    private Database $db;
    private string $logPath;
    private ?MailTemplates $templates = null;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        // MAIL_LOG_PATH (relative to backend/) lets tests keep their own log.
        $this->logPath = $base . '/' . ltrim((string) (Env::get('MAIL_LOG_PATH', 'storage/logs/mail.log') ?: 'storage/logs/mail.log'), '/');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Templates are built once per request (they read the store settings). */
    private function templates(): MailTemplates
    {
        return $this->templates ??= new MailTemplates($this->db);
    }

    public static function mode(): string
    {
        return ResendTransport::isConfigured() ? 'resend' : (SmtpTransport::isConfigured() ? 'smtp' : 'log_only');
    }

    /**
     * MAIL_REPLY_TO (optional): where a customer's "Reply" goes, for example the owner's Gmail,
     * when the sender address has no mailbox. Null when unset or not a valid address.
     */
    public static function replyToAddress(): ?string
    {
        $address = trim((string) Env::get('MAIL_REPLY_TO', ''));
        return $address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }

    // =========================================================================
    // Order emails. Payment, shipment, cancellation and refund emails are queued from beforeCommit
    // callbacks (in the same transaction as the change); order-received from afterCommit, so a
    // checkout does not hold its stock-row locks while the email is rendered.
    // =========================================================================

    public function sendOrderReceived(array $order): bool
    {
        $payload = $this->orderPayload((string) ($order['order_number'] ?? ''));
        if ($payload === null) {
            return false;
        }
        return $this->queue($payload['recipient'], 'order_received', $payload['data'], 'order_received:' . $payload['data']['order_number']);
    }

    /**
     * Receipt. Only for orders whose payment is verified (guard kept from the original design).
     */
    public function sendPaymentVerified(array $order, array $paymentDetails = []): bool
    {
        $payload = $this->orderPayload((string) ($order['order_number'] ?? ''));
        if ($payload === null) {
            return false;
        }
        $data = $payload['data'];
        if (!in_array($data['payment_status'], ['successful', 'partially_refunded'], true)) {
            error_log("[MailService] Refused payment_verified email for {$data['order_number']}: payment is not verified.");
            return false;
        }

        $payment = $this->db->fetchOne(
            "SELECT `provider`, `provider_reference`, `amount`, `amount_received`, `payment_method`, `verified_at`
             FROM `payments` WHERE `order_id` = ? AND `status` IN ('successful', 'partially_refunded')
             ORDER BY `verified_at` ASC, `id` ASC LIMIT 1",
            'i',
            [$data['order_id']]
        );
        $provider = (string) ($payment['provider'] ?? '');
        $label = ['kora' => 'Kora', 'monnify' => 'Monnify', 'whatsapp' => 'Bank transfer', 'manual' => 'Bank transfer'][$provider] ?? ucfirst($provider);
        $method = trim(str_replace('_', ' ', (string) ($payment['payment_method'] ?? '')));

        $data['payment_reference'] = (string) ($payment['provider_reference'] ?? $paymentDetails['reference'] ?? '');
        $data['payment_label'] = trim($label . ($method !== '' && strcasecmp($method, $label) !== 0 ? ' · ' . $method : ''));
        $data['amount_paid'] = (float) ($payment['amount_received'] ?? $payment['amount'] ?? $data['grand_total']);
        $data['verified_at'] = $payment['verified_at'] ?? null;

        return $this->queue($payload['recipient'], 'payment_verified', $data, 'payment_verified:' . $data['order_number']);
    }

    /**
     * Alert the store's admins that a customer marked a WhatsApp transfer as paid.
     * One email per order and admin, however often the customer taps the button.
     * Goes to adminRecipients(): "Send admin alerts to", else every active admin account.
     *
     * @return int Number of emails queued
     */
    public function sendPaymentClaimedToAdmins(string $orderNumber, string $claimedAt): int
    {
        $payload = $this->orderPayload($orderNumber);
        if ($payload === null) {
            return 0;
        }
        $data = $payload['data'];
        $data['claimed_at'] = $claimedAt;


        $queued = 0;
        foreach ($this->adminRecipients() as $email) {
            // Queued for the outbox worker (within a minute), not sent in the customer's request:
            // one email per admin would otherwise hold that request's worker per admin.
            if ($this->queue($email, 'payment_claimed', $data, "payment_claimed:{$data['order_number']}:{$email}", false)) {
                $queued++;
            }
        }
        return $queued;
    }

    /**
     * Tell the admins a new order was placed (unpaid until a payment is confirmed). One email
     * per order and admin.
     *
     * @return int Number of emails queued
     */
    public function sendNewOrderToAdmins(string $orderNumber): int
    {
        return $this->queueForAdmins($orderNumber, 'admin_new_order', []);
    }

    /**
     * Tell the admins a payment was confirmed (gateway, webhook, reconciliation or a manual
     * confirmation). Only for a verified payment; one email per order and admin.
     *
     * @return int Number of emails queued
     */
    public function sendPaymentReceivedToAdmins(string $orderNumber, string $methodLabel = '', string $reference = ''): int
    {
        $payload = $this->orderPayload($orderNumber);
        if ($payload === null || !in_array($payload['data']['payment_status'], ['successful', 'partially_refunded'], true)) {
            return 0;
        }
        return $this->queueForAdmins($orderNumber, 'admin_payment_received', ['payment_label' => $methodLabel, 'payment_reference' => $reference], $payload);
    }

    /**
     * Customer email for an order moving forward: 'processing' (being prepared) or 'delivered'.
     * Shipping, cancellation and refunds have their own emails. One email per order and stage.
     */
    public function sendOrderStatusUpdate(array $order, string $status): bool
    {
        if (!in_array($status, ['processing', 'delivered'], true)) {
            return false;
        }
        $payload = $this->orderPayload((string) ($order['order_number'] ?? ''));
        if ($payload === null) {
            return false;
        }
        $data = $payload['data'];
        $data['stage'] = $status;
        return $this->queue($payload['recipient'], 'order_status_update', $data, "order_status_update:{$data['order_number']}:{$status}");
    }

    /**
     * @param array<string, mixed> $extra
     * @param array{recipient: string, data: array<string, mixed>}|null $payload
     */
    private function queueForAdmins(string $orderNumber, string $template, array $extra, ?array $payload = null): int
    {
        $payload ??= $this->orderPayload($orderNumber);
        if ($payload === null) {
            return 0;
        }
        $data = $extra + $payload['data'];
        $queued = 0;
        foreach ($this->adminRecipients() as $email) {
            // Admin alerts go to the outbox worker (sent within a minute by process_outbox.php or the
            // after-response sweep), never delivered in the customer's own request: with a slow or
            // stuck mail server that would hold the request's worker once per admin.
            if ($email !== '' && $this->queue($email, $template, $data, "{$template}:{$data['order_number']}:{$email}", false)) {
                $queued++;
            }
        }
        return $queued;
    }

    /**
     * Tell the admins the database is getting too big or the site slow (a StorageHealth
     * report). Sent at most once a week per problem level.
     *
     * @return int Number of emails queued
     */
    public function sendStorageAlertToAdmins(array $report): int
    {

        $data = ['level' => (string) $report['level'], 'issues' => $report['issues']];
        $week = date('o-\WW');
        $queued = 0;
        foreach ($this->adminRecipients() as $email) {
            if ($this->queue($email, 'storage_alert', $data, "storage_alert:{$data['level']}:{$week}:{$email}")) {
                $queued++;
            }
        }
        return $queued;
    }

    /** Store setting (Admin → Settings → "Send admin alerts to"): comma-separated addresses. */
    public const ALERT_EMAIL_SETTING = 'admin_alert_email';
    public const MAX_ALERT_EMAILS = 10;

    /**
     * Where admin alerts go: the addresses in "Send admin alerts to" when that setting is filled
     * in; otherwise every active admin account; otherwise (no admins) the store contact email.
     *
     * @return list<string>
     */
    public function adminRecipients(): array
    {
        $setting = $this->db->fetchOne("SELECT `value` FROM `store_settings` WHERE `key_name` = ?", 's', [self::ALERT_EMAIL_SETTING]);
        $chosen = self::parseAlertEmails((string) ($setting['value'] ?? ''));
        if ($chosen !== null && $chosen !== []) {
            return $chosen;
        }
        return $this->adminAccountEmails();
    }

    /**
     * Every active admin account (the store contact email when there are none).
     *
     * @return list<string>
     */
    private function adminAccountEmails(): array
    {
        $recipients = array_column($this->db->fetchAll(
            "SELECT `email` FROM `users` WHERE `role` IN ('admin', 'super_admin') AND `status` = 'active'"
        ), 'email');
        if ($recipients === []) {
            $contact = $this->db->fetchOne("SELECT `value` FROM `store_settings` WHERE `key_name` = 'store_contact_email'");
            $recipients = [trim((string) ($contact['value'] ?? ''))];
        }
        return array_values(array_unique(array_map('strtolower', $recipients)));
    }

    /**
     * "a@x.com, b@y.com" (commas, semicolons or spaces between) as a tidy list: lower case,
     * no repeats. [] when empty, null when an address is not valid or there are too many.
     *
     * @return list<string>|null
     */
    public static function parseAlertEmails(string $value): ?array
    {
        $parts = preg_split('/[\s,;]+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $emails = [];
        foreach ($parts as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return null;
            }
            $emails[$email] = true;
        }
        if (count($emails) > self::MAX_ALERT_EMAILS) {
            return null;
        }
        return array_keys($emails);
    }

    public function sendShipmentTracking(array $order, array $shipment): bool
    {
        $payload = $this->orderPayload((string) ($order['order_number'] ?? ''));
        if ($payload === null) {
            return false;
        }
        $data = $payload['data'];
        $data['carrier'] = (string) ($shipment['carrier'] ?? '');
        $data['tracking_number'] = (string) ($shipment['tracking_number'] ?? '');
        $data['tracking_url'] = (string) ($shipment['tracking_url'] ?? '');

        return $this->queue(
            $payload['recipient'],
            'shipment_tracking',
            $data,
            'shipment_tracking:' . $data['order_number'] . ':' . $data['tracking_number']
        );
    }

    /**
     * @param string $type 'cancellation' or 'refund'
     * @param array{reason?: string, amount?: float|string, refund_id?: int, reference?: string|null} $extra
     */
    public function sendCancellationOrRefund(array $order, string $type, array $extra = []): bool
    {
        $payload = $this->orderPayload((string) ($order['order_number'] ?? ''));
        if ($payload === null) {
            return false;
        }
        $data = $payload['data'];
        $data['reason'] = trim((string) ($extra['reason'] ?? ''));

        if ($type === 'refund') {
            $data['amount'] = (float) ($extra['amount'] ?? 0);
            $data['refund_reference'] = (string) ($extra['reference'] ?? '');
            $key = 'refund_processed:' . $data['order_number'] . ':' . ($extra['refund_id'] ?? md5(json_encode($extra)));
            return $this->queue($payload['recipient'], 'refund_processed', $data, $key);
        }

        $paid = $this->db->fetchOne(
            "SELECT COALESCE(SUM(COALESCE(`amount_received`, `amount`)), 0) AS paid FROM `payments`
             WHERE `order_id` = ? AND `status` IN ('successful', 'partially_refunded')",
            'i',
            [$data['order_id']]
        );
        $data['was_paid'] = (float) ($paid['paid'] ?? 0) > 0;
        $data['amount_paid'] = (float) ($paid['paid'] ?? 0);

        return $this->queue($payload['recipient'], 'order_cancelled', $data, 'order_cancelled:' . $data['order_number']);
    }

    // =========================================================================
    // Sign-in links (delivered immediately; the token is never stored)
    // =========================================================================

    public function sendAccountVerification(string $email, string $rawToken, string $type = 'magic_link'): bool
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $link = Env::frontendUrl() . '/auth/verify?token=' . rawurlencode($rawToken);
        $templates = $this->templates();
        $rendered = $templates->render('magic_link', ['link' => $link, 'ttl_minutes' => 15]);
        $fingerprint = substr(hash('sha256', $rawToken), 0, 12);

        [$ok, $transport, $error] = $this->transmit($email, $rendered, $templates->storeName(), 'magic_link');

        try {
            $this->db->insert(
                "INSERT INTO `email_outbox` (`recipient_email`, `subject`, `template`, `dedup_key`, `payload`, `status`, `transport`, `attempt_count`, `last_error`, `sent_at`)
                 VALUES (?, ?, 'magic_link', ?, ?, ?, ?, 1, ?, ?)",
                'ssssssss',
                [
                    $email,
                    $rendered['subject'],
                    'magic_link:' . $fingerprint,
                    json_encode(['v' => MailTemplates::VERSION, 'type' => $type, 'token_fingerprint' => $fingerprint]),
                    $ok ? 'sent' : 'failed',
                    $transport,
                    $error,
                    $ok ? date('Y-m-d H:i:s') : null,
                ]
            );
        } catch (Throwable $e) {
            error_log('[MailService] Could not record sign-in email: ' . $e->getMessage());
        }

        return $ok;
    }

    /**
     * The one-time code an admin types to confirm a data clean-up. Delivered immediately like
     * a sign-in link; the code itself is never stored (the outbox row only records the send).
     *
     * @param array<string, int> $summary label => number of records
     */
    public function sendAdminConfirmCode(string $email, string $name, string $code, string $confirmationId, array $summary, string $expiresAt): bool
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $templates = $this->templates();
        $rendered = $templates->render('admin_confirm_code', ['name' => $name, 'code' => $code, 'summary' => $summary, 'expires_at' => $expiresAt]);
        [$ok, $transport, $error] = $this->transmit($email, $rendered, $templates->storeName(), 'admin_confirm_code');

        try {
            $this->db->insert(
                "INSERT INTO `email_outbox` (`recipient_email`, `subject`, `template`, `dedup_key`, `payload`, `status`, `transport`, `attempt_count`, `last_error`, `sent_at`)
                 VALUES (?, ?, 'admin_confirm_code', ?, ?, ?, ?, 1, ?, ?)",
                'ssssssss',
                [
                    $email,
                    $rendered['subject'],
                    'admin_confirm:' . $confirmationId,
                    json_encode(['v' => MailTemplates::VERSION, 'summary' => $summary]),
                    $ok ? 'sent' : 'failed',
                    $transport,
                    $error,
                    $ok ? date('Y-m-d H:i:s') : null,
                ]
            );
        } catch (Throwable $e) {
            error_log('[MailService] Could not record confirmation email: ' . $e->getMessage());
        }

        return $ok;
    }

    /**
     * Tell every admin that a data clean-up ran (who, when, from where, what was removed).
     *
     * @param array<string, int> $removed label => number of records
     * @return int Number of emails queued
     */
    public function sendCleanupDoneToAdmins(string $adminName, string $ip, array $removed): int
    {
        $data = ['admin_name' => $adminName, 'ip' => $ip, 'at' => date('Y-m-d H:i:s'), 'removed' => $removed];
        $queued = 0;
        // A security notice: every admin account hears about it even when alerts are sent
        // elsewhere, so nobody can hide a clean-up by changing "Send admin alerts to".
        $recipients = array_values(array_unique(array_merge($this->adminAccountEmails(), $this->adminRecipients())));
        foreach ($recipients as $email) {
            if ($this->queue($email, 'data_cleanup_done', $data, null)) {
                $queued++;
            }
        }
        return $queued;
    }

    // =========================================================================
    // Newsletter
    // =========================================================================

    /**
     * Queue one newsletter email (delivered by the outbox worker, never inline).
     */
    /**
     * $dedupKey (optional): the same key is never queued twice, so sending the same newsletter
     * again (a retry after a timeout, a double click) does not email anyone a second time.
     */
    /**
     * @param bool $asCustomer The address is on the list because it has a customer account
     *                         (not a newsletter sign-up), which the footer says.
     */
    public function queueBroadcast(string $email, string $subject, string $content, string $unsubscribeUrl, ?string $dedupKey = null, bool $asCustomer = false): void
    {
        $this->queue($email, 'marketing_broadcast', [
            'subject' => $subject,
            'content' => $content,
            'unsubscribe_url' => $unsubscribeUrl,
            'reason' => $asCustomer ? 'customer' : 'subscriber',
        ], $dedupKey, false);
    }

    // =========================================================================
    // Outbox
    // =========================================================================

    /**
     * Insert an outbox row (skipping exact duplicates) and optionally make one quick delivery
     * attempt: after the response during a web request, at once in a command-line script.
     * Returns false only when the email could not be queued at all.
     */
    public function queue(string $recipient, string $template, array $data, ?string $dedupKey, bool $deliverNow = true): bool
    {
        $recipient = strtolower(trim($recipient));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            if ($dedupKey !== null) {
                $existing = $this->db->fetchOne("SELECT `id` FROM `email_outbox` WHERE `dedup_key` = ? LIMIT 1", 's', [$dedupKey]);
                if ($existing !== null) {
                    return true;
                }
            }

            $data['v'] = MailTemplates::VERSION;
            $subject = $this->templates()->render($template, $data)['subject'];

            $id = (int) $this->db->insert(
                "INSERT INTO `email_outbox` (`recipient_email`, `subject`, `template`, `dedup_key`, `payload`, `status`, `attempt_count`)
                 VALUES (?, ?, ?, ?, ?, 'pending', 0)",
                'sssss',
                [$recipient, mb_substr($subject, 0, 255), $template, $dedupKey, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
            );
        } catch (Throwable $e) {
            // Another request queued the same email a moment earlier: it is already on its way.
            if ($dedupKey !== null && (int) $e->getCode() === 1062) {
                return true;
            }
            // Inside a caller's transaction a database error (a deadlock rolls the whole transaction
            // back) must reach the caller, or it would carry on and commit a half-done change.
            if ($e instanceof \mysqli_sql_exception && $this->db->inTransaction()) {
                throw $e;
            }
            error_log("[MailService] Could not queue {$template} for {$recipient}: " . $e->getMessage());
            return false;
        }

        if ($deliverNow && DevSwitches::enabled(DevSwitches::MAIL_AFTER_RESPONSE)) {
            if (PHP_SAPI !== 'cli') {
                $this->deliverAfterResponse($id);
            } elseif (!$this->db->inTransaction()) {
                $this->deliver($id);
            } else {
                $this->db->afterCommit(fn () => $this->deliver($id));
            }
        }
        return true;
    }

    /**
     * Deliver $id once the response has been handed to the client, so a slow or unreachable mail
     * server never delays a checkout, a payment confirmation or an admin action. Whatever is still
     * undelivered (also after a crash) is picked up by process_outbox.php.
     */
    /**
     * Retry due emails without a scheduler: after an API request has answered, the first request
     * each minute (across every PHP process and server, decided by one atomic row in
     * store_settings) sends up to SWEEP_BATCH due emails, for at most SWEEP_MAX_SECONDS. Each email
     * is still claimed before it is sent, so this, process_outbox.php and other servers never send
     * the same email twice. MAIL_AUTO_RETRY=false turns it off (process_outbox.php still works).
     * Called from index.php at shutdown; a busy shop gets at most one small batch a minute.
     */
    public static function retryDueAfterResponse(): void
    {
        if (!DevSwitches::enabled(DevSwitches::MAIL_AUTO_RETRY)) {
            return;
        }
        try {
            $db = Database::getInstance();
            // A plain read first (it takes no lock), so busy traffic doesn't queue on the row below.
            $recent = $db->fetchOne(
                "SELECT CAST(`value` AS DATETIME) > NOW() - INTERVAL " . self::SWEEP_INTERVAL_SECONDS . " SECOND AS recent
                 FROM `store_settings` WHERE `key_name` = ?",
                's',
                [self::SWEEP_KEY]
            );
            if ((int) ($recent['recent'] ?? 0) === 1) {
                return;
            }
            // 1 = first run ever, 2 = the last run was over a minute ago; 0 = someone else ran it.
            $won = $db->executeUpdate(
                "INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'))
                 ON DUPLICATE KEY UPDATE `value` = IF(COALESCE(CAST(`value` AS DATETIME) <= NOW() - INTERVAL " . self::SWEEP_INTERVAL_SECONDS . " SECOND, 1),
                                                      VALUES(`value`), `value`)",
                's',
                [self::SWEEP_KEY]
            );
            if ($won !== 1 && $won !== 2) {
                return;
            }
            $due = $db->fetchOne(
                "SELECT 1 AS d FROM `email_outbox` WHERE `status` = 'pending' AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= NOW()) LIMIT 1"
            );
            if ($due === null) {
                return;
            }
            Response::finishForClient();
            self::getInstance()->processQueue(self::SWEEP_BATCH, self::SWEEP_MAX_SECONDS);
        } catch (Throwable $e) {
            error_log('[MailService] Automatic retry run failed: ' . $e->getMessage());
        }
    }

    private function deliverAfterResponse(int $id): void
    {
        if (self::$afterResponse === []) {
            register_shutdown_function(static function (): void {
                Response::finishForClient();
                $ids = self::$afterResponse;
                self::$afterResponse = [];
                foreach ($ids as $queuedId) {
                    try {
                        self::getInstance()->deliver($queuedId);
                    } catch (Throwable $e) {
                        error_log("[MailService] Delivery after response failed for outbox #{$queuedId}: " . $e->getMessage());
                    }
                }
            });
        }
        self::$afterResponse[] = $id;
    }

    /**
     * Deliver one outbox row if it is due and nobody else is delivering it.
     *
     * @return array{id: int, status: string, error: string|null, rate_limited?: bool}|null null when the row was not due / claimed
     */
    public function deliver(int $id): ?array
    {
        // Claim the row: the conditional update doubles as a lease so two workers never send it twice.
        $claimed = $this->db->executeUpdate(
            "UPDATE `email_outbox`
             SET `next_attempt_at` = DATE_ADD(NOW(), INTERVAL " . self::LEASE_MINUTES . " MINUTE)
             WHERE `id` = ? AND `status` = 'pending' AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= NOW())",
            'i',
            [$id]
        );
        if ($claimed !== 1) {
            return null;
        }

        $row = $this->db->fetchOne("SELECT * FROM `email_outbox` WHERE `id` = ?", 'i', [$id]);
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        $template = (string) $row['template'];

        if (!is_array($payload) || (int) ($payload['v'] ?? 0) !== MailTemplates::VERSION || !in_array($template, MailTemplates::RENDERABLE, true)) {
            return $this->finish($id, 'failed', null, 'This email was queued by an older version of the store and cannot be rendered.', false);
        }

        if ($template === 'marketing_broadcast') {
            $sub = $this->db->fetchOne("SELECT `status` FROM `newsletter_subscribers` WHERE `email` = ?", 's', [$row['recipient_email']]);
            if (($sub['status'] ?? null) !== 'active') {
                return $this->finish($id, 'failed', null, 'Not sent: the recipient unsubscribed before delivery.', false);
            }
        }

        try {
            $templates = $this->templates();
            $rendered = $templates->render($template, $payload);
        } catch (Throwable $e) {
            error_log("[MailService] Render failed for outbox #{$id}: " . $e->getMessage());
            return $this->finish($id, 'failed', null, 'The email could not be rendered.', false);
        }

        // One idempotency key per outbox row: a provider that supports it (Resend) treats a retry
        // after a lost answer as the same email, not a second one.
        [$ok, $transport, $error, $permanent, $rateLimited] = $this->transmit((string) $row['recipient_email'], $rendered, $templates->storeName(), $template, $payload, "shopco-outbox-{$id}");

        if ($ok) {
            return $this->finish($id, 'sent', $transport, null, true);
        }

        // Sending too fast is not the email's fault: try again in a minute without using up one of
        // its attempts (otherwise a large newsletter sent too quickly would end up marked failed).
        if ($rateLimited) {
            $this->db->executeUpdate(
                "UPDATE `email_outbox`
                 SET `last_error` = ?, `transport` = ?, `next_attempt_at` = DATE_ADD(NOW(), INTERVAL 1 MINUTE)
                 WHERE `id` = ?",
                'ssi',
                [$error, $transport, $id]
            );
            return ['id' => $id, 'status' => 'pending', 'error' => $error, 'rate_limited' => true];
        }

        $attempts = (int) $row['attempt_count'] + 1;
        // The provider refused the email itself (not a passing problem): retrying cannot help.
        if ($attempts >= self::MAX_ATTEMPTS || $permanent) {
            return $this->finish($id, 'failed', $transport, $error, true);
        }

        $this->db->executeUpdate(
            "UPDATE `email_outbox`
             SET `attempt_count` = ?, `last_error` = ?, `transport` = ?,
                 `next_attempt_at` = DATE_ADD(NOW(), INTERVAL ? MINUTE)
             WHERE `id` = ?",
            'issii',
            [$attempts, $error, $transport, self::BACKOFF_MINUTES[$attempts] ?? 60, $id]
        );
        return ['id' => $id, 'status' => 'pending', 'error' => $error];
    }

    /**
     * Deliver due rows (the oldest batch). Used by scripts/process_outbox.php, the admin "Send now"
     * and the automatic retries after requests. $maxSeconds stops starting new sends once the
     * batch has run that long (0 = no limit). $perSecond caps the sending pace (0 = as fast as the
     * transport allows), so a large backlog does not take the database and CPU away from shoppers.
     *
     * The batch is worked through in random order: workers running side by side then rarely
     * reach for the same email at the same moment (in id order they all queue on the same row).
     *
     * @return array{processed: int, sent: int, failed: int, retrying: int, rate_limited: int} rate_limited is 1 when the provider asked to slow down
     */
    public function processQueue(int $limit = 50, int $maxSeconds = 0, float $perSecond = 0): array
    {
        $startedAt = microtime(true);
        $ids = array_column($this->db->fetchAll(
            "SELECT `id` FROM `email_outbox`
             WHERE `status` = 'pending' AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= NOW())
             ORDER BY `id` ASC LIMIT ?",
            'i',
            [max(1, min(500, $limit))]
        ), 'id');
        shuffle($ids);

        $summary = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'retrying' => 0, 'rate_limited' => 0];
        foreach ($ids as $id) {
            if ($maxSeconds > 0 && microtime(true) - $startedAt >= $maxSeconds) {
                break;
            }
            if ($perSecond > 0 && $summary['processed'] > 0) {
                $wait = $startedAt + $summary['processed'] / $perSecond - microtime(true);
                if ($wait > 0) {
                    usleep((int) ($wait * 1e6));
                }
                if ($maxSeconds > 0 && microtime(true) - $startedAt >= $maxSeconds) {
                    break;
                }
            }
            $result = $this->deliver((int) $id);
            if ($result === null) {
                continue;
            }
            if (!empty($result['rate_limited'])) {
                // The provider asked us to slow down: stop this batch; the rest waits for the next run.
                $summary['rate_limited'] = 1;
                break;
            }
            $summary['processed']++;
            $key = $result['status'] === 'sent' ? 'sent' : ($result['status'] === 'failed' ? 'failed' : 'retrying');
            $summary[$key]++;
        }
        return $summary;
    }

    /**
     * Admin "Retry" / "Send again": re-render the stored payload and deliver the same row.
     *
     * @return array{ok: bool, message: string}
     */
    public function retryOutboxItem(int $outboxId): array
    {
        $row = $this->db->fetchOne("SELECT `id`, `template`, `payload`, `attempt_count` FROM `email_outbox` WHERE `id` = ?", 'i', [$outboxId]);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Email not found.'];
        }
        if ($row['template'] === 'magic_link' || $row['template'] === 'account_verification') {
            return ['ok' => false, 'message' => 'Sign-in links cannot be re-sent. Ask the customer to request a new link.'];
        }
        if ($row['template'] === 'admin_confirm_code') {
            return ['ok' => false, 'message' => 'Confirmation codes cannot be re-sent. Start the clean-up again to get a new code.'];
        }
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload) || (int) ($payload['v'] ?? 0) !== MailTemplates::VERSION || !in_array($row['template'], MailTemplates::RENDERABLE, true)) {
            return ['ok' => false, 'message' => 'This email was created by an older version of the store and cannot be re-sent.'];
        }

        // Give it one more attempt within the limit.
        $this->db->executeUpdate(
            "UPDATE `email_outbox` SET `status` = 'pending', `next_attempt_at` = NULL, `attempt_count` = LEAST(`attempt_count`, ?) WHERE `id` = ?",
            'ii',
            [self::MAX_ATTEMPTS - 1, $outboxId]
        );
        $result = $this->deliver($outboxId);

        return match ($result['status'] ?? null) {
            'sent' => ['ok' => true, 'message' => self::mode() !== 'log_only' ? 'Email sent.' : 'Email written to the mail log (no email service is configured).'],
            'pending' => ['ok' => false, 'message' => 'Sending failed again; it will be retried automatically. ' . ($result['error'] ?? '')],
            default => ['ok' => false, 'message' => $result['error'] ?? 'The email could not be sent.'],
        };
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Send through the configured transport: Resend, SMTP, or the mail log.
     *
     * @param array{subject: string, html: string, text: string} $rendered
     * @param string|null $idempotencyKey stable for one outbox row, so a retry is never a second email
     * @return array{0: bool, 1: string, 2: string|null, 3: bool, 4: bool} ok, transport, error, permanent failure, rate limited
     */
    private function transmit(string $to, array $rendered, string $storeName, string $template, array $payload = [], ?string $idempotencyKey = null): array
    {
        $headers = [];
        if ($template === 'marketing_broadcast' && !empty($payload['unsubscribe_url'])) {
            $headers['List-Unsubscribe'] = '<' . $payload['unsubscribe_url'] . '>';
        }

        if (ResendTransport::isConfigured()) {
            try {
                (new ResendTransport())->send($to, $rendered['subject'], $rendered['html'], $rendered['text'], $storeName, $headers, $idempotencyKey);
                return [true, 'resend', null, false, false];
            } catch (ResendError $e) {
                error_log("[MailService Resend] {$template} to {$to}: " . $e->getMessage());
                return [false, 'resend', mb_substr($e->getMessage(), 0, 500), $e->permanent, $e->rateLimited];
            } catch (Throwable $e) {
                error_log("[MailService Resend] {$template} to {$to}: " . $e->getMessage());
                return [false, 'resend', mb_substr($e->getMessage(), 0, 500), false, false];
            }
        }

        if (SmtpTransport::isConfigured()) {
            try {
                (new SmtpTransport())->send($to, $rendered['subject'], $rendered['html'], $rendered['text'], $storeName, $headers);
                return [true, 'smtp', null, false, false];
            } catch (Throwable $e) {
                error_log("[MailService SMTP] {$template} to {$to}: " . $e->getMessage());
                return [false, 'smtp', mb_substr($e->getMessage(), 0, 500), false, false];
            }
        }

        try {
            $this->writeToMailLog($to, $rendered, $template);
            return [true, 'log', null, false, false];
        } catch (Throwable $e) {
            return [false, 'log', 'Could not write to storage/logs/mail.log: ' . $e->getMessage(), false, false];
        }
    }

    /**
     * @return array{id: int, status: string, error: string|null}
     */
    private function finish(int $id, string $status, ?string $transport, ?string $error, bool $countAttempt): array
    {
        $this->db->executeUpdate(
            "UPDATE `email_outbox`
             SET `status` = ?, `transport` = COALESCE(?, `transport`), `last_error` = ?,
                 `attempt_count` = `attempt_count` + ?, `next_attempt_at` = NULL,
                 `sent_at` = IF(? = 'sent', NOW(), `sent_at`)
             WHERE `id` = ?",
            'sssisi',
            [$status, $transport, $error, $countAttempt ? 1 : 0, $status, $id]
        );
        return ['id' => $id, 'status' => $status, 'error' => $error];
    }

    /** A value from an order line's saved options as text ('' when it is missing or not text). */
    private static function optionText(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
    }

    /**
     * Everything the order templates need, read from the committed order.
     *
     * @return array{recipient: string, data: array<string, mixed>}|null
     */
    private function orderPayload(string $orderNumber): ?array
    {
        if ($orderNumber === '') {
            return null;
        }
        $order = $this->db->fetchOne(
            "SELECT o.*, u.email AS account_email, u.full_name AS account_name
             FROM `orders` o LEFT JOIN `users` u ON u.id = o.user_id
             WHERE o.order_number = ?",
            's',
            [$orderNumber]
        );
        if ($order === null) {
            return null;
        }

        $recipient = trim((string) ($order['guest_email'] ?: $order['account_email'] ?: ''));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $address = json_decode((string) ($order['shipping_address_snapshot'] ?? ''), true) ?: [];
        $zone = json_decode((string) ($order['shipping_zone_snapshot'] ?? ''), true) ?: [];

        $items = [];
        foreach ($this->db->fetchAll(
            "SELECT `item_name`, `variant_options`, `quantity`, `line_total` FROM `order_items` WHERE `order_id` = ? ORDER BY `id`",
            'i',
            [(int) $order['id']]
        ) as $item) {
            $opts = json_decode((string) ($item['variant_options'] ?? ''), true) ?: [];
            $items[] = [
                'name' => (string) $item['item_name'],
                // Only the options the product really uses: the "none" words are never shown.
                'options' => Shop::optionsText(self::optionText($opts['size'] ?? null), self::optionText($opts['color'] ?? $opts['color_name'] ?? null)),
                // The warranty the item was sold with (kept on the order line when the shop offers warranties).
                'warranty' => self::optionText($opts['warranty'] ?? null),
                'quantity' => (int) $item['quantity'],
                'line_total' => (float) $item['line_total'],
            ];
        }

        return [
            'recipient' => $recipient,
            'data' => [
                'order_id' => (int) $order['id'],
                'order_number' => (string) $order['order_number'],
                'order_status' => (string) $order['order_status'],
                'payment_status' => (string) $order['payment_status'],
                'customer_name' => (string) ($address['full_name'] ?? $order['account_name'] ?? ''),
                'currency' => (string) ($order['currency'] ?: 'NGN'),
                'subtotal' => (float) $order['subtotal'],
                'discount_amount' => (float) $order['discount_amount'],
                'promo_code' => $order['applied_promo_code'] ?? null,
                'shipping_fee' => (float) $order['shipping_fee'],
                'grand_total' => (float) $order['grand_total'],
                'zone_name' => $zone['name'] ?? null,
                'estimated_days' => $zone['estimated_days'] ?? null,
                'expires_at' => $order['expires_at'] ?? null,
                'items' => $items,
                'address' => [
                    'full_name' => $address['full_name'] ?? '',
                    'phone' => $address['phone'] ?? '',
                    'address_line1' => $address['address_line1'] ?? '',
                    'address_line2' => $address['address_line2'] ?? '',
                    'city' => $address['city'] ?? '',
                    'state' => $address['state'] ?? '',
                ],
            ],
        ];
    }

    /**
     * @param array{subject: string, html: string, text: string} $rendered
     */
    private function writeToMailLog(string $to, array $rendered, string $template): void
    {
        $dir = dirname($this->logPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Mail log directory is not writable.');
        }

        $line = str_repeat('=', 80);
        $entry = "{$line}\n"
            . '[' . date('Y-m-d H:i:s') . "] LOGGED ONLY – not delivered (MAIL_MAILER is not smtp)\n"
            . "To: {$to}\nSubject: {$rendered['subject']}\nTemplate: {$template}\n"
            . str_repeat('-', 80) . "\n{$rendered['text']}\n"
            . str_repeat('-', 80) . "\n[HTML]\n{$rendered['html']}\n{$line}\n\n";

        if (@file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Mail log is not writable.');
        }
    }
}
