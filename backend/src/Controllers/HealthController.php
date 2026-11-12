<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Support\Response;

/**
 * Public health check for uptime monitors and load balancers: whether the API and its database
 * answer, and nothing else. Versions, extensions and settings are in the admin's System status,
 * so the open internet learns nothing about the server it could use.
 */
class HealthController
{
    /**
     * Handle GET /api/v1/health
     */
    public function check(): void
    {
        $connected = Database::getInstance()->ping()['connected'];

        Response::success(
            ['status' => $connected ? 'healthy' : 'degraded', 'database' => $connected ? 'up' : 'down', 'timestamp' => time()],
            $connected ? 'System is fully operational.' : 'System is degraded: Database unreachable.',
            $connected ? 200 : 503
        );
    }
}
