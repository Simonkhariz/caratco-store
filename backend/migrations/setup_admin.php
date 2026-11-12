<?php

declare(strict_types=1);

/**
 * One-Time Admin Onboarding Setup Script
 * Creates the single initial administrator account and locks public setup.
 *
 * Usage:
 *   php backend/migrations/setup_admin.php --name="Admin Name" --email="admin@shop.co" --password="SecurePassword"
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/src/autoload.php';

use App\Config\Env;
use App\Database\Database;

Env::load(BASE_PATH . '/.env');

echo "========================================================\n";
echo " Shop.co — First-Run Admin Onboarding Setup\n";
echo "========================================================\n\n";

$db = Database::getInstance();

// 1. Verify that admin setup is not already completed
$adminCheck = $db->fetchOne("SELECT COUNT(*) AS c FROM `users` WHERE `role` IN ('admin', 'super_admin')");
if ($adminCheck !== null && ((int) $adminCheck['c']) > 0) {
    fwrite(STDERR, "[LOCKED] Setup has already been completed. An administrator account already exists.\n");
    fwrite(STDERR, "Public admin registration and initial setup are permanently disabled.\n");
    exit(1);
}

// 2. Parse CLI arguments
$options = getopt('', ['name:', 'email:', 'password:', 'origin_city::', 'origin_state::', 'origin_address::']);

$name = trim($options['name'] ?? '');
$email = trim(strtolower($options['email'] ?? ''));
$password = $options['password'] ?? '';
$originCity = trim($options['origin_city'] ?? 'Enugu');
$originState = trim($options['origin_state'] ?? 'Enugu');
$originAddress = trim($options['origin_address'] ?? '12 Okpara Avenue, Independence Layout, Enugu, Nigeria');

if ($name === '' || $email === '' || $password === '') {
    echo "Required options missing. Interactive prompt:\n";
    if ($name === '') {
        echo "Enter Admin Full Name: ";
        $name = trim(fgets(STDIN) ?: '');
    }
    if ($email === '') {
        echo "Enter Admin Email: ";
        $email = trim(strtolower(fgets(STDIN) ?: ''));
    }
    if ($password === '') {
        echo "Enter Admin Password (min 8 characters): ";
        $password = trim(fgets(STDIN) ?: '');
    }
}

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
    fwrite(STDERR, "[ERROR] Invalid input. Name, valid email, and password (min 8 chars) are required.\n");
    exit(1);
}

// 3. Hash password securely using standard Bcrypt
$passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$uuid = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

// 4. Atomic transaction to create admin and lock setup
$db->transaction(function (Database $db) use ($uuid, $email, $passwordHash, $name, $originCity, $originState, $originAddress) {
    // Insert administrator user and capture generated ID
    $adminId = (int) $db->insert(
        "INSERT INTO `users` (`uuid`, `email`, `password_hash`, `full_name`, `role`, `status`, `email_verified_at`)
         VALUES (?, ?, ?, ?, 'super_admin', 'active', NOW())",
        'ssss',
        [$uuid, $email, $passwordHash, $name]
    );

    // Save and lock store settings
    $settings = [
        'store_origin_city' => $originCity,
        'store_origin_state' => $originState,
        'store_origin_address' => $originAddress,
        'admin_onboarded_email' => $email,
        'is_setup_completed' => '1',
    ];

    foreach ($settings as $key => $val) {
        $db->execute(
            "INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            'ss',
            [$key, $val]
        );
    }

    // Log the onboarding action in admin audit logs using captured adminId
    $db->execute(
        "INSERT INTO `admin_audit_logs` (`admin_id`, `action`, `entity_type`, `entity_id`, `after_state`)
         VALUES (?, 'admin_onboarding_completed', 'system', 'setup', ?)",
        'is',
        [$adminId, json_encode(['email' => $email, 'origin_city' => $originCity, 'origin_state' => $originState])]
    );
});

echo "\n[SUCCESS] Administrator account successfully created!\n";
echo "  Name:           {$name}\n";
echo "  Email:          {$email}\n";
echo "  Role:           admin\n";
echo "  Store Origin:   {$originCity}, {$originState} ({$originAddress})\n";
echo "  Setup Status:   PERMANENTLY LOCKED (Public setup closed)\n";
exit(0);
