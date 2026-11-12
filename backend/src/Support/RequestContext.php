<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Context container for the current request lifecycle.
 * Holds authenticated user, session, and security attributes.
 */
class RequestContext
{
    private static ?array $user = null;
    private static ?array $session = null;
    private static ?string $csrfToken = null;

    public static function setUser(?array $user): void
    {
        self::$user = $user;
    }

    public static function getUser(): ?array
    {
        return self::$user;
    }

    public static function setSession(?array $session): void
    {
        self::$session = $session;
    }

    public static function getSession(): ?array
    {
        return self::$session;
    }

    public static function setCsrfToken(?string $token): void
    {
        self::$csrfToken = $token;
    }

    public static function getCsrfToken(): ?string
    {
        return self::$csrfToken;
    }

    /**
     * Best-effort client IP (rate limits, sessions, audit). X-Forwarded-For is only honoured when
     * the direct peer is a trusted proxy (TRUSTED_PROXIES: addresses or ranges such as
     * 10.0.0.0/8, e.g. the Next.js server forwarding /api/v1), never from the open internet.
     *
     * The header is read from the right: each proxy appends the address it received the request
     * from, so the entries on the left are whatever the visitor chose to send. The client is the
     * last entry that is not one of our own proxies. (Taking the leftmost entry would let anyone
     * pick their own address and step around every rate limit.)
     */
    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        if (!self::isTrustedProxy($remote)) {
            return $remote;
        }

        $chain = array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $hop = $chain[$i];
            if ($hop === '' || !filter_var($hop, FILTER_VALIDATE_IP)) {
                break; // a malformed entry: nothing to its left can be trusted
            }
            if (!self::isTrustedProxy($hop)) {
                return $hop;
            }
        }

        return $remote;
    }

    /** Whether $ip is listed in TRUSTED_PROXIES (exact addresses or CIDR ranges, IPv4 or IPv6). */
    public static function isTrustedProxy(string $ip): bool
    {
        $address = @inet_pton($ip);
        if ($address === false) {
            return false;
        }
        foreach (explode(',', (string) \App\Config\Env::get('TRUSTED_PROXIES', '127.0.0.1,::1')) as $entry) {
            [$network, $bits] = array_pad(explode('/', trim($entry), 2), 2, null);
            $net = $network !== null && $network !== '' ? @inet_pton($network) : false;
            if ($net === false || strlen($net) !== strlen($address)) {
                continue;
            }
            $bits = $bits === null ? strlen($net) * 8 : (int) $bits;
            if ($bits < 0 || $bits > strlen($net) * 8) {
                continue;
            }
            $whole = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($address, 0, $whole) !== substr($net, 0, $whole)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($address[$whole]) & $mask) === (ord($net[$whole]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    public static function userAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return is_string($ua) && $ua !== '' ? mb_substr($ua, 0, 500) : null;
    }

    public static function reset(): void
    {
        self::$user = null;
        self::$session = null;
        self::$csrfToken = null;
    }
}
