<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\SessionCookie;
use App\Support\SessionGuard;

/**
 * Admin Authorization Middleware.
 * Requires a valid admin-scoped HTTP-only session cookie (role admin/super_admin),
 * enforces CSRF on mutations and exposes the admin through RequestContext.
 */
class AdminAuthMiddleware
{
    public static function handle(): void
    {
        SessionGuard::require(SessionCookie::SCOPE_ADMIN);
    }
}
