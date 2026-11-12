<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

/**
 * Repository for reading and persisting store-level settings and setup lock status.
 */
class StoreSettingsRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get a setting by key.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->db->fetchOne("SELECT `value` FROM `store_settings` WHERE `key_name` = ? LIMIT 1", 's', [$key]);
        return $row !== null ? $row['value'] : $default;
    }

    /**
     * Upsert a setting key-value pair.
     */
    public function set(string $key, ?string $value): void
    {
        $sql = "INSERT INTO `store_settings` (`key_name`, `value`)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

        $this->db->execute($sql, 'ss', [$key, $value]);
    }

    /**
     * Retrieve all settings as key-value associative array.
     */
    public function getAll(): array
    {
        $rows = $this->db->fetchAll("SELECT `key_name`, `value` FROM `store_settings`");
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key_name']] = $row['value'];
        }
        return $settings;
    }

    /**
     * Check if initial admin setup has already been completed and locked.
     */
    public function isSetupCompleted(): bool
    {
        $val = $this->get('is_setup_completed', '0');
        if ($val === '1') {
            return true;
        }

        // Also check if any admin exists in users table
        $userRepo = new UserRepository();
        return $userRepo->countAdmins() > 0;
    }

    /**
     * Mark initial setup as permanently completed.
     */
    public function markSetupCompleted(): void
    {
        $this->set('is_setup_completed', '1');
    }

    /**
     * Reset setup lock (local testing only).
     */
    public function resetSetupLock(): void
    {
        $this->set('is_setup_completed', '0');
    }
}
