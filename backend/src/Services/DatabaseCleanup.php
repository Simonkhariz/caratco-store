<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Storage\StorageError;
use App\Support\UploadFiles;
use DomainException;

/**
 * Data the store no longer needs, per the keep periods the admin chose (admin → Data &
 * storage). Nothing is ever removed automatically: the daily check only alerts the admin,
 * who reviews the exact records here and confirms with their password and an emailed code
 * (AdminDataController). Money records (order totals and items, payments, refunds) are never
 * deleted: tax rules require them to be kept.
 *
 * Everything is computed "as of" the moment the admin reviewed the list. The clean-up then
 * removes exactly those records: if anything changed since (a customer came back, a setting
 * changed), it refuses and the admin reviews again.
 *
 * Keep periods (store_settings, days; 0 = keep):
 *   retention_customer_days  (off)  customers with no sign-in and no order for this long and
 *                                   nothing open: account, saved addresses, cart and wishlist
 *                                   deleted; name, phone, email, street address and note
 *                                   removed from their finished orders and email from reviews
 *   retention_unpaid_days    (30)   cancelled orders that were never paid; abandoned guest carts
 *   retention_message_days   (180)  sent/failed emails and raw payment gateway notifications
 *   retention_activity_days  (off)  admin activity log and stock history
 * Expired sign-in sessions and links, old rate-limit counters and slow-request records older
 * than 30 days are listed too (technical rows with no customer or business data).
 */
final class DatabaseCleanup
{
    /** The choices offered in admin for each setting (days; 0 = keep). */
    public const OPTIONS = [
        'retention_customer_days' => [0, 90, 180, 365, 730],
        'retention_unpaid_days' => [7, 30, 90],
        'retention_message_days' => [90, 180, 365],
        'retention_activity_days' => [0, 365, 730, 1825],
    ];

    public const DEFAULTS = [
        'retention_customer_days' => 0,
        'retention_unpaid_days' => 30,
        'retention_message_days' => 180,
        'retention_activity_days' => 0,
    ];

    /** What each rule removes, in the order shown and carried out. */
    public const LABELS = [
        'inactive_customers' => 'Inactive customers (personal details removed)',
        'inactive_customer_accounts' => 'Their customer accounts deleted',
        'inactive_customer_orders' => 'Their orders anonymised (totals kept)',
        'unpaid_cancelled_orders' => 'Cancelled orders that were never paid',
        'abandoned_guest_carts' => 'Abandoned guest carts',
        'old_emails' => 'Old sent and failed emails',
        'old_gateway_notifications' => 'Old payment gateway notifications',
        'unsubscribed_newsletter_emails' => 'Unsubscribed newsletter addresses',
        'old_admin_activity' => 'Old admin activity log entries',
        'old_stock_history' => 'Old stock history entries',
        'expired_sessions' => 'Expired sign-in sessions',
        'used_or_expired_sign_in_links' => 'Used or expired sign-in links',
        'old_rate_limit_counters' => 'Old rate-limit counters',
        'old_slow_request_records' => 'Slow-request records older than 30 days',
        'unused_images' => 'Picture files no product, banner or category uses (older than a day)',
    ];

    /** Technical rows and files with no customer or business data: they only raise an alert when many pile up. */
    public const HOUSEKEEPING = ['expired_sessions', 'used_or_expired_sign_in_links', 'old_rate_limit_counters', 'old_slow_request_records', 'unused_images'];

    /** An order in one of these states still needs the customer's details. */
    private const OPEN_ORDER_STATES = "'pending_payment', 'paid', 'processing', 'shipped', 'refund_pending'";
    private const LAST_RUN_KEY = 'cleanup_last_run';

    /**
     * Throwaway records whose list, for a fixed review time, can only get shorter: their dates
     * only ever move forward (a counter's window restarts, a session or guest cart is used
     * again, a link gets used) and new rows are never old enough to join. They are left out of
     * the fingerprint, so a visitor (or the clean-up's own code email) touching one between
     * review and confirmation no longer makes the clean-up refuse; the run removes the rows
     * still on the list, which are always among those reviewed.
     */
    private const SHRINK_ONLY = ['abandoned_guest_carts', 'expired_sessions', 'used_or_expired_sign_in_links', 'old_rate_limit_counters'];

    /** Shown when the storage holding the pictures can't be reached. */
    public const PICTURES_NOT_CHECKED = 'The picture storage could not be reached, so unused pictures were left out of this check. Nothing was removed; they will be checked again once it is back.';
    private const CHUNK = 500;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Saved keep periods; a missing or unknown value falls back to the default.
     *
     * @return array<string, int>
     */
    public function settings(): array
    {
        $rows = array_column($this->db->fetchAll(
            "SELECT `key_name`, `value` FROM `store_settings` WHERE `key_name` IN ('" . implode("', '", array_keys(self::DEFAULTS)) . "')"
        ), 'value', 'key_name');

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = isset($rows[$key]) && is_numeric($rows[$key]) ? (int) $rows[$key] : $default;
            $out[$key] = in_array($value, self::OPTIONS[$key], true) ? $value : $default;
        }
        return $out;
    }

    /**
     * When an admin last cleaned up and what was removed.
     *
     * @return array{at: string, by: string|null, removed: array<string, int>}|null
     */
    public function lastRun(): ?array
    {
        $row = $this->db->fetchOne("SELECT `value` FROM `store_settings` WHERE `key_name` = ?", 's', [self::LAST_RUN_KEY]);
        $data = $row ? json_decode((string) $row['value'], true) : null;
        return is_array($data) && isset($data['at']) ? $data : null;
    }

    /** Removed together or not at all: a customer is never half-removed. */
    private const CUSTOMER_GROUP = ['inactive_customers', 'inactive_customer_accounts', 'inactive_customer_orders'];

    /**
     * The rules a clean-up covers when the admin unticked $skip (unknown names are ignored).
     * Unticking any of the inactive-customer lines leaves out all three.
     *
     * @param list<mixed> $skip
     * @return list<string>
     */
    public static function selection(array $skip): array
    {
        $skip = array_values(array_filter($skip, 'is_string'));
        if (array_intersect($skip, self::CUSTOMER_GROUP) !== []) {
            $skip = array_merge($skip, self::CUSTOMER_GROUP);
        }
        return array_values(array_diff(array_keys(self::LABELS), $skip));
    }

    /**
     * Everything a clean-up as of $asOf would remove; with $only, just those rules (the lines the
     * admin left ticked), and the fingerprint then covers only them, so a change to an unticked
     * line never stops the clean-up.
     *
     * Pictures are left out when their storage can't be reached (pictures_checked is false). A
     * list reviewed that way never matches one with pictures, so a clean-up refuses until reviewed
     * again.
     *
     * @param list<string>|null $only
     * @return array{ids: array<string, list<string>>, counts: array<string, int>, fingerprint: string, pictures_checked: bool}
     */
    public function snapshot(string $asOf, ?array $only = null): array
    {
        $s = $this->settings();
        // Inactive customers unticked: their emails don't join the old-email list either.
        $withCustomers = $s['retention_customer_days'] > 0 && ($only === null || in_array('inactive_customers', $only, true));
        $customers = $withCustomers ? $this->inactiveCustomers($asOf, $s['retention_customer_days']) : [];
        $emails = array_keys($customers);

        $ids = [];
        foreach ($this->rules($asOf, $s) as $name => $rule) {
            $ids[$name] = isset($rule['ids']) ? $this->column($rule['ids'], $rule['types'], $rule['params']) : [];
        }
        $picturesChecked = true;
        if ($only === null || in_array('unused_images', $only, true)) {
            try {
                $ids['unused_images'] = UploadFiles::unused($this->db, $asOf);
            } catch (StorageError $e) {
                error_log('[Database check] ' . $e->getMessage());
                $picturesChecked = false;
            }
        }
        if ($withCustomers) {
            $ids['inactive_customers'] = $emails;
            $ids['inactive_customer_accounts'] = $this->columnForEmails(
                "SELECT u.`id` AS k FROM `users` u WHERE u.`role` = 'customer' AND u.`email` IN ({in}) ORDER BY u.`id`",
                $emails
            );
            // Orders removed as never-paid are not anonymised as well. With that line unticked they
            // stay, so they are anonymised here: a removed customer's details are left on no order.
            $removedAsUnpaid = $only === null || in_array('unpaid_cancelled_orders', $only, true) ? $ids['unpaid_cancelled_orders'] : [];
            $ids['inactive_customer_orders'] = array_values(array_diff($this->columnForEmails(
                "SELECT o.`id` AS k FROM `orders` o LEFT JOIN `users` u ON u.`id` = o.`user_id`
                 WHERE o.`personal_data_removed_at` IS NULL AND (o.`guest_email` IN ({in}) OR u.`email` IN ({in})) ORDER BY o.`created_at`",
                $emails
            ), $removedAsUnpaid));
            // Their copies of sent emails go too, whatever their age.
            $ids['old_emails'] = array_values(array_unique(array_merge($ids['old_emails'], $this->columnForEmails(
                "SELECT e.`id` AS k FROM `email_outbox` e WHERE e.`status` IN ('sent', 'failed') AND e.`recipient_email` IN ({in})",
                $emails
            ))));
        }

        $ordered = [];
        foreach (array_keys(self::LABELS) as $name) {
            if (array_key_exists($name, $ids) && ($only === null || in_array($name, $only, true))) {
                $ordered[$name] = array_map('strval', $ids[$name]);
            }
        }

        $sorted = array_map(static function (array $list): array {
            sort($list, SORT_STRING);
            return $list;
        }, array_diff_key($ordered, array_flip(self::SHRINK_ONLY)));

        return [
            'ids' => $ordered,
            'counts' => array_map('count', $ordered),
            // The whole list keeps its original fingerprint; a ticked selection also covers which lines.
            'fingerprint' => hash('sha256', json_encode($only === null ? [$s, $sorted] : [$s, $sorted, $only])),
            'pictures_checked' => $picturesChecked,
        ];
    }

    /**
     * One page of the records a rule would remove, for the admin to look through.
     *
     * @return array{columns: list<array{key: string, label: string, type: string}>, items: list<array<string, mixed>>, total: int}
     */
    public function records(string $rule, string $asOf, int $page, int $perPage): array
    {
        $snapshot = $this->snapshot($asOf);
        if ($rule === 'unused_images' && !$snapshot['pictures_checked']) {
            throw new DomainException(self::PICTURES_NOT_CHECKED);
        }
        if (!isset($snapshot['ids'][$rule])) {
            throw new DomainException('This kind of data is not part of the clean-up with your current settings.');
        }
        $all = $snapshot['ids'][$rule];
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

        if ($rule === 'inactive_customers') {
            $s = $this->settings();
            $activity = $this->inactiveCustomers($asOf, $s['retention_customer_days']);
            $items = array_map(fn (string $email): array => $this->customerSummary($email, $activity[$email] ?? null), $slice);
            $columns = [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                ['key' => 'email', 'label' => 'Email', 'type' => 'text'],
                ['key' => 'last_activity', 'label' => 'Last activity', 'type' => 'date'],
                ['key' => 'orders', 'label' => 'Orders', 'type' => 'number'],
                ['key' => 'account', 'label' => 'Account', 'type' => 'text'],
            ];
            return ['columns' => $columns, 'items' => $items, 'total' => count($all)];
        }

        if ($rule === 'unused_images') {
            $columns = [
                ['key' => 'file', 'label' => 'File', 'type' => 'text'],
                ['key' => 'size_kb', 'label' => 'Size (KB)', 'type' => 'number'],
                ['key' => 'modified', 'label' => 'Last changed', 'type' => 'date'],
            ];
            try {
                return ['columns' => $columns, 'items' => UploadFiles::describe($slice), 'total' => count($all)];
            } catch (StorageError $e) {
                error_log('[Database check] ' . $e->getMessage());
                throw new DomainException(self::PICTURES_NOT_CHECKED);
            }
        }

        $display = $this->display()[$rule];
        $items = [];
        if ($slice !== []) {
            $in = implode(', ', array_fill(0, count($slice), '?'));
            $rows = $this->db->fetchAll(
                "{$display['select']} WHERE {$display['key']} IN ({$in})",
                str_repeat('s', count($slice)),
                $slice
            );
            // Keep the order of the list.
            $byKey = [];
            foreach ($rows as $row) {
                $byKey[(string) $row['k']] = $row;
            }
            foreach ($slice as $key) {
                if (isset($byKey[$key])) {
                    $row = $byKey[$key];
                    unset($row['k']);
                    $items[] = $row;
                }
            }
        }

        return ['columns' => $display['columns'], 'items' => $items, 'total' => count($all)];
    }

    /**
     * Remove exactly what the admin reviewed as of $asOf (only the rules in $only when given).
     * Throws DomainException when the records have changed since (fingerprint differs), so
     * nothing unexpected is removed.
     *
     * @param list<string>|null $only
     * @return array<string, int> rows removed per rule
     */
    public function execute(string $asOf, string $fingerprint, ?string $adminName = null, ?array $only = null): array
    {
        $snapshot = $this->snapshot($asOf, $only);
        if (!hash_equals($snapshot['fingerprint'], $fingerprint)) {
            throw new DomainException('Some of these records changed after you reviewed them. Review the list again before cleaning up.');
        }
        $ids = $snapshot['ids'];
        $removed = [];

        // Never-paid orders go first, so an inactive customer's order is never both deleted and anonymised.
        foreach (['unpaid_cancelled_orders', 'inactive_customer_orders', 'inactive_customers', 'old_emails', 'inactive_customer_accounts'] as $name) {
            if (isset($ids[$name])) {
                $removed[$name] = $this->apply($name, $ids[$name]);
            }
        }
        foreach ($ids as $name => $list) {
            if (!isset($removed[$name])) {
                $removed[$name] = $this->apply($name, $list);
            }
        }

        $this->db->execute(
            "INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            'ss',
            [self::LAST_RUN_KEY, json_encode(['at' => date('Y-m-d H:i:s'), 'by' => $adminName, 'removed' => $removed])]
        );

        $ordered = [];
        foreach (array_keys(self::LABELS) as $name) {
            if (isset($removed[$name])) {
                $ordered[$name] = $removed[$name];
            }
        }
        return $ordered;
    }

    // -------------------------------------------------------------------------

    /**
     * @param list<string> $ids
     */
    private function apply(string $rule, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        if ($rule === 'inactive_customers') {
            // The reviews stay on the shop; only the email behind them goes.
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $in = implode(', ', array_fill(0, count($chunk), '?'));
                $this->db->executeUpdate("UPDATE `reviews` SET `author_email` = NULL WHERE `author_email` IN ({$in})", str_repeat('s', count($chunk)), $chunk);
                // On the newsletter list only because of the account that is being removed (an
                // unsubscribe is kept until its own retention rule, as for everyone else).
                $this->db->execute(
                    "DELETE FROM `newsletter_subscribers` WHERE `source` = 'registered_customer' AND `status` = 'active' AND `email` IN ({$in})",
                    str_repeat('s', count($chunk)),
                    $chunk
                );
            }
            return count($ids);
        }
        if ($rule === 'unused_images') {
            try {
                return UploadFiles::delete($ids);
            } catch (StorageError $e) {
                // The database part is done by now: the next review lists what is left.
                error_log('[Database clean-up] ' . $e->getMessage());
                return 0;
            }
        }

        $targets = [
            'inactive_customer_accounts' => ["DELETE FROM `users` WHERE `role` = 'customer' AND `id` IN ({in})"],
            // updated_at keeps its value: reports date orders by it.
            'inactive_customer_orders' => [
                "UPDATE `orders` o
                 SET o.`guest_email` = '',
                     o.`guest_access_token_hash` = SHA2(CONCAT(o.`id`, '|', RAND(), '|', NOW(6)), 256),
                     o.`shipping_address_snapshot` = JSON_OBJECT(
                         'city', JSON_UNQUOTE(JSON_EXTRACT(o.`shipping_address_snapshot`, '$.city')),
                         'state', JSON_UNQUOTE(JSON_EXTRACT(o.`shipping_address_snapshot`, '$.state')),
                         'country', JSON_UNQUOTE(JSON_EXTRACT(o.`shipping_address_snapshot`, '$.country'))),
                     o.`notes` = NULL,
                     o.`idempotency_key` = NULL,
                     o.`user_id` = NULL,
                     o.`personal_data_removed_at` = NOW(),
                     o.`updated_at` = o.`updated_at`
                 WHERE o.`personal_data_removed_at` IS NULL AND o.`id` IN ({in})",
            ],
            'unpaid_cancelled_orders' => ["DELETE FROM `orders` WHERE `order_status` = 'cancelled' AND `id` IN ({in})"],
            'abandoned_guest_carts' => ["DELETE FROM `carts` WHERE `user_id` IS NULL AND `id` IN ({in})"],
            'old_emails' => ["DELETE FROM `email_outbox` WHERE `status` IN ('sent', 'failed') AND `id` IN ({in})"],
            'old_gateway_notifications' => ["DELETE FROM `payment_webhook_events` WHERE `id` IN ({in})"],
            // Kept while the email has an account: registered customers get the newsletter unless
            // this row says they unsubscribed, so removing it would sign them back up.
            'unsubscribed_newsletter_emails' => ["DELETE FROM `newsletter_subscribers` WHERE `status` = 'unsubscribed' AND `id` IN ({in})
                                                  AND NOT EXISTS (SELECT 1 FROM `users` u WHERE u.`email` = `newsletter_subscribers`.`email`)"],
            'old_admin_activity' => ["DELETE FROM `admin_audit_logs` WHERE `id` IN ({in})"],
            'old_stock_history' => ["DELETE FROM `inventory_logs` WHERE `id` IN ({in})"],
            'expired_sessions' => ["DELETE FROM `sessions` WHERE `id` IN ({in})"],
            'used_or_expired_sign_in_links' => ["DELETE FROM `email_verification_tokens` WHERE `id` IN ({in})"],
            'old_rate_limit_counters' => ["DELETE FROM `rate_limits` WHERE `id` IN ({in})"],
            'old_slow_request_records' => ["DELETE FROM `api_slow_requests` WHERE `id` IN ({in})"],
        ];

        $sql = $targets[$rule][0];
        $count = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            $count += $this->db->transaction(
                fn (Database $db): int => $db->executeUpdate(str_replace('{in}', $in, $sql), str_repeat('s', count($chunk)), $chunk)
            );
        }
        return $count;
    }

    /**
     * Rules that select their records by date (customer rules are added in snapshot()).
     *
     * @return array<string, array{ids?: string, types: string, params: array}>
     */
    private function rules(string $asOf, array $s): array
    {
        $before = static fn (int $days): array => [$asOf, $days];
        $rules = [];
        if ($s['retention_customer_days'] > 0) {
            $rules['inactive_customers'] = ['types' => '', 'params' => []];
            $rules['inactive_customer_accounts'] = ['types' => '', 'params' => []];
            $rules['inactive_customer_orders'] = ['types' => '', 'params' => []];
        }
        $rules += [
            // Never paid, nothing refunded, nothing waiting for the admin (that includes a
            // customer's claim to have paid, flagged until the admin resolves it); stock was
            // already released when the order was cancelled. Items, history and closed payment
            // attempts go with the order (foreign keys cascade). An attempt that received part
            // of the money is a money record, so its order stays.
            'unpaid_cancelled_orders' => [
                'ids' => "SELECT o.`id` AS k FROM `orders` o
                          WHERE o.`order_status` = 'cancelled'
                            AND o.`requires_attention` = 0
                            AND o.`updated_at` < DATE_SUB(?, INTERVAL ? DAY)
                            AND NOT EXISTS (SELECT 1 FROM `payments` p WHERE p.`order_id` = o.`id`
                                            AND (p.`status` IN ('successful', 'partially_refunded', 'refunded') OR p.`amount_received` > 0))
                            AND NOT EXISTS (SELECT 1 FROM `refunds` r WHERE r.`order_id` = o.`id`)
                          ORDER BY o.`created_at`",
                'types' => 'si',
                'params' => $before($s['retention_unpaid_days']),
            ],
            'abandoned_guest_carts' => [
                'ids' => "SELECT c.`id` AS k FROM `carts` c WHERE c.`user_id` IS NULL AND c.`updated_at` < DATE_SUB(?, INTERVAL ? DAY) ORDER BY c.`updated_at`",
                'types' => 'si',
                'params' => $before($s['retention_unpaid_days']),
            ],
            // Emails carry names and addresses, so they never outlive the customer-record period.
            'old_emails' => [
                'ids' => "SELECT e.`id` AS k FROM `email_outbox` e WHERE e.`status` IN ('sent', 'failed') AND e.`created_at` < DATE_SUB(?, INTERVAL ? DAY) ORDER BY e.`created_at`",
                'types' => 'si',
                'params' => $before($s['retention_customer_days'] > 0 ? min($s['retention_message_days'], $s['retention_customer_days']) : $s['retention_message_days']),
            ],
            'old_gateway_notifications' => [
                'ids' => "SELECT w.`id` AS k FROM `payment_webhook_events` w WHERE w.`received_at` < DATE_SUB(?, INTERVAL ? DAY) ORDER BY w.`received_at`",
                'types' => 'si',
                'params' => $before($s['retention_message_days']),
            ],
        ];
        if ($s['retention_customer_days'] > 0) {
            $rules['unsubscribed_newsletter_emails'] = [
                'ids' => "SELECT n.`id` AS k FROM `newsletter_subscribers` n
                          WHERE n.`status` = 'unsubscribed' AND COALESCE(n.`unsubscribed_at`, n.`created_at`) < DATE_SUB(?, INTERVAL ? DAY)
                            AND NOT EXISTS (SELECT 1 FROM `users` u WHERE u.`email` = n.`email`)
                          ORDER BY n.`id`",
                'types' => 'si',
                'params' => $before($s['retention_customer_days']),
            ];
        }
        if ($s['retention_activity_days'] > 0) {
            $rules['old_admin_activity'] = [
                'ids' => "SELECT a.`id` AS k FROM `admin_audit_logs` a WHERE a.`created_at` < DATE_SUB(?, INTERVAL ? DAY) ORDER BY a.`created_at`",
                'types' => 'si',
                'params' => $before($s['retention_activity_days']),
            ];
            $rules['old_stock_history'] = [
                'ids' => "SELECT l.`id` AS k FROM `inventory_logs` l WHERE l.`created_at` < DATE_SUB(?, INTERVAL ? DAY) ORDER BY l.`created_at`",
                'types' => 'si',
                'params' => $before($s['retention_activity_days']),
            ];
        }
        $rules += [
            'expired_sessions' => [
                'ids' => "SELECT s.`id` AS k FROM `sessions` s WHERE s.`expires_at` < ? ORDER BY s.`expires_at`",
                'types' => 's',
                'params' => [$asOf],
            ],
            'used_or_expired_sign_in_links' => [
                'ids' => "SELECT t.`id` AS k FROM `email_verification_tokens` t
                          WHERE t.`expires_at` < DATE_SUB(?, INTERVAL 1 DAY) OR (t.`used_at` IS NOT NULL AND t.`used_at` < DATE_SUB(?, INTERVAL 1 DAY))
                          ORDER BY t.`created_at`",
                'types' => 'ss',
                'params' => [$asOf, $asOf],
            ],
            // The longest rate-limit window is one hour; keep a day for safety.
            'old_rate_limit_counters' => [
                'ids' => "SELECT r.`id` AS k FROM `rate_limits` r WHERE r.`window_start` < UNIX_TIMESTAMP(?) - 86400 ORDER BY r.`window_start`",
                'types' => 's',
                'params' => [$asOf],
            ],
            'old_slow_request_records' => [
                'ids' => "SELECT q.`id` AS k FROM `api_slow_requests` q WHERE q.`hour_start` < DATE_SUB(?, INTERVAL 30 DAY) ORDER BY q.`hour_start`",
                'types' => 's',
                'params' => [$asOf],
            ],
        ];
        return $rules;
    }

    /**
     * How each rule's records are shown to the admin (rows are fetched by `key`, as `k`).
     *
     * @return array<string, array{select: string, key: string, columns: list<array{key: string, label: string, type: string}>}>
     */
    private function display(): array
    {
        $col = static fn (string $key, string $label, string $type = 'text'): array => compact('key', 'label', 'type');
        $name = "JSON_UNQUOTE(JSON_EXTRACT(o.`shipping_address_snapshot`, '$.full_name'))";

        return [
            'inactive_customer_accounts' => [
                'select' => "SELECT u.`id` AS k, u.`full_name`, u.`email`, u.`created_at`, u.`last_seen_at`,
                                    (SELECT COUNT(*) FROM `addresses` a WHERE a.`user_id` = u.`id`) AS addresses,
                                    (SELECT COUNT(*) FROM `wishlists` w JOIN `wishlist_items` wi ON wi.`wishlist_id` = w.`id` WHERE w.`user_id` = u.`id`) AS wishlist_items,
                                    (SELECT COUNT(*) FROM `carts` c JOIN `cart_items` ci ON ci.`cart_id` = c.`id` WHERE c.`user_id` = u.`id`) AS cart_items
                             FROM `users` u",
                'key' => 'u.`id`',
                'columns' => [$col('full_name', 'Name'), $col('email', 'Email'), $col('created_at', 'Signed up', 'date'), $col('last_seen_at', 'Last seen', 'date'),
                              $col('addresses', 'Saved addresses', 'number'), $col('cart_items', 'Cart items', 'number'), $col('wishlist_items', 'Wishlist items', 'number')],
            ],
            'inactive_customer_orders' => [
                'select' => "SELECT o.`id` AS k, o.`order_number`, {$name} AS customer, COALESCE(u.`email`, o.`guest_email`) AS email,
                                    o.`created_at`, o.`order_status`, o.`grand_total`
                             FROM `orders` o LEFT JOIN `users` u ON u.`id` = o.`user_id`",
                'key' => 'o.`id`',
                'columns' => [$col('order_number', 'Order', 'code'), $col('customer', 'Customer'), $col('email', 'Email'), $col('created_at', 'Placed', 'date'),
                              $col('order_status', 'Status'), $col('grand_total', 'Total (kept)', 'money')],
            ],
            'unpaid_cancelled_orders' => [
                'select' => "SELECT o.`id` AS k, o.`order_number`, {$name} AS customer, o.`guest_email` AS email, o.`created_at`, o.`grand_total` FROM `orders` o",
                'key' => 'o.`id`',
                'columns' => [$col('order_number', 'Order', 'code'), $col('customer', 'Customer'), $col('email', 'Email'), $col('created_at', 'Placed', 'date'),
                              $col('grand_total', 'Total (never paid)', 'money')],
            ],
            'abandoned_guest_carts' => [
                'select' => "SELECT c.`id` AS k, CONCAT('Cart #', c.`id`) AS cart, (SELECT COUNT(*) FROM `cart_items` ci WHERE ci.`cart_id` = c.`id`) AS items, c.`updated_at` FROM `carts` c",
                'key' => 'c.`id`',
                'columns' => [$col('cart', 'Cart'), $col('items', 'Items', 'number'), $col('updated_at', 'Last used', 'date')],
            ],
            'old_emails' => [
                'select' => "SELECT e.`id` AS k, e.`recipient_email`, e.`subject`, e.`status`, e.`created_at` FROM `email_outbox` e",
                'key' => 'e.`id`',
                'columns' => [$col('recipient_email', 'To'), $col('subject', 'Subject'), $col('status', 'Status'), $col('created_at', 'Created', 'date')],
            ],
            'old_gateway_notifications' => [
                'select' => "SELECT w.`id` AS k, w.`provider`, w.`event_type`, w.`event_reference`, w.`processing_result`, w.`received_at` FROM `payment_webhook_events` w",
                'key' => 'w.`id`',
                'columns' => [$col('provider', 'Gateway'), $col('event_type', 'Event'), $col('event_reference', 'Reference', 'code'), $col('processing_result', 'Result'),
                              $col('received_at', 'Received', 'date')],
            ],
            'unsubscribed_newsletter_emails' => [
                'select' => "SELECT n.`id` AS k, n.`email`, n.`subscribed_at`, n.`unsubscribed_at` FROM `newsletter_subscribers` n",
                'key' => 'n.`id`',
                'columns' => [$col('email', 'Email'), $col('subscribed_at', 'Subscribed', 'date'), $col('unsubscribed_at', 'Unsubscribed', 'date')],
            ],
            'old_admin_activity' => [
                'select' => "SELECT a.`id` AS k, a.`created_at`, COALESCE(u.`full_name`, a.`admin_label`, 'System') AS admin, a.`action`,
                                    TRIM(CONCAT(a.`entity_type`, ' ', COALESCE(a.`entity_id`, ''))) AS entity
                             FROM `admin_audit_logs` a LEFT JOIN `users` u ON u.`id` = a.`admin_id`",
                'key' => 'a.`id`',
                'columns' => [$col('created_at', 'When', 'date'), $col('admin', 'Admin'), $col('action', 'Action', 'code'), $col('entity', 'Item')],
            ],
            'old_stock_history' => [
                'select' => "SELECT l.`id` AS k, l.`created_at`, p.`name` AS product, l.`change_amount`, l.`reason`
                             FROM `inventory_logs` l LEFT JOIN `products` p ON p.`id` = l.`product_id`",
                'key' => 'l.`id`',
                'columns' => [$col('created_at', 'When', 'date'), $col('product', 'Product'), $col('change_amount', 'Change', 'number'), $col('reason', 'Reason')],
            ],
            'expired_sessions' => [
                'select' => "SELECT s.`id` AS k, COALESCE(u.`email`, 'Visitor (not signed in)') AS account, s.`ip_address`, s.`expires_at`
                             FROM `sessions` s LEFT JOIN `users` u ON u.`id` = s.`user_id`",
                'key' => 's.`id`',
                'columns' => [$col('account', 'Account'), $col('ip_address', 'IP address', 'code'), $col('expires_at', 'Expired', 'date')],
            ],
            'used_or_expired_sign_in_links' => [
                'select' => "SELECT t.`id` AS k, t.`email`, t.`type`, t.`created_at`, t.`used_at` FROM `email_verification_tokens` t",
                'key' => 't.`id`',
                'columns' => [$col('email', 'Email'), $col('type', 'Kind'), $col('created_at', 'Sent', 'date'), $col('used_at', 'Used', 'date')],
            ],
            'old_rate_limit_counters' => [
                'select' => "SELECT r.`id` AS k, LEFT(r.`id`, 12) AS counter, r.`hits`, FROM_UNIXTIME(r.`window_start`) AS started FROM `rate_limits` r",
                'key' => 'r.`id`',
                'columns' => [$col('counter', 'Counter', 'code'), $col('hits', 'Requests counted', 'number'), $col('started', 'Started', 'date')],
            ],
            'old_slow_request_records' => [
                'select' => "SELECT q.`id` AS k, q.`hour_start`, q.`method`, q.`route`, q.`hits`, q.`max_ms` FROM `api_slow_requests` q",
                'key' => 'q.`id`',
                'columns' => [$col('hour_start', 'Hour', 'date'), $col('method', 'Method', 'code'), $col('route', 'Request', 'code'), $col('hits', 'Times', 'number'),
                              $col('max_ms', 'Slowest (ms)', 'number')],
            ],
        ];
    }

    /**
     * Customers whose sign-up, last sign-in and order activity are all older than $days
     * before $asOf and who have nothing open (unpaid, paid but not delivered, flagged, or a
     * refund pending). Emails compare without regard to case (utf8mb4_unicode_ci).
     *
     * @return array<string, string> email => last activity, sorted by email
     */
    private function inactiveCustomers(string $asOf, int $days): array
    {
        $open = "(o.`order_status` IN (" . self::OPEN_ORDER_STATES . ") OR o.`requires_attention` = 1
                  OR EXISTS (SELECT 1 FROM `refunds` r WHERE r.`order_id` = o.`id` AND r.`status` = 'pending'))";

        $rows = $this->db->fetchAll(
            "SELECT MIN(t.`email`) AS email, MAX(t.`last_at`) AS last_at FROM (
                 SELECT u.`email`, GREATEST(u.`created_at`, COALESCE(u.`last_seen_at`, u.`created_at`)) AS `last_at`, 0 AS `open`
                   FROM `users` u WHERE u.`role` = 'customer'
                 UNION ALL
                 SELECT o.`guest_email`, GREATEST(o.`created_at`, o.`updated_at`), {$open}
                   FROM `orders` o WHERE o.`personal_data_removed_at` IS NULL AND o.`guest_email` <> ''
                 UNION ALL
                 SELECT u.`email`, GREATEST(o.`created_at`, o.`updated_at`), {$open}
                   FROM `orders` o JOIN `users` u ON u.`id` = o.`user_id` WHERE o.`personal_data_removed_at` IS NULL
             ) t
             GROUP BY t.`email`
             HAVING MAX(t.`last_at`) < DATE_SUB(?, INTERVAL ? DAY) AND SUM(t.`open`) = 0
             ORDER BY email",
            'si',
            [$asOf, $days]
        );
        return array_column($rows, 'last_at', 'email');
    }

    /** @return array<string, mixed> */
    private function customerSummary(string $email, ?string $lastActivity): array
    {
        $account = $this->db->fetchOne("SELECT `full_name` FROM `users` WHERE `role` = 'customer' AND `email` = ?", 's', [$email]);
        $orders = $this->db->fetchOne(
            "SELECT COUNT(*) AS c, MAX(JSON_UNQUOTE(JSON_EXTRACT(o.`shipping_address_snapshot`, '$.full_name'))) AS name
             FROM `orders` o LEFT JOIN `users` u ON u.`id` = o.`user_id`
             WHERE o.`personal_data_removed_at` IS NULL AND (o.`guest_email` = ? OR u.`email` = ?)",
            'ss',
            [$email, $email]
        );
        return [
            'name' => $account['full_name'] ?? ($orders['name'] ?? null),
            'email' => $email,
            'last_activity' => $lastActivity,
            'orders' => (int) ($orders['c'] ?? 0),
            'account' => $account ? 'Yes (deleted)' : 'No (guest)',
        ];
    }

    /** @return list<string> */
    private function column(string $sql, string $types, array $params): array
    {
        return array_map('strval', array_column($this->db->fetchAll($sql, $types, $params), 'k'));
    }

    /**
     * Run a query whose {in} placeholders take the email list, in chunks.
     *
     * @param list<string> $emails
     * @return list<string>
     */
    private function columnForEmails(string $sql, array $emails): array
    {
        $out = [];
        $uses = substr_count($sql, '{in}');
        foreach (array_chunk($emails, self::CHUNK) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            $params = array_merge(...array_fill(0, $uses, $chunk));
            array_push($out, ...$this->column(str_replace('{in}', $in, $sql), str_repeat('s', count($params)), $params));
        }
        return array_values(array_unique($out));
    }
}
