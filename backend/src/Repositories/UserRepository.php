<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Input;
use App\Database\Database;

/**
 * Repository for managing users table records.
 */
class UserRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Find user by email address (case-insensitive).
     */
    public function findByEmail(string $email): ?array
    {
        $sql = "SELECT id, uuid, email, password_hash, full_name, phone, role, status, email_verified_at, created_at, updated_at
                FROM `users`
                WHERE `email` = ?
                LIMIT 1";
        // Plain `=` so the unique email index is used: the column's collation (utf8mb4_unicode_ci)
        // already ignores case, which is what LOWER() on both sides did, without the table scan.
        return $this->db->fetchOne($sql, 's', [strtolower(trim($email))]);
    }

    /**
     * Find user by ID.
     */
    public function findById(int $id): ?array
    {
        $sql = "SELECT id, uuid, email, password_hash, full_name, phone, role, status, email_verified_at, created_at, updated_at
                FROM `users`
                WHERE `id` = ?
                LIMIT 1";
        return $this->db->fetchOne($sql, 'i', [$id]);
    }

    /**
     * Find user by UUID.
     */
    public function findByUuid(string $uuid): ?array
    {
        $sql = "SELECT id, uuid, email, password_hash, full_name, phone, role, status, email_verified_at, created_at, updated_at
                FROM `users`
                WHERE `uuid` = ?
                LIMIT 1";
        return $this->db->fetchOne($sql, 's', [$uuid]);
    }

    /**
     * Count users with admin or super_admin role.
     */
    public function countAdmins(): int
    {
        $row = $this->db->fetchOne("SELECT COUNT(*) AS c FROM `users` WHERE `role` IN ('admin', 'super_admin')");
        return $row !== null ? (int) $row['c'] : 0;
    }

    /**
     * Create a new user record.
     * Returns the inserted user ID.
     */
    public function create(array $data): int
    {
        $uuid = $data['uuid'] ?? sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $email = trim(strtolower($data['email']));
        $passwordHash = $data['password_hash'] ?? null;
        $fullName = Input::text($data['full_name'] ?? null);
        $phone = isset($data['phone']) ? Input::text($data['phone'] ?? null) : null;
        $role = $data['role'] ?? 'customer';
        $status = $data['status'] ?? 'active';
        $emailVerifiedAt = $data['email_verified_at'] ?? null;

        $sql = "INSERT INTO `users` (`uuid`, `email`, `password_hash`, `full_name`, `phone`, `role`, `status`, `email_verified_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        return (int) $this->db->insert(
            $sql,
            'ssssssss',
            [$uuid, $email, $passwordHash, $fullName, $phone, $role, $status, $emailVerifiedAt]
        );
    }

    /**
     * Update user details.
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $types = '';
        $values = [];

        if (array_key_exists('full_name', $data)) {
            $fields[] = "`full_name` = ?";
            $types .= 's';
            $values[] = trim((string) $data['full_name']);
        }

        if (array_key_exists('phone', $data)) {
            $fields[] = "`phone` = ?";
            $types .= 's';
            $values[] = $data['phone'] !== null ? trim((string) $data['phone']) : null;
        }

        if (array_key_exists('password_hash', $data)) {
            $fields[] = "`password_hash` = ?";
            $types .= 's';
            $values[] = $data['password_hash'];
        }

        if (array_key_exists('status', $data)) {
            $fields[] = "`status` = ?";
            $types .= 's';
            $values[] = $data['status'];
        }

        if (array_key_exists('email_verified_at', $data)) {
            $fields[] = "`email_verified_at` = ?";
            $types .= 's';
            $values[] = $data['email_verified_at'];
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE `users` SET " . implode(', ', $fields) . " WHERE `id` = ?";
        $types .= 'i';
        $values[] = $id;

        $this->db->execute($sql, $types, $values);
        return true;
    }

    /**
     * Mark a user's email as verified.
     */
    public function markEmailVerified(int $id): bool
    {
        // Only an 'unverified' profile is activated; a suspended account stays suspended.
        $this->db->execute(
            "UPDATE `users`
             SET `email_verified_at` = COALESCE(`email_verified_at`, NOW()),
                 `status` = IF(`status` = 'unverified', 'active', `status`)
             WHERE `id` = ?",
            'i',
            [$id]
        );
        return true;
    }

    /**
     * Record that the user is active (at most once an hour; updated_at keeps its value).
     */
    public function markSeen(int $id): void
    {
        $this->db->execute(
            "UPDATE `users` SET `last_seen_at` = NOW(), `updated_at` = `updated_at`
             WHERE `id` = ? AND (`last_seen_at` IS NULL OR `last_seen_at` < DATE_SUB(NOW(), INTERVAL 1 HOUR))",
            'i',
            [$id]
        );
    }

    /**
     * Strip internal sensitive properties like password_hash.
     */
    public static function toSafeUser(?array $user): ?array
    {
        if ($user === null) {
            return null;
        }

        unset($user['password_hash']);
        return $user;
    }
}
