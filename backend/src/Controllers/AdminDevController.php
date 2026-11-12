<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Middleware\JsonBodyMiddleware;
use App\Services\AuditService;
use App\Services\LiveStatus;
use App\Storage\Storage;
use App\Support\Cache;
use App\Support\DevSwitches;
use App\Support\RateLimiter;
use App\Support\Redis;
use App\Support\RequestContext;
use App\Support\Response;
use App\Support\StorefrontRefresh;

/**
 * Dev mode (Store settings) and the tech switches page. Turning dev mode on needs the admin's
 * password again, so an unattended signed-in screen cannot open the technical pages. Every change
 * is in the audit log.
 */
final class AdminDevController
{
    private const PASSWORD_ATTEMPTS = 5;
    private const PASSWORD_WINDOW = 900;

    public function show(): void
    {
        $on = DevSwitches::devMode();
        Response::success(['on' => $on] + ($on ? $this->details() : []), 'Dev mode retrieved.');
    }

    public function setMode(): void
    {
        $body = JsonBodyMiddleware::getBody();
        $on = $body['on'] ?? null;
        if (!is_bool($on)) {
            Response::error('Say whether dev mode should be on or off.', 422, 'VALIDATION_ERROR', ['on' => 'Must be true or false.']);
        }
        $adminId = (int) (RequestContext::getUser()['id'] ?? 0);
        if ($on) {
            $this->checkPassword($adminId, (string) ($body['password'] ?? ''));
        }
        DevSwitches::setDevMode($on);
        AuditService::record($adminId, $on ? 'dev_mode.turned_on' : 'dev_mode.turned_off', 'settings', 'dev_mode');
        Response::success(['on' => $on] + ($on ? $this->details() : []), $on ? 'Dev mode is on.' : 'Dev mode is off.');
    }

    public function updateSwitches(): void
    {
        DevSwitches::requireDevMode();
        $body = JsonBodyMiddleware::getBody();
        $adminId = (int) (RequestContext::getUser()['id'] ?? 0);

        if (($body['reset'] ?? null) === true) {
            DevSwitches::resetSwitches();
            AuditService::record($adminId, 'dev_mode.switches_reset', 'settings', 'dev_switches');
            Response::success(['on' => true] + $this->details(), 'Switches are back to their defaults.');
        }

        $key = (string) ($body['key'] ?? '');
        $on = $body['on'] ?? null;
        if (!array_key_exists($key, DevSwitches::definitions())) {
            Response::error('That switch does not exist.', 422, 'VALIDATION_ERROR', ['key' => 'Unknown switch.']);
        }
        if (!is_bool($on)) {
            Response::error('Say whether the switch should be on or off.', 422, 'VALIDATION_ERROR', ['on' => 'Must be true or false.']);
        }
        $before = DevSwitches::enabled($key);
        DevSwitches::setSwitch($key, $on);
        AuditService::record($adminId, 'dev_mode.switch_changed', 'settings', $key, ['on' => $before], ['on' => $on]);
        Response::success(['on' => true] + $this->details(), 'Saved.');
    }

    private function checkPassword(int $adminId, #[\SensitiveParameter] string $password): void
    {
        // The same five-tries limit as every other "type your password again" check.
        if (!AdminOperationsController::passwordConfirmed($adminId, $password)) {
            AuditService::record($adminId, 'dev_mode.password_failed', 'settings', 'dev_mode');
            Response::error('That password is not right.', 422, 'WRONG_PASSWORD', ['password' => 'That password is not right.']);
        }
    }

    /** @return array{switches: list<array<string, mixed>>, fixed: list<array{name: string, state: string, why: string}>, warnings: list<string>} */
    private function details(): array
    {
        $switches = [];
        foreach (DevSwitches::definitions() as $key => $definition) {
            $note = $this->notSetUp($key);
            $switches[] = $definition + [
                'key' => $key,
                'on' => DevSwitches::enabled($key),
                'recommended' => DevSwitches::recommended($key),
                'available' => $note === null,
                'note' => $note,
            ];
        }
        $opcache = function_exists('opcache_get_status') && is_array(@opcache_get_status(false));
        return [
            'switches' => $switches,
            'fixed' => DevSwitches::fixed(Storage::uploads()->name(), $opcache),
            'warnings' => $this->warnings(),
        ];
    }

    /** Why a switch can have no effect here, or null when it works. */
    private function notSetUp(string $key): ?string
    {
        return match ($key) {
            DevSwitches::REDIS => Redis::isConfigured() ? null : 'Not set up: REDIS_URL is empty in backend/.env, so the file cache is used.',
            DevSwitches::INSTANT_REFRESH => StorefrontRefresh::isConfigured() ? null : 'Not set up: STOREFRONT_REVALIDATE_URL and REVALIDATE_SECRET are not both set in backend/.env.',
            DevSwitches::CATALOG_CACHE => Cache::configuredSeconds() > 0 ? null : 'Not set up: CATALOG_CACHE_SECONDS is 0 in backend/.env.',
            DevSwitches::LIVE_STATUS => PHP_SAPI === 'cli-server' ? 'Not available on this test server: php -S answers one request at a time, so one open payment page would stall the whole shop.' : null,
            default => null,
        };
    }

    /** @return list<string> */
    private function warnings(): array
    {
        $warnings = [];
        if (!DevSwitches::enabled(DevSwitches::MAIL_AFTER_RESPONSE) && !DevSwitches::enabled(DevSwitches::MAIL_AUTO_RETRY)) {
            $warnings[] = 'Both email switches are off: emails are only sent if the scheduled job (process_outbox.php) is running. Without it, customers get no email.';
        }
        if (LiveStatus::enabled()) {
            $warnings[] = 'Live payment status is on: at most ' . LiveStatus::maxStreams() . ' payment pages can use it at once on this server, each holding a PHP worker. Check it has been measured here (docs/SSE_CONTRACT.md).';
        }
        return $warnings;
    }
}
