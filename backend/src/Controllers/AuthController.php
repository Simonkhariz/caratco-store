<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\JsonBodyMiddleware;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Support\Response;
use App\Support\SessionCookie;
use App\Support\SessionGuard;

/**
 * Controller handling user registration, authentication, session lifecycle, and magic links.
 * Admin and customer sessions are separate cookies; endpoints that act on "the current
 * session" take `?scope=admin` to address the admin one (default: customer).
 */
class AuthController
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    /**
     * Customer registration.
     */
    public function register(): void
    {
        $result = $this->authService->registerCustomer(JsonBodyMiddleware::getBody());

        Response::success($result, 'Account created successfully.', 201);
    }

    /**
     * Email and password login.
     */
    public function login(): void
    {
        $body = JsonBodyMiddleware::getBody();
        $email = (string) ($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $password === '') {
            Response::error('Email and password are required.', 422, 'VALIDATION_ERROR', [
                'email' => $email === '' ? 'Email is required.' : null,
                'password' => $password === '' ? 'Password is required.' : null,
            ]);
        }

        $result = $this->authService->login($email, $password);

        Response::success($result, 'Signed in successfully.');
    }

    /**
     * Terminate the active session for the requested scope.
     */
    public function logout(): void
    {
        $this->authService->logout(SessionCookie::normalizeScope($_GET['scope'] ?? null));

        Response::success(null, 'Signed out successfully.');
    }

    /**
     * Retrieve the currently authenticated user and the session's CSRF token.
     */
    public function me(): void
    {
        $resolved = SessionGuard::resolve(SessionCookie::normalizeScope($_GET['scope'] ?? null));
        if ($resolved === null) {
            // Asking "is anyone signed in?" on every page: for a visitor, "no" is not an error.
            if (($_GET['optional'] ?? null) === '1') {
                Response::success(['user' => null, 'csrf_token' => null, 'expires_at' => null], 'Not signed in.');
            }
            Response::error('Unauthenticated.', 401, 'UNAUTHENTICATED');
        }

        Response::success([
            'user' => UserRepository::toSafeUser($resolved['user']),
            'csrf_token' => $resolved['session']['payload']['csrf_token'] ?? null,
            'expires_at' => $resolved['session']['expires_at'],
        ], 'Current user session retrieved.');
    }

    /**
     * Request a passwordless magic sign-in link (delivered by email only).
     */
    public function requestMagicLink(): void
    {
        $body = JsonBodyMiddleware::getBody();

        $result = $this->authService->requestMagicLink((string) ($body['email'] ?? ''));

        Response::success($result, 'If that email can receive sign-in links, one is on its way. It expires in 15 minutes.');
    }

    /**
     * Verify a magic sign-in token.
     */
    public function verifyMagicLink(): void
    {
        $body = JsonBodyMiddleware::getBody();
        $token = trim((string) ($body['token'] ?? ''));

        if ($token === '') {
            Response::error('Verification token is required.', 422, 'VALIDATION_ERROR', [
                'token' => 'Token cannot be empty.',
            ]);
        }

        $result = $this->authService->verifyMagicLink($token);

        Response::success($result, 'Signed in successfully.');
    }
}
