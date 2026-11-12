<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Env;
use App\Database\Database;
use Throwable;

/**
 * Dev mode and the on/off switches for the shop's scalability technology (admin → Tech switches).
 *
 * The saved choices live in store_settings (so backups and restores keep them). Because several
 * switches are read on every request, including catalogue reads that never touch the database,
 * they are read from a small file that is refreshed from the database once a minute. A second
 * server therefore follows a change within a minute. If the database cannot be reached, the last
 * known choices (or the defaults) are used: a switch can never break a request.
 *
 * Only technology that can be switched safely at any moment is here. Picture storage, payment
 * safety, stock protection, rate limits and the email outbox are deliberately not: see fixed().
 *
 * Dev mode is off by default (DEV_MODE_DEFAULT=true is for the test runner). Its pages are refused
 * by the API, not just hidden, using the database's current answer (requireDevMode).
 */
final class DevSwitches
{
    public const CATALOG_CACHE = 'catalog_cache';
    public const REDIS = 'redis';
    public const LIVE_STATUS = 'live_status';
    public const INSTANT_REFRESH = 'instant_refresh';
    public const MAIL_AFTER_RESPONSE = 'mail_after_response';
    public const MAIL_AUTO_RETRY = 'mail_auto_retry';
    public const GZIP = 'gzip';
    public const SLOW_REQUEST_LOG = 'slow_request_log';

    private const DEV_MODE_KEY = 'dev_mode';
    private const SWITCHES_KEY = 'dev_switches';
    private const REFRESH_SECONDS = 60;

    /** @var array{dev_mode: ?bool, switches: array<string, bool>}|null */
    private static ?array $state = null;

    /** Whether a piece of technology is on: the admin's choice, else its default. */
    public static function enabled(string $key): bool
    {
        $stored = self::state()['switches'][$key] ?? null;
        return is_bool($stored) ? $stored : self::serverDefault($key);
    }

    /**
     * The default when the admin has not chosen: on, except live payment status, which stays off
     * until it has been measured on the server (SSE_ENABLED, docs/SSE_CONTRACT.md §13). The email
     * switches follow their existing settings in backend/.env.
     */
    public static function serverDefault(string $key): bool
    {
        return match ($key) {
            self::LIVE_STATUS => Env::bool('SSE_ENABLED', false),
            self::MAIL_AFTER_RESPONSE => filter_var(Env::get('MAIL_SEND_IMMEDIATELY', 'true'), FILTER_VALIDATE_BOOLEAN),
            self::MAIL_AUTO_RETRY => filter_var(Env::get('MAIL_AUTO_RETRY', 'true'), FILTER_VALIDATE_BOOLEAN),
            default => true,
        };
    }

    /** What this code recommends, whatever backend/.env says. */
    public static function recommended(string $key): bool
    {
        return $key !== self::LIVE_STATUS;
    }

    /**
     * The switches, in the order shown to the admin.
     *
     * @return array<string, array{label: string, what: string, when_off: string}>
     */
    public static function definitions(): array
    {
        return [
            self::CATALOG_CACHE => [
                'label' => 'Catalogue cache',
                'what' => 'Keeps the homepage, filters, categories and listing pages ready for 60 seconds, so the database is not asked again for every visitor.',
                'when_off' => 'Every visit reads the database. Pages slow down and the database works much harder when the shop is busy.',
            ],
            self::REDIS => [
                'label' => 'Redis shared cache',
                'what' => 'Shares listing pages and the "catalogue changed" signal between servers. Worth having with more than one server.',
                'when_off' => 'The catalogue cache keeps working from files, which is fine on one server.',
            ],
            self::LIVE_STATUS => [
                'label' => 'Live payment status',
                'what' => 'The payment page learns "paid" within seconds through an open connection. Each open payment page holds a PHP worker and a database connection for up to 110 seconds (limit: SSE_MAX_STREAMS). Off is recommended until it has been measured on this server (docs/SSE_CONTRACT.md).',
                'when_off' => 'The payment page checks every 15 seconds instead. Nothing else changes.',
            ],
            self::INSTANT_REFRESH => [
                'label' => 'Instant page refresh',
                'what' => 'The storefront rebuilds its pages as soon as an admin saves a change.',
                'when_off' => 'Changes appear on the shop within about a minute.',
            ],
            self::MAIL_AFTER_RESPONSE => [
                'label' => 'Email right after each request',
                'what' => 'An order email goes out straight after the customer has their answer, so a slow mail service never delays checkout.',
                'when_off' => 'Emails wait for the scheduled job or the automatic retries, usually up to a minute.',
            ],
            self::MAIL_AUTO_RETRY => [
                'label' => 'Automatic email retries',
                'what' => 'Ordinary requests also send emails that are due (a few a minute), so email keeps moving even without the scheduled job.',
                'when_off' => 'Only the scheduled job (process_outbox.php) sends emails that are due.',
            ],
            self::GZIP => [
                'label' => 'Compressed API answers',
                'what' => 'Shrinks API answers 5 to 10 times, which matters most on mobile data.',
                'when_off' => 'Shoppers download more data and pages load more slowly on mobile.',
            ],
            self::SLOW_REQUEST_LOG => [
                'label' => 'Slow request log',
                'what' => 'Counts slow pages and checkout calls for the database health alert.',
                'when_off' => 'Nothing is counted, so the alert cannot warn about a slow shop.',
            ],
        ];
    }

    /** Technology with no switch, and why. @return list<array{name: string, state: string, why: string}> */
    public static function fixed(string $pictureStorage, bool $opcache): array
    {
        return [
            ['name' => 'Picture storage', 'state' => $pictureStorage === 's3' ? 'Cloud bucket' : 'Server disk', 'why' => 'Moving pictures needs a copy first (docs/STORAGE.md). A switch here would break every picture on the shop.'],
            ['name' => 'OPcache', 'state' => $opcache ? 'On' : 'Off', 'why' => 'Set in the server\'s PHP settings, not by the shop.'],
            ['name' => 'Email outbox', 'state' => 'Always on', 'why' => 'Every email is saved before sending. Without it, a mail outage could lose order emails.'],
            ['name' => 'Payment and stock protection', 'state' => 'Always on', 'why' => 'Turning these off could charge a customer twice or sell stock you do not have.'],
            ['name' => 'Rate limits and sign-in protection', 'state' => 'Always on', 'why' => 'These protect accounts and checkout from abuse.'],
            ['name' => 'Next.js page cache and picture resizing', 'state' => 'Always on', 'why' => 'Built into the storefront; changing them needs a new build.'],
        ];
    }

    public static function devMode(): bool
    {
        $stored = self::state()['dev_mode'];
        return $stored ?? Env::bool('DEV_MODE_DEFAULT', false);
    }

    /** For dev mode pages: refuses the request when dev mode is off (asks the database itself). */
    public static function requireDevMode(): void
    {
        self::$state = null;
        $fresh = self::readDatabase();
        if ($fresh !== null) {
            self::$state = $fresh;
        }
        if (!self::devMode()) {
            Response::error('Turn on dev mode in Store settings to use this.', 403, 'DEV_MODE_OFF');
        }
    }

    public static function setDevMode(bool $on): void
    {
        self::save(self::DEV_MODE_KEY, $on ? '1' : '0');
    }

    public static function setSwitch(string $key, bool $on): void
    {
        $fresh = self::readDatabase();
        $switches = $fresh['switches'] ?? [];
        $switches[$key] = $on;
        self::save(self::SWITCHES_KEY, (string) json_encode($switches));
    }

    /** Back to the recommended and default values. */
    public static function resetSwitches(): void
    {
        self::save(self::SWITCHES_KEY, '{}');
    }

    /** Tests only: forget what this process has read. */
    public static function resetForTests(): void
    {
        self::$state = null;
    }

    /** Tests only: where the shared file is. */
    public static function fileForTests(): string
    {
        return self::file();
    }

    private static function save(string $settingKey, string $value): void
    {
        Database::getInstance()->execute(
            'INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            'ss',
            [$settingKey, $value]
        );
        $fresh = self::readDatabase();
        self::$state = $fresh;
        if ($fresh !== null) {
            self::writeFile($fresh);
        }
    }

    /** @return array{dev_mode: ?bool, switches: array<string, bool>} */
    private static function state(): array
    {
        if (self::$state !== null) {
            return self::$state;
        }
        $file = self::file();
        $age = @filemtime($file);
        $saved = $age !== false ? self::decodeFile($file) : null;
        if ($saved !== null && time() - $age < self::REFRESH_SECONDS) {
            return self::$state = $saved;
        }
        $fresh = self::readDatabase();
        if ($fresh !== null) {
            self::writeFile($fresh);
            return self::$state = $fresh;
        }
        // The database is out of reach: keep the last known choices, and do not ask it again on
        // every request until the next refresh is due.
        if ($age !== false) {
            @touch($file);
        }
        return self::$state = $saved ?? ['dev_mode' => null, 'switches' => []];
    }

    /** @return array{dev_mode: ?bool, switches: array<string, bool>}|null null when unreachable */
    private static function readDatabase(): ?array
    {
        try {
            $rows = Database::getInstance()->fetchAll(
                'SELECT `key_name`, `value` FROM `store_settings` WHERE `key_name` IN (?, ?)',
                'ss',
                [self::DEV_MODE_KEY, self::SWITCHES_KEY]
            );
        } catch (Throwable) {
            return null;
        }
        $state = ['dev_mode' => null, 'switches' => []];
        foreach ($rows as $row) {
            if ($row['key_name'] === self::DEV_MODE_KEY) {
                $state['dev_mode'] = $row['value'] === '1';
            } else {
                $state['switches'] = self::cleanSwitches(json_decode((string) $row['value'], true));
            }
        }
        return $state;
    }

    /** @return array<string, bool> */
    private static function cleanSwitches(mixed $values): array
    {
        return is_array($values) ? array_filter($values, 'is_bool') : [];
    }

    /** @return array{dev_mode: ?bool, switches: array<string, bool>}|null */
    private static function decodeFile(string $file): ?array
    {
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || !array_key_exists('dev_mode', $data)) {
            return null;
        }
        return ['dev_mode' => is_bool($data['dev_mode']) ? $data['dev_mode'] : null, 'switches' => self::cleanSwitches($data['switches'] ?? null)];
    }

    /** @param array{dev_mode: ?bool, switches: array<string, bool>} $state */
    private static function writeFile(array $state): void
    {
        $file = self::file();
        @mkdir(dirname($file), 0755, true);
        $temp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temp, (string) json_encode($state)) === false) {
            return;
        }
        if (!@rename($temp, $file)) {
            @unlink($temp);
        }
    }

    /** One file per database, so test runs never share one with the real shop. */
    private static function file(): string
    {
        $database = preg_replace('/\W/', '_', (string) Env::get('DB_DATABASE', 'shop'));
        return BASE_PATH . "/storage/cache/dev-switches-{$database}.json";
    }
}
