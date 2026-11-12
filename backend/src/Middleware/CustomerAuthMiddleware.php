<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\SessionCookie;
use App\Support\SessionGuard;

/**
 * Customer Authentication Middleware.
 * Requires a valid customer-scoped HTTP-only session cookie, enforces CSRF on
 * mutations and isolates the customer through RequestContext.
 */
class CustomerAuthMiddleware
{
    public static function handle(): void
    {
        SessionGuard::require(SessionCookie::SCOPE_CUSTOMER);
    }
}
