<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Support\RequestContext;
use Throwable;

/**
 * AuditService provides centralized, immutable audit logging for administrative actions.
 * Records actor (admin_id), action, entity_type, entity_id, before_state, after_state,
 * ip_address, and user_agent. Sanitizes secrets before storing.
 */
class AuditService
{
    private static array $sensitiveKeys = [
        'password',
        'password_hash',
        'secret',
        'api_key',
        'secret_key',
        'encryption_key',
        'token',
        'token_hash',
        'guest_access_token_hash',
        'csrf_token',
        'authorization',
    ];

    /**
     * Record an administrative mutation into admin_audit_logs.
     */
    public static function record(
        ?int $adminId,
        string $action,
        string $entityType,
        ?string $entityId,
        ?array $before = null,
        ?array $after = null
    ): void {
        try {
            $db = Database::getInstance();

            // Fallback to RequestContext admin if not provided
            if ($adminId === null) {
                $user = RequestContext::getUser();
                if ($user !== null) {
                    $adminId = (int) $user['id'];
                }
            }

            $beforeClean = $before !== null ? self::redact($before) : null;
            $afterClean = $after !== null ? self::redact($after) : null;

            $beforeJson = $beforeClean !== null ? json_encode($beforeClean, JSON_UNESCAPED_SLASHES) : null;
            $afterJson = $afterClean !== null ? json_encode($afterClean, JSON_UNESCAPED_SLASHES) : null;

            $ip = RequestContext::clientIp();
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'CLI', 0, 500);

            $db->execute(
                "INSERT INTO `admin_audit_logs` (`admin_id`, `action`, `entity_type`, `entity_id`, `before_state`, `after_state`, `ip_address`, `user_agent`, `created_at`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                'isssssss',
                [$adminId, $action, $entityType, $entityId, $beforeJson, $afterJson, $ip, $ua]
            );
        } catch (Throwable $e) {
            if ($e instanceof \mysqli_sql_exception && Database::getInstance()->inTransaction()) {
                throw $e;
            }
            error_log('[AuditService Record Error] ' . $e->getMessage());
        }
    }

    /**
     * Recursively redact sensitive keys from audit log payloads.
     */
    public static function redact(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;
            foreach (self::$sensitiveKeys as $secretKey) {
                if (str_contains($lowerKey, $secretKey)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::redact($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }
}
