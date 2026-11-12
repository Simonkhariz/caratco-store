<?php

declare(strict_types=1);

namespace App\Support;

use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;

/**
 * Resolves the session behind a scoped session cookie.
 * Shared by the admin/customer middleware, /auth/me and endpoints with optional sign-in.
 */
final class SessionGuard
{
    /**
     * Resolve the current session for a scope without failing the request.
     *
     * @return array{token: string, session: array, user: array}|null
     */
    public static function resolve(string $scope): ?array
    {
        $token = SessionCookie::read($scope);
        if ($token === null) {
            return null;
        }

        $sessionRepo = new SessionRepository();
        $session = $sessionRepo->find($token);
        if ($session === null) {
            return null;
        }

        $idle = SessionCookie::idleTimeoutSeconds($scope);
        if ($idle !== null && (time() - (int) $session['last_activity']) > $idle) {
            $sessionRepo->destroy($token);
            return null;
        }

        $user = (new UserRepository())->findById((int) $session['user_id']);
        if ($user === null || $user['status'] === 'suspended') {
            return null;
        }

        // A customer cookie must never carry an admin identity and vice versa.
        if (SessionCookie::scopeForRole((string) $user['role']) !== $scope) {
            return null;
        }

        // For the data-retention rule (accounts unused for the chosen period are removed).
        // Written at most once an hour per user.
        (new UserRepository())->markSeen((int) $user['id']);

        return ['token' => $token, 'session' => $session, 'user' => $user];
    }

    /**
     * Require a valid session for a scope, enforce CSRF on mutations and populate RequestContext.
     *
     * @return array{token: string, session: array, user: array}
     */
    public static function require(string $scope): array
    {
        $resolved = self::resolve($scope);
        if ($resolved === null) {
            Response::error(
                $scope === SessionCookie::SCOPE_ADMIN
                    ? 'Administrator sign-in required.'
                    : 'Please sign in to continue.',
                401,
                'UNAUTHENTICATED'
            );
        }

        self::enforceCsrf($resolved['session']);

        RequestContext::setUser($resolved['user']);
        RequestContext::setSession($resolved['session']);
        RequestContext::setCsrfToken($resolved['session']['payload']['csrf_token'] ?? null);

        (new SessionRepository())->touch($resolved['token']);

        return $resolved;
    }

    /**
     * Cookie-authenticated state-changing requests must echo the session CSRF token.
     */
    public static function enforceCsrf(array $session): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $expected = (string) ($session['payload']['csrf_token'] ?? '');

        if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
            Response::error('Security token mismatch. Please refresh the page and try again.', 403, 'CSRF_MISMATCH');
        }
    }
}
