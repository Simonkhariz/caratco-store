<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Env;
use App\Database\Database;
use Throwable;

/**
 * Tells the Next.js storefront to rebuild its cached pages after an admin change, so edits
 * show on the shop straight away instead of within its 60-second refresh.
 *
 * Set STOREFRONT_REVALIDATE_URL (the storefront's /api/revalidate address, reachable from this
 * server) and the same REVALIDATE_SECRET (at least 32 characters) in both apps. Without them
 * nothing is sent and the storefront still refreshes on its own timer. Every attempt's outcome
 * is kept in store_settings for System status; a failed attempt never affects the admin change.
 */
final class StorefrontRefresh
{
    public const STATUS_KEY = 'storefront_refresh_last';
    private const MIN_SECRET_LENGTH = 32;

    public static function isConfigured(): bool
    {
        return self::url() !== null && strlen(self::secret()) >= self::MIN_SECRET_LENGTH;
    }

    /** Shutdown hook for admin writes: only successful changes trigger a refresh. */
    public static function afterAdminChange(): void
    {
        $status = http_response_code();
        if (!is_int($status) || $status >= 400 || !self::isConfigured() || !DevSwitches::enabled(DevSwitches::INSTANT_REFRESH)) {
            return;
        }
        // Hand the admin their response first so they never wait for the storefront.
        Response::finishForClient();
        self::record(self::send());
    }

    /**
     * Ask the storefront to refresh. Bounded timeouts: an unreachable storefront costs at most
     * about a second (three if it accepts the connection but hangs) and is reported as a failure.
     *
     * @return array{ok: bool, error: ?string, ms: int}
     */
    public static function send(): array
    {
        $started = microtime(true);
        $result = static fn (bool $ok, ?string $error): array => [
            'ok' => $ok,
            'error' => $error,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
        if (!self::isConfigured()) {
            return $result(false, 'Not configured');
        }

        $ch = curl_init((string) self::url());
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Revalidate-Secret: ' . self::secret()],
            CURLOPT_RETURNTRANSFER => true,
            // The storefront is normally on the same server or network: connecting takes a few
            // milliseconds and the refresh itself is quick, so these limits only bite when it's down.
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => 3000,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return $result(false, 'Storefront unreachable: ' . mb_substr($curlError, 0, 200));
        }
        $json = json_decode((string) $body, true);
        if ($code !== 200 || !is_array($json) || ($json['ok'] ?? false) !== true) {
            return $result(false, "Storefront answered HTTP {$code}" . ($code === 401 ? ' (REVALIDATE_SECRET differs)' : ($code === 404 ? ' (refresh not enabled there)' : '')));
        }
        return $result(true, null);
    }

    /** @param array{ok: bool, error: ?string, ms: int} $outcome */
    public static function record(array $outcome): void
    {
        if (!$outcome['ok']) {
            error_log('[StorefrontRefresh] ' . $outcome['error']);
        }
        try {
            Database::getInstance()->execute(
                "INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                'ss',
                [self::STATUS_KEY, json_encode(['at' => date('Y-m-d H:i:s')] + $outcome)]
            );
        } catch (Throwable $e) {
            error_log('[StorefrontRefresh] could not record the outcome: ' . $e->getMessage());
        }
    }

    /**
     * For System status: whether it is set up and how the last attempt went.
     *
     * @return array{configured: bool, last: array{at: string, ok: bool, error: ?string, ms: int}|null}
     */
    public static function status(Database $db): array
    {
        $row = $db->fetchOne("SELECT `value` FROM `store_settings` WHERE `key_name` = ?", 's', [self::STATUS_KEY]);
        $last = $row ? json_decode((string) $row['value'], true) : null;
        return [
            'configured' => self::isConfigured(),
            'last' => is_array($last) && isset($last['at'], $last['ok']) ? $last : null,
        ];
    }

    private static function url(): ?string
    {
        $url = trim((string) Env::get('STOREFRONT_REVALIDATE_URL', ''));
        return preg_match('#^https?://#i', $url) ? $url : null;
    }

    private static function secret(): string
    {
        return trim((string) Env::get('REVALIDATE_SECRET', ''));
    }
}
