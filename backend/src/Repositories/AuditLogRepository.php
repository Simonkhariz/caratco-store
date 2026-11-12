<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use App\Support\RequestContext;

class AuditLogRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Record an administrative audit log entry.
     *
     * @param int|null $adminId
     * @param string $action
     * @param string $entityType
     * @param string|null $entityId
     * @param array|null $beforeState
     * @param array|null $afterState
     * @return int Inserted log ID
     */
    public function record(
        ?int $adminId,
        string $action,
        string $entityType,
        ?string $entityId = null,
        ?array $beforeState = null,
        ?array $afterState = null
    ): int {
        if ($adminId === null) {
            $user = RequestContext::getUser();
            $adminId = $user ? (int) $user['id'] : null;
        }

        if ($adminId !== null) {
            $exists = $this->db->fetchOne("SELECT id FROM users WHERE id = ?", "i", [$adminId]);
            if (!$exists) {
                $adminId = null;
            }
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'ShopCo-Admin/1.0', 0, 500);

        $beforeJson = $beforeState !== null ? json_encode($beforeState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $afterJson = $afterState !== null ? json_encode($afterState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $sql = "INSERT INTO admin_audit_logs (admin_id, action, entity_type, entity_id, before_state, after_state, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        return (int) $this->db->insert(
            $sql,
            "isssssss",
            [
                $adminId,
                $action,
                $entityType,
                $entityId,
                $beforeJson,
                $afterJson,
                $ip,
                $ua,
            ]
        );
    }

    /**
     * Fetch audit log history for a specific entity (e.g. product or variant).
     */
    public function listForEntity(string $entityType, string $entityId, int $limit = 20): array
    {
        $sql = "SELECT a.id, a.admin_id, COALESCE(u.full_name, a.admin_label) AS admin_name, u.email AS admin_email,
                       a.action, a.entity_type, a.entity_id, a.before_state, a.after_state,
                       a.ip_address, a.created_at
                FROM admin_audit_logs a
                LEFT JOIN users u ON u.id = a.admin_id
                WHERE a.entity_type = ? AND a.entity_id = ?
                ORDER BY a.created_at DESC
                LIMIT ?";

        $rows = $this->db->fetchAll($sql, "ssi", [$entityType, $entityId, $limit]);

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['admin_id'] = $row['admin_id'] !== null ? (int) $row['admin_id'] : null;
            $row['before_state'] = $row['before_state'] ? json_decode($row['before_state'], true) : null;
            $row['after_state'] = $row['after_state'] ? json_decode($row['after_state'], true) : null;
        }
        unset($row);

        return $rows;
    }
}
