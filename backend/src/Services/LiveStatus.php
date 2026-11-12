<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Support\DevSwitches;
use App\Database\Database;

/**
 * Live payment status for the payment return page (roadmap Prompt 33, docs/SSE_CONTRACT.md).
 *
 * The stream only watches: it reads the order and its payments with plain autocommit queries,
 * so it can only ever see committed data, and it never confirms a payment or changes an order
 * (every confirmation still goes through PaymentFinalizer). Off unless switched on in admin (Tech switches) or SSE_ENABLED=true.
 *
 * Each open stream holds one PHP worker and one database connection, so streams are counted in
 * `rate_limits` rows (id "sse:<order>:<visitor>:<random>", window_start = opening time) and
 * capped per order, per visitor and per server. A live stream renews its row at every heartbeat
 * (at most SLOT_RENEW_SECONDS apart); a row nobody renews stops counting after SLOT_TTL_SECONDS, so the slots of a
 * killed process or a restarted server free themselves within half a minute (measured in
 * Prompt 35: with the old fixed 120 s expiry, live status stayed off for 117 s after a restart).
 */
final class LiveStatus
{
    public const PER_ORDER = 2;
    public const PER_VISITOR = 4;
    public const SLOT_RENEW_SECONDS = 10;
    public const SLOT_TTL_SECONDS = 30;
    /** Suffix of the slot lock; the database name is put in front (named locks are server-wide). */
    private const LOCK = ':sse_slots';

    public static function enabled(): bool
    {
        return DevSwitches::enabled(DevSwitches::LIVE_STATUS);
    }

    /** Streams allowed on this server at once (each holds a PHP worker and a DB connection). */
    public static function maxStreams(): int
    {
        return max(1, Env::int('SSE_MAX_STREAMS', 8));
    }

    /** Stream length: under the storefront proxy's 120 s limit. */
    public static function streamSeconds(): int
    {
        return min(115, max(3, Env::int('SSE_STREAM_SECONDS', 110)));
    }

    /** How often a live stream renews its slot: every heartbeat, and never less than every 10 s. */
    public static function renewSeconds(): int
    {
        return min(self::SLOT_RENEW_SECONDS, self::heartbeatSeconds());
    }

    public static function heartbeatSeconds(): int
    {
        // 5 s: PHP only learns that a tab closed when a write fails, which is the second write
        // after the close, so a closed tab holds its worker for up to two heartbeats.
        return min(30, max(1, Env::int('SSE_HEARTBEAT_SECONDS', 5)));
    }

    public function __construct(private ?Database $db = null)
    {
        $this->db ??= Database::getInstance();
    }

    /**
     * The order's payment state in verify-payment's words: the successful attempt if there is
     * one, else the newest. `id` changes whenever the state does.
     *
     * @return array{id: string, order_number: string, status: string, order_status: string, final: bool}
     */
    public function snapshot(int $orderId): array
    {
        $order = $this->db->fetchOne("SELECT `order_number`, `order_status` FROM `orders` WHERE `id` = ?", 'i', [$orderId]);
        $payment = $this->db->fetchOne(
            "SELECT `id`, `status` FROM `payments` WHERE `order_id` = ?
             ORDER BY FIELD(`status`, 'refunded', 'partially_refunded', 'successful') DESC, `id` DESC LIMIT 1",
            'i',
            [$orderId]
        );
        $status = $payment === null ? 'not_started' : match ((string) $payment['status']) {
            'successful', 'partially_refunded', 'refunded' => 'successful',
            'failed', 'cancelled' => 'failed',
            default => 'pending',
        };
        $orderStatus = (string) ($order['order_status'] ?? '');

        return [
            'id' => ($payment === null ? '0' : (string) $payment['id']) . '-' . $status . '-' . $orderStatus,
            'order_number' => (string) ($order['order_number'] ?? ''),
            'status' => $status,
            'order_status' => $orderStatus,
            // Nothing more will change for this page: paid, or the order closed.
            'final' => $status === 'successful' || $orderStatus === 'cancelled',
        ];
    }

    /**
     * Take a stream slot for this order and visitor. Returns the slot id, or null when the order,
     * the visitor or the server already has as many streams as allowed.
     */
    public function claimSlot(int $orderId, string $visitor): ?string
    {
        $visitorKey = substr(hash('sha256', $visitor), 0, 16);
        $now = time();
        $since = $now - self::SLOT_TTL_SECONDS;

        $got = $this->db->fetchOne("SELECT GET_LOCK(CONCAT(LEFT(DATABASE(), 40), ?), 2) AS got", 's', [self::LOCK]);
        if ((int) ($got['got'] ?? 0) !== 1) {
            return null;
        }
        try {
            $live = fn (string $pattern): int => (int) $this->db->fetchOne(
                "SELECT COUNT(*) AS c FROM `rate_limits` WHERE `id` LIKE ? AND `window_start` > ?",
                'si',
                [$pattern, $since]
            )['c'];
            $server = $live('sse:%');
            $order = $live('sse:' . $orderId . ':%');
            $mine = $live('sse:%:' . $visitorKey . ':%');
            if ($server >= self::maxStreams() || $order >= self::PER_ORDER || $mine >= self::PER_VISITOR) {
                return null;
            }

            $slot = 'sse:' . $orderId . ':' . $visitorKey . ':' . bin2hex(random_bytes(8));
            $this->db->execute("INSERT INTO `rate_limits` (`id`, `hits`, `window_start`) VALUES (?, 1, ?)", 'si', [$slot, $now]);
            // Old slots of finished or killed streams: removed here, as their count has lapsed.
            $this->db->execute("DELETE FROM `rate_limits` WHERE `id` LIKE 'sse:%' AND `window_start` <= ?", 'i', [$since]);
            return $slot;
        } finally {
            $this->db->fetchOne("SELECT RELEASE_LOCK(CONCAT(LEFT(DATABASE(), 40), ?)) AS released", 's', [self::LOCK]);
        }
    }

    /**
     * Keep a live stream's slot counted. Puts the row back if a late renewal let it lapse and
     * another opening removed it, so a stream that is still running is never left uncounted.
     */
    public function renewSlot(string $slot): void
    {
        $this->db->execute(
            "INSERT INTO `rate_limits` (`id`, `hits`, `window_start`) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE `window_start` = VALUES(`window_start`)",
            'si',
            [$slot, time()]
        );
    }

    public function releaseSlot(string $slot): void
    {
        $this->db->execute("DELETE FROM `rate_limits` WHERE `id` = ?", 's', [$slot]);
    }
}
