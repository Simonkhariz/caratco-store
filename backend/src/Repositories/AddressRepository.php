<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\PhoneNumber;

use App\Support\Input;
use App\Database\Database;

/**
 * Repository for customer delivery address book management.
 * Strictly scopes all address operations to the authenticated user ID.
 */
class AddressRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all addresses for a specific customer.
     */
    public function getByUserId(int $userId): array
    {
        $sql = "SELECT id, user_id, label, recipient_name, phone, street_address, city, state, country, postal_code, is_default, created_at, updated_at
                FROM `addresses`
                WHERE `user_id` = ?
                ORDER BY `is_default` DESC, `id` DESC";

        return $this->db->fetchAll($sql, 'i', [$userId]);
    }

    /**
     * Find single address ensuring ownership.
     */
    public function findByIdAndUser(int $id, int $userId): ?array
    {
        $sql = "SELECT id, user_id, label, recipient_name, phone, street_address, city, state, country, postal_code, is_default, created_at, updated_at
                FROM `addresses`
                WHERE `id` = ? AND `user_id` = ?
                LIMIT 1";

        return $this->db->fetchOne($sql, 'ii', [$id, $userId]);
    }

    /**
     * Create a new address for the customer.
     */
    public function create(int $userId, array $data): int
    {
        // In one transaction: a failed insert must not leave the customer with no default address.
        return $this->db->transaction(function (Database $db) use ($userId, $data): int {
            $isDefault = !empty($data['is_default']) ? 1 : 0;

            // If this is the user's first address, make it default automatically
            $existingCount = $db->fetchOne("SELECT COUNT(*) AS c FROM `addresses` WHERE `user_id` = ?", 'i', [$userId]);
            if ($existingCount !== null && (int) $existingCount['c'] === 0) {
                $isDefault = 1;
            }

            if ($isDefault === 1) {
                $db->execute("UPDATE `addresses` SET `is_default` = 0 WHERE `user_id` = ?", 'i', [$userId]);
            }

            $label = Input::text($data['label'] ?? null, 'Home');
            $recipientName = Input::text($data['recipient_name'] ?? null);
            $phone = PhoneNumber::stored(Input::text($data['phone'] ?? null));
            $streetAddress = Input::text($data['street_address'] ?? null);
            $city = Input::text($data['city'] ?? null);
            $state = Input::text($data['state'] ?? null);
            $country = Input::text($data['country'] ?? null, 'Nigeria');
            $postalCode = isset($data['postal_code']) ? Input::text($data['postal_code'] ?? null) : null;

            $sql = "INSERT INTO `addresses` (`user_id`, `label`, `recipient_name`, `phone`, `street_address`, `city`, `state`, `country`, `postal_code`, `is_default`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            return (int) $db->insert(
                $sql,
                'issssssssi',
                [$userId, $label, $recipientName, $phone, $streetAddress, $city, $state, $country, $postalCode, $isDefault]
            );
        });
    }

    /**
     * Update an existing address ensuring ownership.
     */
    public function update(int $id, int $userId, array $data): bool
    {
        $existing = $this->findByIdAndUser($id, $userId);
        if ($existing === null) {
            return false;
        }

        $fields = [];
        $types = '';
        $values = [];

        $map = [
            'label' => 's',
            'recipient_name' => 's',
            'phone' => 's',
            'street_address' => 's',
            'city' => 's',
            'state' => 's',
            'country' => 's',
            'postal_code' => 's',
            'is_default' => 'i',
        ];

        foreach ($map as $col => $t) {
            if (array_key_exists($col, $data)) {
                $fields[] = "`{$col}` = ?";
                $types .= $t;
                $values[] = match (true) {
                    $t === 'i' => !empty($data[$col]) ? 1 : 0,
                    $col === 'phone' => PhoneNumber::stored(Input::text($data[$col])),
                    default => Input::text($data[$col]),
                };
            }
        }

        if (empty($fields)) {
            return true;
        }

        $sql = "UPDATE `addresses` SET " . implode(', ', $fields) . " WHERE `id` = ? AND `user_id` = ?";
        $types .= 'ii';
        $values[] = $id;
        $values[] = $userId;

        // In one transaction: a failed update must not leave the customer with no default address.
        $this->db->transaction(function (Database $db) use ($data, $userId, $sql, $types, $values): void {
            if (!empty($data['is_default'])) {
                $db->execute("UPDATE `addresses` SET `is_default` = 0 WHERE `user_id` = ?", 'i', [$userId]);
            }
            $db->execute($sql, $types, $values);
        });
        return true;
    }

    /**
     * Delete an address ensuring ownership.
     */
    public function delete(int $id, int $userId): bool
    {
        $existing = $this->findByIdAndUser($id, $userId);
        if ($existing === null) {
            return false;
        }

        $this->db->execute("DELETE FROM `addresses` WHERE `id` = ? AND `user_id` = ?", 'ii', [$id, $userId]);

        // If the deleted address was default, set the latest remaining address as default
        if ((int) $existing['is_default'] === 1) {
            $latest = $this->db->fetchOne("SELECT id FROM `addresses` WHERE `user_id` = ? ORDER BY id DESC LIMIT 1", 'i', [$userId]);
            if ($latest !== null) {
                $this->db->execute("UPDATE `addresses` SET `is_default` = 1 WHERE `id` = ?", 'i', [(int) $latest['id']]);
            }
        }

        return true;
    }

    /**
     * Set a specific address as default.
     */
    public function setDefault(int $id, int $userId): bool
    {
        $existing = $this->findByIdAndUser($id, $userId);
        if ($existing === null) {
            return false;
        }

        $this->db->transaction(function (Database $db) use ($id, $userId): void {
            $db->execute("UPDATE `addresses` SET `is_default` = 0 WHERE `user_id` = ?", 'i', [$userId]);
            $db->execute("UPDATE `addresses` SET `is_default` = 1 WHERE `id` = ? AND `user_id` = ?", 'ii', [$id, $userId]);
        });
        return true;
    }
}
